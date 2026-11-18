<?php

declare(strict_types=1);

namespace Blue\Modules\Gps;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Attendance\AttendanceService;
use Blue\Modules\Employees\EmployeeService;

/**
 * M57 GPS check-in/out (phase 5C, DEC-71).
 *
 *  policy     `gps.policy` (no default — no policy = off): what to do out of area, accuracy limit, offline window,
 *             which employee groups, and the notice the employee acknowledges before the first check-in.
 *  points     geofences per company (optionally per branch): centre + radius. The nearest active point counts.
 *  punch      the browser sends the reading (lat/lng/accuracy/device time) once the employee presses the button —
 *             never in the background. Online: the server time is the punch time; offline (queued in the browser):
 *             the device time, always held for review. A repeated event id returns the first punch.
 *  status     validated (sent to attendance at once) · flagged (out of area, imprecise, clock differs, offline,
 *             no point) → the line manager or HR in scope approves (sent) or rejects with a note.
 *             Spoofing cannot be detected reliably in a browser — the flags are signals, not proof.
 *  privacy    coordinates are shown only to the employee and, while a punch waits for review, to its reviewers;
 *             reports show the in/out-of-area result and distance. The retention job clears coordinates, IP and
 *             device once the approved rule for record class gps_selfie says so — no approved rule = nothing cleared.
 */
final class GpsService
{
    public const HOLD = ['outside', 'accuracy', 'clock', 'offline', 'no_point'];
    public const CLOCK_SKEW = 300;
    public const MIN_GAP = 60;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
    }

    public function att(): AttendanceService
    {
        return AttendanceService::of($this->app);
    }

    public function today(): string
    {
        return $this->emp()->today();
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function nowUtc(): DateTimeImmutable
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    // ---- policy -----------------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null policy in force (+ version_pid) */
    public function policy(int $le, ?string $date = null): ?array
    {
        $date ??= $this->today();
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', $this->tz()) ?: $this->app->clock->now();
        $r = $this->app->registry()->resolveOrNull('gps.policy', $le, null, $at);
        return $r !== null && is_array($r->value) ? $r->value + ['version_pid' => $r->versionPublicId, 'from' => $r->effectiveFrom] : null;
    }

    /** @param array<string, mixed> $in */
    public function proposePolicy(Authorizer $az, int $le, array $in, string $from, string $source, string $reason): string
    {
        if (!$az->scope('gps.policy')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        [$value, $errors] = GpsPolicy::validate($in);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->tz());
        if ($d === false || $d->format('Y-m-d') !== $from) {
            $errors['effective_from'] = 'gps.error.date';
        }
        if (mb_strlen(trim($source)) < 5) {
            $errors['source_reference'] = 'gps.policy_error.source';
        }
        if (mb_strlen(trim($reason)) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== [] || $value === null || $d === false) {
            throw new ValidationException($errors);
        }
        $reg = $this->app->registry();
        $pid = $reg->propose($az, 'gps.policy', 'legal_entity', $le, (string) json_encode($value, JSON_UNESCAPED_UNICODE), $d, null, trim($reason), trim($source));
        $reg->submit($az, $pid);
        return $pid;
    }

    // ---- check-in points ------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function geofences(int $le, bool $activeOnly = false): array
    {
        return $this->app->db()->all(
            'SELECT g.*, s.code AS site_code FROM gps_geofences g LEFT JOIN sites s ON s.id = g.site_id WHERE g.legal_entity_id = ?' . ($activeOnly ? " AND g.status = 'active'" : '') . ' ORDER BY g.name',
            [$le]
        );
    }

    /**
     * Add or change a check-in point (gps.manage on the company). Changing a point needs a reason (audited).
     *
     * @param array<string, mixed> $in name, site (public id|''), lat, lng, radius_m, status, reason
     */
    public function saveGeofence(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$az->scope('gps.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $old = null;
        if ($pid !== null) {
            $old = $db->one('SELECT * FROM gps_geofences WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]);
            if ($old === null) {
                throw HttpException::notFound();
            }
        }
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'gps.error.name';
        }
        $site = null;
        if (($in['site'] ?? '') !== '') {
            $site = $db->scalar('SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ?', [(string) $in['site'], $le]);
            if ($site === null) {
                $errors['site'] = 'form.invalid_choice';
            }
        }
        $lat = self::coord((string) ($in['lat'] ?? ''), 90);
        $lng = self::coord((string) ($in['lng'] ?? ''), 180);
        if ($lat === null) {
            $errors['lat'] = 'gps.error.lat';
        }
        if ($lng === null) {
            $errors['lng'] = 'gps.error.lng';
        }
        $radius = trim((string) ($in['radius_m'] ?? ''));
        if (!ctype_digit($radius) || (int) $radius < 20 || (int) $radius > 5000) {
            $errors['radius_m'] = 'gps.error.radius';
        }
        $status = ($in['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($old !== null && mb_strlen($reason) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->now();
        $vals = ['name' => $name, 'site_id' => $site !== null ? (int) $site : null, 'lat' => $lat, 'lng' => $lng, 'radius_m' => (int) $radius, 'status' => $status];
        if ($old === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO gps_geofences (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, name, lat, lng, radius_m, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $vals['site_id'], $name, $lat, $lng, (int) $radius, $status]
            );
            $this->app->audit()->record('gps.geofence_created', 'success', 'gps_geofence', $pid, $le, null, ['name' => $name, 'radius_m' => (int) $radius]);
            return $pid;
        }
        $db->run(
            'UPDATE gps_geofences SET name = ?, site_id = ?, lat = ?, lng = ?, radius_m = ?, status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$name, $vals['site_id'], $lat, $lng, (int) $radius, $status, $now, $az->userId, $old['id']]
        );
        $diff = [];
        foreach ($vals as $k => $v) {
            if ((string) $old[$k] !== (string) $v) {
                $diff[$k] = ['from' => $old[$k], 'to' => $v];
            }
        }
        $this->app->audit()->record('gps.geofence_changed', 'success', 'gps_geofence', (string) $pid, $le, $reason, $diff);
        return (string) $pid;
    }

    /** "13.7563" → normalised 6-decimal string within ±$max, or null */
    public static function coord(string $raw, int $max): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^-?\d{1,3}(\.\d{1,10})?$/', $raw) !== 1 || abs((float) $raw) > $max) {
            return null;
        }
        return number_format(round((float) $raw, 6), 6, '.', '');
    }

    /** Great-circle distance in metres (haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return (int) round(2 * $r * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * The nearest active point for the employee's company (company-wide points and the employee's branch).
     *
     * @return array{0: array<string, mixed>|null, 1: int|null}
     */
    public function nearest(int $le, ?int $site, float $lat, float $lng): array
    {
        $best = null;
        $dist = null;
        foreach ($this->geofences($le, true) as $g) {
            if ($g['site_id'] !== null && (int) $g['site_id'] !== $site) {
                continue;
            }
            $d = self::distance($lat, $lng, (float) $g['lat'], (float) $g['lng']);
            if ($dist === null || $d - (int) $g['radius_m'] < $dist - (int) $best['radius_m']) {
                [$best, $dist] = [$g, $d];
            }
        }
        return [$best, $dist];
    }

    // ---- employee -------------------------------------------------------------------------------------------------

    /**
     * Everything the check-in screen needs for the signed-in user.
     *
     * @return array{me: array<string, mixed>|null, policy: array<string, mixed>|null, eligible: bool, needs_notice: bool, points: int}
     */
    public function state(Authorizer $az): array
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null || $me['job'] === null || $me['status'] === 'former') {
            return ['me' => $me, 'policy' => null, 'eligible' => false, 'needs_notice' => false, 'points' => 0];
        }
        $le = (int) $me['job']['legal_entity_id'];
        $policy = $this->policy($le);
        $groups = (array) ($policy['groups'] ?? []);
        $eligible = $policy !== null && ($groups === [] || in_array(strtoupper((string) ($me['job']['employee_group'] ?? '')), $groups, true));
        $needs = $eligible && $this->app->db()->scalar('SELECT id FROM gps_notices WHERE employee_id = ? AND policy_version_pid = ?', [$me['id'], $policy['version_pid']]) === null;
        return ['me' => $me, 'policy' => $policy, 'eligible' => $eligible, 'needs_notice' => $needs, 'points' => count($this->geofences($le, true))];
    }

    public function acknowledge(Authorizer $az): void
    {
        $s = $this->state($az);
        if (!$s['eligible'] || $s['me'] === null || $s['policy'] === null) {
            throw new BusinessException('gps.error.not_enabled', [], 409);
        }
        $this->app->db()->run(
            'INSERT IGNORE INTO gps_notices (employee_id, policy_version_pid, user_id, acknowledged_at) VALUES (?, ?, ?, ?)',
            [$s['me']['id'], $s['policy']['version_pid'], $az->userId, $this->now()]
        );
        $this->app->audit()->record('gps.notice_acknowledged', 'success', 'employee', (string) $s['me']['public_id'], (int) $s['me']['job']['legal_entity_id'], null, ['policy' => $s['policy']['version_pid']]);
    }

    /**
     * Record one check-in / check-out.
     *
     * @param array<string, mixed> $in direction, lat, lng, accuracy, captured (epoch ms), event, offline (0/1), note
     * @return array{pid: string, status: string, flags: list<string>, repeated: bool}
     */
    public function punch(Authorizer $az, array $in, string $ip = '', string $device = ''): array
    {
        $s = $this->state($az);
        if (!$s['eligible'] || $s['me'] === null || $s['policy'] === null) {
            throw new BusinessException('gps.error.not_enabled', [], 409);
        }
        if ($s['needs_notice']) {
            throw new BusinessException('gps.error.notice', [], 409);
        }
        $me = $s['me'];
        $policy = $s['policy'];
        $db = $this->app->db();
        $event = (string) ($in['event'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{8,40}$/', $event) !== 1) {
            throw new ValidationException(['event' => 'gps.error.event']);
        }
        $existing = $db->one('SELECT public_id, status, flags FROM gps_punches WHERE employee_id = ? AND client_event_id = ?', [$me['id'], $event]);
        if ($existing !== null) {
            return ['pid' => (string) $existing['public_id'], 'status' => (string) $existing['status'], 'flags' => self::flagList((string) $existing['flags']), 'repeated' => true];
        }
        $errors = [];
        $dir = (string) ($in['direction'] ?? '');
        if (!in_array($dir, ['in', 'out'], true)) {
            $errors['direction'] = 'form.invalid_choice';
        }
        $lat = self::coord((string) ($in['lat'] ?? ''), 90);
        $lng = self::coord((string) ($in['lng'] ?? ''), 180);
        $accRaw = trim((string) ($in['accuracy'] ?? ''));
        $acc = preg_match('/^\d{1,6}(\.\d+)?$/', $accRaw) === 1 ? (int) ceil((float) $accRaw) : null;
        if ($lat === null || $lng === null || $acc === null) {
            $errors['location'] = 'gps.error.location';
        }
        $capRaw = trim((string) ($in['captured'] ?? ''));
        $captured = ctype_digit($capRaw) && strlen($capRaw) <= 15 ? DateTimeImmutable::createFromFormat('U', (string) intdiv((int) $capRaw, 1000)) : false;
        if ($captured === false) {
            $errors['location'] = 'gps.error.location';
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 300) {
            $errors['note'] = 'gps.error.note_long';
        }
        if ($errors !== [] || $captured === false || $lat === null || $lng === null) {
            throw new ValidationException($errors);
        }
        $now = $this->nowUtc();
        $captured = $captured->setTimezone(new DateTimeZone('UTC'));
        $offline = ($in['offline'] ?? '') === '1';
        $flags = [];
        $skew = $captured->getTimestamp() - $now->getTimestamp();
        if ($offline) {
            $hours = (int) ($policy['offline_hours'] ?? 0);
            if ($hours === 0) {
                throw new BusinessException('gps.error.offline_off', [], 422);
            }
            if ($skew > self::CLOCK_SKEW) {
                throw new BusinessException('gps.error.future', [], 422);
            }
            if (-$skew > $hours * 3600) {
                throw new BusinessException('gps.error.too_old', [], 422);
            }
            $at = $captured;
            $flags[] = 'offline';
        } else {
            $at = $now;
            if (abs($skew) > self::CLOCK_SKEW) {
                $flags[] = 'clock';
            }
        }
        $job = $me['job'];
        $le = (int) $job['legal_entity_id'];
        $site = $job['site_id'] !== null ? (int) $job['site_id'] : null;
        $atS = $at->format('Y-m-d H:i:s');
        $recent = $db->scalar(
            "SELECT id FROM gps_punches WHERE employee_id = ? AND direction = ? AND status <> 'rejected' AND punch_at_utc BETWEEN ? AND ?",
            [$me['id'], $dir, $at->modify('-' . self::MIN_GAP . ' seconds')->format('Y-m-d H:i:s'), $at->modify('+' . self::MIN_GAP . ' seconds')->format('Y-m-d H:i:s')]
        );
        if ($recent !== null) {
            throw new BusinessException('gps.error.too_soon', [], 422);
        }
        [$fence, $dist] = $this->nearest($le, $site, (float) $lat, (float) $lng);
        $inside = $fence !== null && $dist !== null ? $dist <= (int) $fence['radius_m'] : null;
        if ($fence === null) {
            $flags[] = 'no_point';
        } elseif ($inside === false) {
            if ($policy['outside'] === 'block') {
                $this->app->audit()->record('gps.blocked', 'denied', 'employee', (string) $me['public_id'], $le, null, ['distance_m' => $dist, 'point' => $fence['public_id'], 'direction' => $dir]);
                throw new BusinessException('gps.error.outside', [], 422);
            }
            $flags[] = 'outside';
        }
        if ($acc > (int) $policy['max_accuracy_m']) {
            $flags[] = 'accuracy';
        }
        $hold = array_values(array_filter($flags, static fn (string $f): bool => in_array($f, self::HOLD, true) && !($f === 'outside' && $policy['outside'] === 'warn')));
        $status = $hold === [] ? 'validated' : 'flagged';
        $local = $at->setTimezone($this->att()->siteTz($site));
        $pid = Ulid::generate($this->app->clock);
        $nowS = $this->now();
        $db->run(
            'INSERT INTO gps_punches (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, site_id, department_id, direction, client_event_id,
                captured_at_utc, received_at_utc, punch_at_utc, local_time, work_date, offline, lat, lng, accuracy_m, geofence_id, distance_m, inside, flags, status, employee_note,
                ip, device, policy_version_pid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $nowS, $az->userId, $nowS, $az->userId, $me['id'], $le, $site, $job['department_id'], $dir, $event,
                $captured->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $atS, $local->format('Y-m-d H:i:s'), $local->format('Y-m-d'), $offline ? 1 : 0, $lat, $lng, $acc,
                $fence['id'] ?? null, $dist, $inside === null ? null : ($inside ? 1 : 0), implode(',', $flags), $status, $note === '' ? null : $note,
                $ip === '' ? null : mb_substr($ip, 0, 45), $device === '' ? null : mb_substr($device, 0, 120), $policy['version_pid']]
        );
        $this->app->audit()->record('gps.punch', 'success', 'gps_punch', $pid, $le, null, ['direction' => $dir, 'status' => $status, 'flags' => $flags, 'distance_m' => $dist]);
        if ($status === 'validated') {
            $this->send($pid, $az->userId);
        } else {
            foreach ($this->emp()->managerUserIds((int) $me['id'], $local->format('Y-m-d')) as $uid) {
                $this->app->notifier()->notify($uid, 'gps.flagged', 'gps.notify.flagged', ['code' => (string) $me['employee_code']], '/gps/p/' . $pid, 'gps.flagged:' . $pid . ':' . $uid);
            }
        }
        return ['pid' => $pid, 'status' => $status, 'flags' => $flags, 'repeated' => false];
    }

    /** Hand a validated / approved punch to attendance (M34) — the event id makes a repeat harmless. */
    private function send(string $pid, ?int $actorId): void
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM gps_punches WHERE public_id = ?', [$pid]);
        if ($p === null || !in_array($p['status'], ['validated', 'approved'], true)) {
            return;
        }
        $event = 'gps:' . $p['public_id'];
        $this->att()->acceptPunch('gps', $event, (int) $p['employee_id'], (string) $p['punch_at_utc'], (string) $p['local_time'], (string) $p['direction'], 'GPS', $actorId);
        $db->run('UPDATE gps_punches SET attendance_event_id = ?, attendance_sent_at = ? WHERE id = ?', [$event, $this->now(), $p['id']]);
    }

    /** @return list<string> */
    public static function flagList(string $flags): array
    {
        return $flags === '' ? [] : explode(',', $flags);
    }

    /** @return list<array<string, mixed>> recent punches of an employee (newest first) */
    public function forEmployee(int $employeeId, int $limit = 40): array
    {
        return $this->app->db()->all(
            'SELECT p.*, g.name AS point_name FROM gps_punches p LEFT JOIN gps_geofences g ON g.id = p.geofence_id WHERE p.employee_id = ? ORDER BY p.punch_at_utc DESC LIMIT ' . max(1, min(200, $limit)),
            [$employeeId]
        );
    }

    // ---- review -----------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $p punch row */
    public function canReview(Authorizer $az, array $p): bool
    {
        $me = $this->emp()->forUser($az->userId, (string) $p['work_date']);
        if ($me !== null && (int) $me['id'] === (int) $p['employee_id']) {
            return false; // never one's own punch
        }
        if ($me !== null && in_array((int) $p['employee_id'], $this->emp()->directReports((int) $me['id'], (string) $p['work_date']), true)) {
            return true;
        }
        $job = ['legal_entity_id' => $p['legal_entity_id'], 'site_id' => $p['site_id'], 'department_id' => $p['department_id']];
        return EmployeeService::covers($az->scope('gps.review'), $job);
    }

    /** @return list<array<string, mixed>> flagged punches this user may decide */
    public function reviewList(Authorizer $az, int $limit = 300): array
    {
        $rows = $this->app->db()->all(
            "SELECT p.*, e.employee_code, e.name_th, e.name_en, e.preferred_name, g.name AS point_name FROM gps_punches p JOIN employees e ON e.id = p.employee_id
              LEFT JOIN gps_geofences g ON g.id = p.geofence_id WHERE p.status = 'flagged' ORDER BY p.punch_at_utc LIMIT 2000"
        );
        $out = [];
        foreach ($rows as $r) {
            if ($this->canReview($az, $r)) {
                $out[] = $r;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * One punch with what this user may see: access = own | reviewer; show_location only for the owner, or for a
     * reviewer while the punch waits for review.
     *
     * @return array<string, mixed>
     */
    public function get(Authorizer $az, string $pid): array
    {
        $p = $this->app->db()->one(
            'SELECT p.*, e.employee_code, e.name_th, e.name_en, e.preferred_name, e.public_id AS employee_pid, g.name AS point_name, g.lat AS point_lat, g.lng AS point_lng, g.radius_m AS point_radius,
                    r.display_name AS reviewer_name
               FROM gps_punches p JOIN employees e ON e.id = p.employee_id LEFT JOIN gps_geofences g ON g.id = p.geofence_id LEFT JOIN users r ON r.id = p.reviewed_by WHERE p.public_id = ?',
            [$pid]
        );
        if ($p === null) {
            throw HttpException::notFound();
        }
        $me = $this->emp()->forUser($az->userId);
        if ($me !== null && (int) $me['id'] === (int) $p['employee_id']) {
            $p['access'] = 'own';
        } elseif ($this->canReview($az, $p)) {
            $p['access'] = 'reviewer';
        } else {
            throw HttpException::notFound();
        }
        $p['show_location'] = $p['lat'] !== null && ($p['access'] === 'own' || $p['status'] === 'flagged');
        if (!$p['show_location']) {
            $p['lat'] = $p['lng'] = $p['ip'] = $p['device'] = null;
        }
        return $p;
    }

    public function review(Authorizer $az, string $pid, string $action, string $note): void
    {
        $p = $this->get($az, $pid);
        if ($p['access'] !== 'reviewer') {
            throw HttpException::forbidden();
        }
        if ($p['status'] !== 'flagged') {
            throw new BusinessException('gps.error.state', [], 409);
        }
        $note = trim($note);
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new ValidationException(['action' => 'form.invalid_choice']);
        }
        if ($action === 'reject' && mb_strlen($note) < 5) {
            throw new ValidationException(['review_note' => 'gps.error.review_note']);
        }
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $n = $this->app->db()->run(
            "UPDATE gps_punches SET status = ?, reviewed_by = ?, reviewed_at = ?, review_note = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'flagged'",
            [$status, $az->userId, $this->now(), $note === '' ? null : mb_substr($note, 0, 300), $this->now(), $az->userId, $pid]
        )->rowCount();
        if ($n !== 1) {
            throw new BusinessException('gps.error.state', [], 409);
        }
        $this->app->audit()->record('gps.reviewed', 'success', 'gps_punch', $pid, (int) $p['legal_entity_id'], $note === '' ? null : $note, ['decision' => $status, 'flags' => self::flagList((string) $p['flags'])]);
        if ($status === 'approved') {
            $this->send($pid, $az->userId);
        }
        $uid = $this->app->db()->scalar("SELECT id FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1", [$p['employee_id']]);
        if ($uid !== null) {
            $this->app->notifier()->notify((int) $uid, 'gps.reviewed', 'gps.notify.' . $status, [], '/gps/p/' . $pid, 'gps.reviewed:' . $pid);
        }
    }

    // ---- report -------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> per employee in gps.report scope for the month: punches, in area, out of area, waiting, rejected */
    public function report(Authorizer $az, string $ym): array
    {
        [$where, $params] = $az->scope('gps.report')->sql('p.legal_entity_id', 'p.site_id', 'p.department_id');
        $rows = $this->app->db()->all(
            "SELECT e.employee_code, e.name_th, e.name_en, e.preferred_name, COUNT(*) AS punches, SUM(p.inside = 1) AS inside, SUM(p.inside = 0) AS outside,
                    SUM(p.status = 'flagged') AS waiting, SUM(p.status = 'rejected') AS rejected, SUM(p.offline = 1) AS offline
               FROM gps_punches p JOIN employees e ON e.id = p.employee_id
              WHERE ({$where}) AND p.work_date BETWEEN ? AND ? GROUP BY e.id, e.employee_code, e.name_th, e.name_en, e.preferred_name ORDER BY e.employee_code",
            array_merge($params, [$ym . '-01', $ym . '-31'])
        );
        return array_map(fn (array $r): array => ['label' => $r['employee_code'] . ' · ' . $this->emp()->displayName($r), 'punches' => (int) $r['punches'], 'inside' => (int) $r['inside'],
            'outside' => (int) $r['outside'], 'waiting' => (int) $r['waiting'], 'rejected' => (int) $r['rejected'], 'offline' => (int) $r['offline']], $rows);
    }

    // ---- retention ----------------------------------------------------------------------------------------------------

    public static function anonymizeJob(App $app): int
    {
        return self::of($app)->anonymize();
    }

    /**
     * Clear coordinates, accuracy, IP and device of punches older than the approved retention rule of record class
     * gps_selfie (trigger approximated by the work date). Punches still waiting for review are kept.
     */
    public function anonymize(): int
    {
        $rule = $this->app->retention()->current('gps_selfie');
        if ($rule === null || !in_array((string) $rule['disposition_action'], ['anonymize', 'destroy'], true) || (int) $rule['duration_months'] < 1) {
            return 0;
        }
        $cutoff = EmployeeService::ymd($this->today())->modify('-' . (int) $rule['duration_months'] . ' months')->format('Y-m-d');
        $n = $this->app->db()->run(
            "UPDATE gps_punches SET lat = NULL, lng = NULL, accuracy_m = NULL, ip = NULL, device = NULL, anonymized_at = ? WHERE work_date < ? AND anonymized_at IS NULL AND status <> 'flagged'",
            [$this->now(), $cutoff]
        )->rowCount();
        if ($n > 0) {
            $this->app->audit()->record('gps.anonymized', 'success', 'retention_rule', (string) $rule['public_id'], null, null, ['punches' => $n, 'before' => $cutoff]);
        }
        return $n;
    }
}
