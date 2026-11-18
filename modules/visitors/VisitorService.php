<?php

declare(strict_types=1);

namespace Blue\Modules\Visitors;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Org\Translations;
use Blue\Core\Support\Qr\QrEncoder;
use Blue\Core\Support\Qr\QrRender;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Privacy\PrivacyCenterService;

/**
 * M43 visitor management (R6-2 · 0.43.0 · DEC-110).
 *
 *  invite     an employee (host) registers a visitor ahead: name, company, purpose, time window (≤ 7 days), people,
 *             vehicle / items when the company records them → ref VSyymm-nnnn + a random QR link (/visit/<token>) that
 *             works until the end of the window. The visitor's page shows the visit, a QR for the gate and the company's
 *             published privacy notice for visitors (privacy centre, audience "visitor").
 *  gate       visitors.gate sees today's visits; the QR (or ref / name search) only OPENS a visit — a guard still checks
 *             the person and presses check-in (the QR is not a pass). Check-in needs the notice to have been given;
 *             an active blacklist match blocks it unless visitors.manage overrides with a note. Walk-ins are recorded at
 *             the gate with a host chosen from the company's employees. Host is told on arrival. Check-out records
 *             badge return. Deny / cancel need a reason.
 *  job        invitations past their window → expired · still inside past the window + overdue_minutes (company setting,
 *             none = no notice) → host and gate staff told once · finished visits older than retention_days (company
 *             setting, none = kept) → visitor identity, vehicle, items and purpose removed; counts and times stay.
 * The audit never holds the visitor's name. No ID-card numbers or photos are collected.
 */
final class VisitorService
{
    public const MAX_DAYS = 7;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d');
    }

    private function event(int $visitId, string $action, ?int $actor, ?string $note = null): void
    {
        $this->app->db()->run(
            'INSERT INTO vs_events (visit_id, action, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)',
            [$visitId, $action, $actor, $note === null ? null : mb_substr($note, 0, 500), $this->stamp()]
        );
    }

    public static function nameKey(string $name): string
    {
        $n = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
        return (string) preg_replace('/^(นาย|นางสาว|นาง|น\.ส\.|mr\.?|mrs\.?|ms\.?|miss)\s*/u', '', $n);
    }

    public function companyName(int $le): string
    {
        $names = (new Translations($this->app->db()))->many('legal_entity', [$le]);
        $n = isset($names[$le]) ? Translations::pick($names[$le], 'th') : '';
        return $n !== '' && $n !== '—' ? $n : (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
    }

    /** Company of an active employee (null = not employed today). */
    public function employerOf(int $userId): ?int
    {
        $me = EmployeeService::of($this->app)->forUser($userId);
        return $me === null || $me['status'] === 'former' || $me['job'] === null ? null : (int) $me['job']['legal_entity_id'];
    }

    /** @return array{0: int, 1: string, 2: string} local datetime-local → [utc string, local ymd, display] */
    private function local(string $v, string $field): array
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $v, $this->tz());
        if ($d === false || $d->format('Y-m-d\TH:i') !== $v) {
            throw new ValidationException([$field => 'visitors.error.when']);
        }
        return [$d->getTimestamp(), $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), $d->format('Y-m-d')];
    }

    // ---- settings ---------------------------------------------------------------------------------------------------------

    /** @return array{retention_days: int|null, overdue_minutes: int|null, record_vehicle: bool} */
    public function settings(int $le): array
    {
        $s = $this->app->db()->one('SELECT * FROM vs_settings WHERE legal_entity_id = ?', [$le]);
        return ['retention_days' => $s === null || $s['retention_days'] === null ? null : (int) $s['retention_days'],
            'overdue_minutes' => $s === null || $s['overdue_minutes'] === null ? null : (int) $s['overdue_minutes'],
            'record_vehicle' => $s !== null && (int) $s['record_vehicle'] === 1];
    }

    /** @param array<string, mixed> $in */
    public function saveSettings(Authorizer $az, int $le, array $in): void
    {
        $this->guard($az, $le, 'visitors.manage');
        $int = static function (mixed $v, int $min, int $max, string $field): ?int {
            $v = trim((string) $v);
            if ($v === '') {
                return null;
            }
            if (preg_match('/^\d{1,5}$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
                throw new ValidationException([$field => 'visitors.error.range']);
            }
            return (int) $v;
        };
        $ret = $int($in['retention_days'] ?? '', 1, 3650, 'retention_days');
        $over = $int($in['overdue_minutes'] ?? '', 5, 1440, 'overdue_minutes');
        $this->app->db()->run(
            'INSERT INTO vs_settings (legal_entity_id, retention_days, overdue_minutes, record_vehicle, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE retention_days = VALUES(retention_days), overdue_minutes = VALUES(overdue_minutes), record_vehicle = VALUES(record_vehicle), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, $ret, $over, !empty($in['record_vehicle']) ? 1 : 0, $this->stamp(), $az->userId]
        );
        $this->app->audit()->record('visitors.settings_changed', 'success', 'legal_entity', $le, $le, null, ['retention_days' => $ret, 'overdue_minutes' => $over]);
    }

    // ---- scope ------------------------------------------------------------------------------------------------------------

    /** @return list<array{id: int, code: string, name: string}> */
    public function companies(Authorizer $az, string $perm): array
    {
        $s = $az->scope($perm);
        $out = [];
        foreach ($this->app->db()->all('SELECT id, code FROM legal_entities ORDER BY code') as $r) {
            if ($s->coversLegalEntity((int) $r['id'])) {
                $out[] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => $this->companyName((int) $r['id'])];
            }
        }
        return $out;
    }

    private function guard(Authorizer $az, int $le, string $perm): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
    }

    /** @return list<array{id: int, name: string}> */
    public function sites(int $le): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['code']],
            $this->app->db()->all("SELECT id, code FROM sites WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le])
        );
    }

    // ---- visits ------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in
     * @return string public id
     */
    public function create(Authorizer $az, array $in, bool $walkIn = false, ?int $gateLe = null): string
    {
        $db = $this->app->db();
        if ($walkIn) {
            $le = (int) $gateLe;
            $this->guard($az, $le, 'visitors.gate');
            $host = $this->hostLookup($le, trim((string) ($in['host'] ?? '')));
            if ($host === null) {
                throw new ValidationException(['host' => 'visitors.error.host']);
            }
        } else {
            if (!$az->can('visitors.invite')) {
                throw HttpException::forbidden();
            }
            $le = $this->employerOf($az->userId) ?? throw new BusinessException('visitors.error.not_employee');
            $host = $az->userId;
        }
        $f = static fn (string $k, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? ''))), 0, $max);
        $name = $f('visitor_name', 160);
        $errors = [];
        if (mb_strlen($name) < 2) {
            $errors['visitor_name'] = 'visitors.error.name';
        }
        $purpose = $f('purpose', 500);
        if (mb_strlen($purpose) < 3) {
            $errors['purpose'] = 'visitors.error.purpose';
        }
        $people = trim((string) ($in['people'] ?? '1'));
        if (preg_match('/^\d{1,3}$/', $people) !== 1 || (int) $people < 1 || (int) $people > 200) {
            $errors['people'] = 'visitors.error.people';
        }
        $site = trim((string) ($in['site_id'] ?? ''));
        if ($site !== '' && !in_array((int) $site, array_column($this->sites($le), 'id'), true)) {
            $errors['site_id'] = 'visitors.error.site';
        }
        $now = $this->app->clock->now();
        if ($walkIn) {
            $fromUtc = $now->format('Y-m-d H:i:s.u');
            $hours = trim((string) ($in['hours'] ?? '2'));
            if (preg_match('/^\d{1,2}$/', $hours) !== 1 || (int) $hours < 1 || (int) $hours > 24) {
                $errors['hours'] = 'visitors.error.hours';
                $hours = '2';
            }
            $toUtc = $now->modify('+' . (int) $hours . ' hours')->format('Y-m-d H:i:s.u');
        } else {
            try {
                [$fromTs, $fromUtc] = $this->local((string) ($in['expected_from'] ?? ''), 'expected_from');
                [$toTs, $toUtc] = $this->local((string) ($in['expected_to'] ?? ''), 'expected_to');
                if ($toTs <= $fromTs || $toTs - $fromTs > self::MAX_DAYS * 86400) {
                    $errors['expected_to'] = 'visitors.error.window';
                }
                if ($toTs <= $now->getTimestamp()) {
                    $errors['expected_to'] = 'visitors.error.past';
                }
            } catch (ValidationException $e) {
                $errors += $e->errors;
                $fromUtc = $toUtc = '';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $vehicle = $this->settings($le)['record_vehicle'];
        $pid = Ulid::generate($this->app->clock);
        $stamp = $this->stamp();
        $token = bin2hex(random_bytes(16));
        $db->transactional(function () use ($db, $le, $pid, $stamp, $az, $walkIn, $host, $name, $f, $purpose, $people, $site, $vehicle, $fromUtc, $toUtc, $token): void {
            $db->one('SELECT id FROM legal_entities WHERE id = ? FOR UPDATE', [$le]);
            $prefix = 'VS' . $this->app->clock->now()->setTimezone($this->tz())->format('ym') . '-';
            $last = (string) ($db->scalar('SELECT MAX(ref) FROM vs_visits WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
            $ref = $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
            $db->run(
                'INSERT INTO vs_visits (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, ref, kind, host_user_id, visitor_name, visitor_company, purpose, people,
                vehicle_plate, items, expected_from, expected_to, qr_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $stamp, $az->userId, $stamp, $az->userId, $le, $site === '' ? null : (int) $site, $ref, $walkIn ? 'walk_in' : 'invited', $host, $name,
                    $f('visitor_company', 160) ?: null, $purpose, (int) $people, $vehicle ? ($f('vehicle_plate', 40) ?: null) : null, $vehicle ? ($f('items', 500) ?: null) : null,
                $fromUtc,
                $toUtc,
                $walkIn ? null : $token]
            );
            $this->event((int) $db->pdo()->lastInsertId(), $walkIn ? 'walk_in' : 'invited', $az->userId);
        });
        $this->app->audit()->record($walkIn ? 'visitors.walk_in' : 'visitors.invited', 'success', 'vs_visit', $pid, $le);
        return $pid;
    }

    /** Host by employee code or user name, employed by the company today. */
    public function hostLookup(int $le, string $q): ?int
    {
        if ($q === '') {
            return null;
        }
        $code = trim(explode('—', $q)[0]);
        $uid = $this->app->db()->scalar(
            "SELECT u.id FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR e.employee_code = ?) LIMIT 1",
            [$code, $code]
        );
        return $uid !== null && $this->employerOf((int) $uid) === $le ? (int) $uid : null;
    }

    /** @return list<array{label: string}> employees of the company for the walk-in host list */
    public function hosts(int $le): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT u.id, u.username, COALESCE(u.display_name, u.username) AS name, e.employee_code FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.status = 'active' ORDER BY e.employee_code") as $r) {
            if ($this->employerOf((int) $r['id']) === $le) {
                $out[] = ['label' => $r['employee_code'] . ' — ' . $r['name']];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one(
            'SELECT v.*, COALESCE(h.display_name, h.username) AS host_name, s.code AS site_code FROM vs_visits v JOIN users h ON h.id = v.host_user_id LEFT JOIN sites s ON s.id = v.site_id WHERE v.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
    }

    /**
     * Host, gate staff or managers of the company.
     * @return array<string, mixed>
     */
    public function visit(Authorizer $az, string $pid): array
    {
        $v = $this->row($pid);
        $le = (int) $v['legal_entity_id'];
        if ((int) $v['host_user_id'] !== $az->userId && !$az->scope('visitors.gate')->coversLegalEntity($le) && !$az->scope('visitors.manage')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        $v['history'] = $this->app->db()->all('SELECT e.action, e.note, e.created_at, COALESCE(u.display_name, u.username) AS actor FROM vs_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.visit_id = ? ORDER BY e.id', [$v['id']]);
        $v['blacklisted'] = $v['visitor_name'] === null ? null : $this->blacklisted($le, (string) $v['visitor_name']);
        return $v;
    }

    /** @return list<array<string, mixed>> my invitations */
    public function mine(int $userId): array
    {
        return $this->app->db()->all("SELECT * FROM vs_visits WHERE host_user_id = ? ORDER BY status IN ('invited','checked_in') DESC, expected_from DESC LIMIT 100", [$userId]);
    }

    /** @return list<array<string, mixed>> visits of a day (local) plus everyone still inside */
    public function day(int $le, string $date): array
    {
        $d0 = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->tz()) ?: throw HttpException::notFound();
        $start = $d0->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $end = $d0->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        return $this->app->db()->all(
            "SELECT v.*, COALESCE(h.display_name, h.username) AS host_name FROM vs_visits v JOIN users h ON h.id = v.host_user_id
             WHERE v.legal_entity_id = ? AND ((v.expected_from < ? AND v.expected_to >= ?) OR (v.checked_in_at >= ? AND v.checked_in_at < ?) OR v.status = 'checked_in')
             ORDER BY FIELD(v.status, 'checked_in', 'invited', 'checked_out', 'denied', 'cancelled', 'expired'), v.expected_from",
            [$le, $end, $start, $start, $end]
        );
    }

    /** Gate lookup by QR link / token or ref. Expired or finished invitations are refused. */
    public function find(Authorizer $az, int $le, string $code): string
    {
        $this->guard($az, $le, 'visitors.gate');
        $code = trim($code);
        if (preg_match('~(?:/visit/|code=)([0-9a-f]{32})~', $code, $m) === 1) {
            $code = $m[1];
        }
        $v = preg_match('/^[0-9a-f]{32}$/', $code) === 1
            ? $this->app->db()->one('SELECT * FROM vs_visits WHERE qr_token = ?', [$code])
            : $this->app->db()->one('SELECT * FROM vs_visits WHERE legal_entity_id = ? AND ref = ?', [$le, strtoupper($code)]);
        if ($v === null || (int) $v['legal_entity_id'] !== $le) {
            throw new BusinessException('visitors.error.not_found');
        }
        if ($v['status'] === 'invited' && $v['expected_to'] < $this->stamp()) {
            throw new BusinessException('visitors.error.qr_expired');
        }
        return (string) $v['public_id'];
    }

    /** @return array<string, mixed>|null */
    public function blacklisted(int $le, string $name): ?array
    {
        return $this->app->db()->one('SELECT public_id, display_name, company, reason FROM vs_blacklist WHERE legal_entity_id = ? AND active = 1 AND name_key = ?', [$le, self::nameKey($name)]);
    }

    /** @param array<string, mixed> $in */
    public function checkIn(Authorizer $az, string $pid, array $in): void
    {
        $v = $this->row($pid);
        $le = (int) $v['legal_entity_id'];
        $this->guard($az, $le, 'visitors.gate');
        if ($v['status'] !== 'invited') {
            throw new BusinessException('visitors.error.state');
        }
        $now = $this->stamp();
        if ($v['kind'] === 'invited' && $v['expected_to'] < $now) {
            throw new BusinessException('visitors.error.qr_expired');
        }
        if (empty($in['notice_given'])) {
            throw new ValidationException(['notice_given' => 'visitors.error.notice']);
        }
        $override = mb_substr(trim((string) ($in['override_note'] ?? '')), 0, 500);
        if ($this->blacklisted($le, (string) $v['visitor_name']) !== null) {
            if (!$az->scope('visitors.manage')->coversLegalEntity($le)) {
                throw new BusinessException('visitors.error.blacklisted');
            }
            if (mb_strlen($override) < 10) {
                throw new ValidationException(['override_note' => 'visitors.error.override']);
            }
        } else {
            $override = '';
        }
        $badge = mb_substr(trim((string) ($in['badge_no'] ?? '')), 0, 40);
        $ok = $this->app->db()->run(
            "UPDATE vs_visits SET status = 'checked_in', notice_given = 1, badge_no = ?, checked_in_at = ?, checked_in_by = ?, blacklist_note = ?, updated_at = ?, updated_by = ? WHERE id = ? AND status = 'invited'",
            [$badge === '' ? null : $badge, $now, $az->userId, $override === '' ? null : $override, $now, $az->userId, $v['id']]
        )->rowCount();
        if ($ok === 0) {
            throw new BusinessException('visitors.error.state');
        }
        $this->event((int) $v['id'], $override === '' ? 'checked_in' : 'checked_in_override', $az->userId, $override === '' ? null : $override);
        $this->app->audit()->record('visitors.checked_in', 'success', 'vs_visit', $pid, $le, null, ['override' => $override !== '']);
        $this->app->notifier()->notify((int) $v['host_user_id'], 'visitors.arrived', 'visitors.notify.arrived', ['ref' => $v['ref']], '/visitors/v/' . $pid, 'vs.in.' . $v['id']);
    }

    public function checkOut(Authorizer $az, string $pid, bool $badgeReturned): void
    {
        $v = $this->row($pid);
        $this->guard($az, (int) $v['legal_entity_id'], 'visitors.gate');
        if ($v['status'] !== 'checked_in') {
            throw new BusinessException('visitors.error.state');
        }
        $now = $this->stamp();
        $this->app->db()->run(
            "UPDATE vs_visits SET status = 'checked_out', checked_out_at = ?, checked_out_by = ?, badge_returned = ?, updated_at = ?, updated_by = ? WHERE id = ?",
            [$now, $az->userId, $v['badge_no'] === null ? null : ($badgeReturned ? 1 : 0), $now, $az->userId, $v['id']]
        );
        $this->event((int) $v['id'], $v['badge_no'] !== null && !$badgeReturned ? 'checked_out_no_badge' : 'checked_out', $az->userId);
        $this->app->audit()->record('visitors.checked_out', 'success', 'vs_visit', $pid, (int) $v['legal_entity_id']);
    }

    /** Deny at the gate (gate staff) or cancel an invitation (host or gate staff) — both need a reason. */
    public function close(Authorizer $az, string $pid, string $how, string $reason): void
    {
        $v = $this->row($pid);
        $le = (int) $v['legal_entity_id'];
        $gate = $az->scope('visitors.gate')->coversLegalEntity($le);
        if ($how === 'deny' ? !$gate : (!$gate && (int) $v['host_user_id'] !== $az->userId)) {
            throw HttpException::notFound();
        }
        if ($v['status'] !== 'invited') {
            throw new BusinessException('visitors.error.state');
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['reason' => 'visitors.error.reason']);
        }
        $status = $how === 'deny' ? 'denied' : 'cancelled';
        $this->app->db()->run('UPDATE vs_visits SET status = ?, closed_reason = ?, qr_token = NULL, updated_at = ?, updated_by = ? WHERE id = ?', [$status, $reason, $this->stamp(), $az->userId, $v['id']]);
        $this->event((int) $v['id'], $status, $az->userId, $reason);
        $this->app->audit()->record('visitors.' . $status, 'success', 'vs_visit', $pid, $le);
        if ($status === 'denied' && (int) $v['host_user_id'] !== $az->userId) {
            $this->app->notifier()->notify((int) $v['host_user_id'], 'visitors.denied', 'visitors.notify.denied', ['ref' => $v['ref']], '/visitors/v/' . $pid);
        }
    }

    // ---- public visitor page --------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null the invitation behind a QR link (null = unknown, cancelled or past its window) */
    public function byToken(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $v = $this->app->db()->one("SELECT v.*, COALESCE(h.display_name, h.username) AS host_name, s.code AS site_code FROM vs_visits v JOIN users h ON h.id = v.host_user_id LEFT JOIN sites s ON s.id = v.site_id
            WHERE v.qr_token = ? AND v.status IN ('invited','checked_in')", [$token]);
        if ($v === null || ($v['status'] === 'invited' && $v['expected_to'] < $this->stamp())) {
            return null;
        }
        $v['company'] = $this->companyName((int) $v['legal_entity_id']);
        $v['notice_url'] = PrivacyCenterService::publicUrl($this->app, (int) $v['legal_entity_id'], 'visitor');
        return $v;
    }

    public function qrSvg(string $token, string $origin, string $ref): string
    {
        return QrRender::svg(QrEncoder::encode(rtrim($origin, '/') . '/visit/' . $token, 'M')['modules'], 6, null, $ref);
    }

    public function noticeUrl(int $le): ?string
    {
        return PrivacyCenterService::publicUrl($this->app, $le, 'visitor');
    }

    // ---- blacklist ------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function blacklist(int $le): array
    {
        return $this->app->db()->all('SELECT * FROM vs_blacklist WHERE legal_entity_id = ? ORDER BY active DESC, display_name', [$le]);
    }

    /** @param array<string, mixed> $in */
    public function addBlacklist(Authorizer $az, int $le, array $in): void
    {
        $this->guard($az, $le, 'visitors.manage');
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? ''))), 0, 160);
        $reason = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 500);
        $errors = [];
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'visitors.error.name';
        }
        if (mb_strlen($reason) < 10) {
            $errors['reason'] = 'visitors.error.blacklist_reason';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->stamp();
        $pid = Ulid::generate($this->app->clock);
        $this->app->db()->run(
            'INSERT INTO vs_blacklist (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name_key, display_name, company, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, self::nameKey($name), $name, mb_substr(trim((string) ($in['company'] ?? '')), 0, 160) ?: null, $reason]
        );
        $this->app->audit()->record('visitors.blacklist_added', 'success', 'vs_blacklist', $pid, $le);
    }

    public function removeBlacklist(Authorizer $az, string $pid, string $reason): void
    {
        $b = $this->app->db()->one('SELECT * FROM vs_blacklist WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->guard($az, (int) $b['legal_entity_id'], 'visitors.manage');
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['reason' => 'visitors.error.reason']);
        }
        $this->app->db()->run('UPDATE vs_blacklist SET active = 0, updated_at = ?, updated_by = ? WHERE id = ?', [$this->stamp(), $az->userId, $b['id']]);
        $this->app->audit()->record('visitors.blacklist_removed', 'success', 'vs_blacklist', $pid, (int) $b['legal_entity_id'], null, ['reason' => mb_substr(trim($reason), 0, 200)]);
    }

    // ---- report ---------------------------------------------------------------------------------------------------------

    /** @return list<array{day: string, visits: int, people: int, walk_in: int, denied: int}> per day of a month (local) */
    public function monthly(int $le, string $ym): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $ym . '-01', $this->tz()) ?: throw HttpException::notFound();
        $rows = $this->app->db()->all(
            "SELECT checked_in_at, people, kind, status, expected_from FROM vs_visits WHERE legal_entity_id = ? AND expected_from >= ? AND expected_from < ?",
            [$le, $start->modify('-1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), $start->modify('+1 month +1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]
        );
        $out = [];
        foreach ($rows as $r) {
            $at = (string) ($r['checked_in_at'] ?? $r['expected_from']);
            $day = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $at, new DateTimeZone('UTC'));
            $day = ($day ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($at, 0, 19), new DateTimeZone('UTC')));
            if ($day === false) {
                continue;
            }
            $d = $day->setTimezone($this->tz())->format('Y-m-d');
            if (substr($d, 0, 7) !== $ym) {
                continue;
            }
            $out[$d] ??= ['day' => $d, 'visits' => 0, 'people' => 0, 'walk_in' => 0, 'denied' => 0];
            if (in_array($r['status'], ['checked_in', 'checked_out'], true)) {
                $out[$d]['visits']++;
                $out[$d]['people'] += (int) $r['people'];
                $out[$d]['walk_in'] += $r['kind'] === 'walk_in' ? 1 : 0;
            }
            $out[$d]['denied'] += $r['status'] === 'denied' ? 1 : 0;
        }
        ksort($out);
        return array_values($out);
    }

    // ---- job ------------------------------------------------------------------------------------------------------------

    /** @return array{expired: int, overdue: int, purged: int} */
    public function tick(): array
    {
        $db = $this->app->db();
        $now = $this->app->clock->now();
        $stamp = $now->format('Y-m-d H:i:s.u');
        $res = ['expired' => 0, 'overdue' => 0, 'purged' => 0];
        foreach ($db->all("SELECT id FROM vs_visits WHERE status = 'invited' AND expected_to < ?", [$stamp]) as $v) {
            $db->run("UPDATE vs_visits SET status = 'expired', qr_token = NULL, updated_at = ? WHERE id = ? AND status = 'invited'", [$stamp, $v['id']]);
            $this->event((int) $v['id'], 'expired', null);
            $res['expired']++;
        }
        foreach ($db->all("SELECT * FROM vs_visits WHERE status = 'checked_in' AND overdue_notified = 0") as $v) {
            $mins = $this->settings((int) $v['legal_entity_id'])['overdue_minutes'];
            if ($mins === null) {
                continue;
            }
            $due = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $v['expected_to'], new DateTimeZone('UTC'));
            if ($due === false || $due->modify('+' . $mins . ' minutes') > $now) {
                continue;
            }
            $to = [(int) $v['host_user_id']];
            foreach ($db->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN role_permissions p ON p.role_id = a.role_id JOIN users u ON u.id = a.user_id WHERE a.status = 'active' AND u.status = 'active' AND p.permission_code = 'visitors.gate'") as $g) {
                $to[] = (int) $g['user_id'];
            }
            foreach (array_unique($to) as $u) {
                $az = new Authorizer($db, $this->app->clock, $u);
                if ($u === (int) $v['host_user_id'] || $az->scope('visitors.gate')->coversLegalEntity((int) $v['legal_entity_id'])) {
                    $this->app->notifier()->notify($u, 'visitors.overdue', 'visitors.notify.overdue', ['ref' => $v['ref']], '/visitors/v/' . $v['public_id'], 'vs.over.' . $v['id'] . '.' . $u);
                }
            }
            $db->run('UPDATE vs_visits SET overdue_notified = 1 WHERE id = ?', [$v['id']]);
            $this->event((int) $v['id'], 'overdue', null);
            $res['overdue']++;
        }
        foreach ($db->all('SELECT legal_entity_id, retention_days FROM vs_settings WHERE retention_days IS NOT NULL') as $s) {
            $cut = $now->modify('-' . (int) $s['retention_days'] . ' days')->format('Y-m-d H:i:s.u');
            $res['purged'] += $db->run("UPDATE vs_visits SET visitor_name = NULL, visitor_company = NULL, purpose = NULL, vehicle_plate = NULL, items = NULL, badge_no = NULL, blacklist_note = NULL, closed_reason = NULL, purged_at = ?
                WHERE legal_entity_id = ? AND purged_at IS NULL AND status IN ('checked_out','cancelled','denied','expired') AND expected_to < ?", [$stamp, $s['legal_entity_id'], $cut])->rowCount();
        }
        return $res;
    }

    public static function tickJob(App $app): void
    {
        self::of($app)->tick();
    }
}
