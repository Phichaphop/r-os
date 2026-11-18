<?php

declare(strict_types=1);

namespace Blue\Modules\Eid;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Org\Translations;
use Blue\Core\Support\Qr\QrEncoder;
use Blue\Core\Support\Qr\QrRender;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * M13 employee e-ID card (phase 5C, DEC-71).
 *
 *  card      issued by HR (eid.manage) from the employee master — no copy of personal data on the card row: name,
 *            position, unit and branch are read from the master every time. One current card per employee.
 *  status    active → suspended / lost → reissued (old = replaced) / revoked. The employee may report their own card
 *            lost. Expired (past expires_on) and former employees are shown as not valid. Lost / suspended / revoked
 *            also stop wallet spending.
 *  QR        an opaque URL: random card QR id + a 30-second time window + an HMAC (key derived from APP_KEY) — no
 *            personal data, cannot be guessed, a screenshot stops working after about a minute, reissue changes the id.
 *            Printed cards (only if the company allows printing) carry a fixed code.
 *  verify    a signed-in person with eid.verify covering the employee's company/branch/unit sees photo, name, code
 *            and status only. Every scan is logged (result only).
 */
final class CardService
{
    public const WINDOW = 30;
    public const WINDOWS_BACK = 2;
    public const STATUS_CURRENT = ['active', 'suspended', 'lost'];

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

    public function today(): string
    {
        return $this->emp()->today();
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    // ---- company settings -------------------------------------------------------------------------------------------

    /** @return array{show_position: bool, show_department: bool, show_site: bool, validity_months: ?int, allow_print: bool} */
    public function settings(int $le): array
    {
        $raw = $this->app->db()->scalar('SELECT value_json FROM eid_settings WHERE legal_entity_id = ?', [$le]);
        $v = is_string($raw) ? (array) json_decode($raw, true) : [];
        return ['show_position' => (bool) ($v['show_position'] ?? true), 'show_department' => (bool) ($v['show_department'] ?? true), 'show_site' => (bool) ($v['show_site'] ?? true),
            'validity_months' => isset($v['validity_months']) && $v['validity_months'] !== null ? (int) $v['validity_months'] : null, 'allow_print' => (bool) ($v['allow_print'] ?? false)];
    }

    /** @param array<string, mixed> $in */
    public function saveSettings(Authorizer $az, int $le, array $in): void
    {
        if (!$az->scope('eid.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $months = trim((string) ($in['validity_months'] ?? ''));
        if ($months !== '' && (!ctype_digit($months) || (int) $months < 1 || (int) $months > 120)) {
            throw new ValidationException(['validity_months' => 'eid.error.validity']);
        }
        $v = ['show_position' => ($in['show_position'] ?? '') === '1', 'show_department' => ($in['show_department'] ?? '') === '1', 'show_site' => ($in['show_site'] ?? '') === '1',
            'validity_months' => $months === '' ? null : (int) $months, 'allow_print' => ($in['allow_print'] ?? '') === '1'];
        $old = $this->settings($le);
        $this->app->db()->run(
            'INSERT INTO eid_settings (legal_entity_id, value_json, updated_at, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, json_encode($v), $this->now(), $az->userId]
        );
        $this->app->audit()->record('eid.settings_changed', 'success', 'legal_entity', $le, $le, null, ['from' => $old, 'to' => $v]);
    }

    // ---- cards ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null the employee's current card (active / suspended / lost) */
    public function current(int $employeeId): ?array
    {
        return $this->app->db()->one("SELECT * FROM eid_cards WHERE employee_id = ? AND status IN ('active','suspended','lost') ORDER BY seq DESC LIMIT 1", [$employeeId]);
    }

    /** @return array<string, mixed>|null the newest card of any status */
    public function latest(int $employeeId): ?array
    {
        return $this->app->db()->one('SELECT * FROM eid_cards WHERE employee_id = ? ORDER BY seq DESC LIMIT 1', [$employeeId]);
    }

    /**
     * active · suspended · lost · revoked · replaced · expired · former — what the card is worth today.
     *
     * @param array<string, mixed> $card
     */
    public function effective(array $card): string
    {
        $status = (string) $card['status'];
        if ($status !== 'active') {
            return $status;
        }
        $e = $this->app->db()->one('SELECT status FROM employees WHERE id = ?', [$card['employee_id']]);
        if ($e === null || $e['status'] === 'former') {
            return 'former';
        }
        if ($card['expires_on'] !== null && (string) $card['expires_on'] < $this->today()) {
            return 'expired';
        }
        return 'active';
    }

    /** @param array<string, mixed>|null $job */
    public function canManage(Authorizer $az, ?array $job): bool
    {
        return $job !== null && EmployeeService::covers($az->scope('eid.manage'), $job);
    }

    /** @return array<string, mixed> employee (+ job) the manager may handle */
    private function managedEmployee(Authorizer $az, string $employeePid): array
    {
        $id = $this->app->db()->scalar('SELECT id FROM employees WHERE public_id = ?', [$employeePid]);
        $e = $id !== null ? $this->emp()->byId((int) $id) : null;
        if ($e === null || !$this->canManage($az, $e['job'])) {
            throw HttpException::notFound();
        }
        return $e;
    }

    /** @return array<string, mixed> card + employee the manager may handle */
    private function managedCard(Authorizer $az, string $cardPid): array
    {
        $c = $this->app->db()->one('SELECT * FROM eid_cards WHERE public_id = ?', [$cardPid]);
        $e = $c !== null ? $this->emp()->byId((int) $c['employee_id']) : null;
        if ($c === null || $e === null || !$this->canManage($az, $e['job'] ?? $this->emp()->employment((int) $e['id'], (string) substr((string) $c['issued_at'], 0, 10)))) {
            throw HttpException::notFound();
        }
        return ['card' => $c, 'employee' => $e];
    }

    public function issue(Authorizer $az, string $employeePid): string
    {
        $e = $this->managedEmployee($az, $employeePid);
        if ($e['status'] === 'former') {
            throw new BusinessException('eid.error.former', [], 409);
        }
        if ($this->current((int) $e['id']) !== null) {
            throw new BusinessException('eid.error.has_card', [], 409);
        }
        return $this->create($e, $az->userId, null, 'issue', null);
    }

    /**
     * @param array<string, mixed> $e employee + job
     * @param array<string, mixed>|null $carry previous card (photo carried over)
     */
    private function create(array $e, int $actorId, ?array $carry, string $event, ?string $reason): string
    {
        $db = $this->app->db();
        $le = (int) $e['job']['legal_entity_id'];
        $seq = (int) $db->scalar('SELECT COALESCE(MAX(seq), 0) + 1 FROM eid_cards WHERE employee_id = ?', [$e['id']]);
        $set = $this->settings($le);
        $expires = $set['validity_months'] !== null ? EmployeeService::ymd($this->today())->modify('+' . $set['validity_months'] . ' months')->format('Y-m-d') : null;
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->run(
            "INSERT INTO eid_cards (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, seq, card_no, qr_id, status, issued_at, expires_on, photo_document_pid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)",
            [$pid, $now, $actorId, $now, $actorId, $e['id'], $le, $seq, $e['employee_code'] . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT), self::randomId(), $now, $expires, $carry['photo_document_pid'] ?? null]
        );
        $this->event((int) $db->pdo()->lastInsertId(), $event, $reason, $actorId);
        $this->app->audit()->record('eid.card_' . $event, 'success', 'eid_card', $pid, $le, $reason, ['employee' => $e['employee_code'], 'seq' => $seq, 'expires_on' => $expires]);
        return $pid;
    }

    /** Issue a card to every active employee in the manager's scope of the company who has none. */
    public function issueMissing(Authorizer $az, int $le): int
    {
        if ($az->scope('eid.manage')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $n = 0;
        foreach (
            $this->app->db()->all(
                "SELECT e.public_id FROM employees e WHERE e.status IN ('active','on_leave') AND NOT EXISTS (SELECT 1 FROM eid_cards c WHERE c.employee_id = e.id AND c.status IN ('active','suspended','lost'))
               AND EXISTS (SELECT 1 FROM employment_history h WHERE h.employee_id = e.id AND h.legal_entity_id = ?) ORDER BY e.employee_code LIMIT 1000",
                [$le]
            ) as $row
        ) {
            $e = $this->emp()->byId((int) $this->app->db()->scalar('SELECT id FROM employees WHERE public_id = ?', [$row['public_id']]));
            if ($e !== null && $e['job'] !== null && (int) $e['job']['legal_entity_id'] === $le && $this->canManage($az, $e['job'])) {
                $this->create($e, $az->userId, null, 'issue', null);
                $n++;
            }
        }
        return $n;
    }

    /** HR: suspend · resume · lost · revoke (reason ≥ 3 characters). */
    public function setStatus(Authorizer $az, string $cardPid, string $action, string $reason): void
    {
        $m = $this->managedCard($az, $cardPid);
        $this->transition($m['card'], $action, $reason, $az->userId);
    }

    /** The employee reports their own card lost (stops the card and wallet spending at once). */
    public function reportLost(Authorizer $az, string $reason): void
    {
        $me = $this->emp()->forUser($az->userId);
        $card = $me !== null ? $this->current((int) $me['id']) : null;
        if ($card === null || $card['status'] !== 'active') {
            throw new BusinessException('eid.error.state', [], 409);
        }
        $this->transition($card, 'lost', $reason !== '' ? $reason : 'reported by the employee', $az->userId);
        foreach ($this->app->db()->all("SELECT DISTINCT u.id FROM users u JOIN user_role_assignments a ON a.user_id = u.id JOIN roles r ON r.id = a.role_id WHERE r.code = 'HRA' AND u.status = 'active' LIMIT 20") as $u) {
            $this->app->notifier()->notify((int) $u['id'], 'eid.lost', 'eid.notify.lost', ['code' => (string) $me['employee_code']], '/eid/admin', 'eid.lost:' . $card['public_id'] . ':' . $u['id']);
        }
    }

    /** @param array<string, mixed> $card */
    private function transition(array $card, string $action, string $reason, int $actorId): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $allowed = [
            'suspend' => ['active'], 'resume' => ['suspended'], 'lost' => ['active', 'suspended'], 'revoke' => ['active', 'suspended', 'lost'],
        ];
        $to = ['suspend' => 'suspended', 'resume' => 'active', 'lost' => 'lost', 'revoke' => 'revoked'][$action] ?? null;
        if ($to === null || !in_array($card['status'], $allowed[$action], true)) {
            throw new BusinessException('eid.error.state', [], 409);
        }
        $n = $this->app->db()->run(
            'UPDATE eid_cards SET status = ?, status_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = ?',
            [$to, mb_substr($reason, 0, 300), $this->now(), $actorId, $card['id'], $card['status']]
        )->rowCount();
        if ($n !== 1) {
            throw new BusinessException('eid.error.state', [], 409);
        }
        $this->event((int) $card['id'], $action, $reason, $actorId);
        $this->app->audit()->record('eid.card_' . $action, 'success', 'eid_card', (string) $card['public_id'], (int) $card['legal_entity_id'], $reason, ['from' => $card['status'], 'to' => $to]);
    }

    /** New card (new number + QR id); the old one becomes "replaced" and stops working. */
    public function reissue(Authorizer $az, string $cardPid, string $reason): string
    {
        $m = $this->managedCard($az, $cardPid);
        $card = $m['card'];
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        if (!in_array($card['status'], self::STATUS_CURRENT, true) || $m['employee']['status'] === 'former' || $m['employee']['job'] === null) {
            throw new BusinessException('eid.error.state', [], 409);
        }
        return $this->app->db()->transactional(function () use ($az, $card, $m, $reason): string {
            $this->app->db()->run("UPDATE eid_cards SET status = 'replaced', status_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [mb_substr(trim($reason), 0, 300), $this->now(), $az->userId, $card['id']]);
            $this->event((int) $card['id'], 'replaced', trim($reason), $az->userId);
            return $this->create($m['employee'], $az->userId, $card, 'reissue', trim($reason));
        });
    }

    public function uploadPhoto(Authorizer $az, string $cardPid, ?UploadedFile $file): void
    {
        $m = $this->managedCard($az, $cardPid);
        if ($file === null || !$file->ok() || $file->size === 0) {
            throw new ValidationException(['photo' => 'file.error.missing']);
        }
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'eid.photo', $file->bytes(), $file->safeName(), [
                'title' => 'e-ID photo ' . $m['employee']['employee_code'], 'legal_entity_id' => (int) $m['card']['legal_entity_id'],
            ], 'upload', true);
        } catch (ValidationException $ex) {
            throw new ValidationException(['photo' => array_values($ex->errors)[0] ?? 'file.error.type_not_allowed']);
        }
        $this->app->db()->run('UPDATE eid_cards SET photo_document_pid = ?, updated_at = ?, updated_by = ? WHERE id = ?', [(string) $stored['document'], $this->now(), $az->userId, $m['card']['id']]);
        $this->event((int) $m['card']['id'], 'photo', null, $az->userId);
        $this->app->audit()->record('eid.photo_changed', 'success', 'eid_card', $cardPid, (int) $m['card']['legal_entity_id']);
    }

    /**
     * The card photo for its owner, a card manager, or a verifier covering the employee (verify page).
     *
     * @return array{bytes: string, mime: string}
     */
    public function photo(Authorizer $az, string $cardPid): array
    {
        $c = $this->app->db()->one('SELECT * FROM eid_cards WHERE public_id = ?', [$cardPid]);
        if ($c === null || $c['photo_document_pid'] === null) {
            throw HttpException::notFound();
        }
        $me = $this->emp()->forUser($az->userId);
        $job = $this->emp()->employment((int) $c['employee_id'], $this->today());
        $ok = ($me !== null && (int) $me['id'] === (int) $c['employee_id']) || $this->canManage($az, $job) || ($job !== null && EmployeeService::covers($az->scope('eid.verify'), $job));
        if (!$ok) {
            throw HttpException::notFound();
        }
        $d = $this->app->documents()->moduleRead((string) $c['photo_document_pid'], 'eid', $az->userId, 'e-ID photo', false);
        return ['bytes' => $d['bytes'], 'mime' => $d['mime']];
    }

    private function event(int $cardId, string $event, ?string $reason, ?int $actorId): void
    {
        $this->app->db()->run('INSERT INTO eid_card_events (card_id, event, reason, actor_id, created_at) VALUES (?, ?, ?, ?, ?)', [$cardId, $event, $reason !== null ? mb_substr($reason, 0, 300) : null, $actorId, $this->now()]);
    }

    /** @return list<array<string, mixed>> */
    public function events(int $cardId): array
    {
        return $this->app->db()->all('SELECT ev.*, u.display_name AS actor_name FROM eid_card_events ev LEFT JOIN users u ON u.id = ev.actor_id WHERE ev.card_id = ? ORDER BY ev.id DESC LIMIT 50', [$cardId]);
    }

    // ---- face of the card -------------------------------------------------------------------------------------------

    /**
     * What the card shows, read from the master now (nothing copied onto the card row).
     *
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    public function face(array $card): array
    {
        $db = $this->app->db();
        $e = $this->emp()->byId((int) $card['employee_id']);
        $job = $e['job'] ?? null;
        $le = (int) $card['legal_entity_id'];
        $set = $this->settings($le);
        $loc = $this->app->translator()->locale();
        $tr = new Translations($db);
        $name = static fn (string $type, ?int $id): ?string => $id === null ? null : (Translations::pick($tr->many($type, [$id])[$id] ?? [], $loc) ?: null);
        return [
            'name' => $e !== null ? $this->emp()->displayName($e) : '—', 'name_en' => (string) ($e['name_en'] ?? ''), 'code' => (string) ($e['employee_code'] ?? ''),
            'position' => $set['show_position'] ? ($job['position_title'] ?? null) : null,
            'department' => $set['show_department'] && $job !== null && $job['department_id'] !== null ? $name('department', (int) $job['department_id']) : null,
            'site' => $set['show_site'] && $job !== null && $job['site_id'] !== null ? $name('site', (int) $job['site_id']) : null,
            'company' => $name('legal_entity', $le) ?? (string) $db->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]),
            'card_no' => (string) $card['card_no'], 'expires_on' => $card['expires_on'], 'status' => $this->effective($card), 'has_photo' => $card['photo_document_pid'] !== null,
            'card_pid' => (string) $card['public_id'], 'allow_print' => $set['allow_print'],
        ];
    }

    private function key(): string
    {
        return $this->app->crypto()->deriveKey('blue.eid.qr.v1');
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function randomId(): string
    {
        return self::b64(random_bytes(16));
    }

    private function mac(string $qrId, string $window): string
    {
        return self::b64(substr(hash_hmac('sha256', $qrId . '.' . $window, $this->key(), true), 0, 12));
    }

    /**
     * The QR for the card face: a URL with a 30-second window (printed = fixed "p" code).
     *
     * @param array<string, mixed> $card
     * @return array{url: string, svg: string, refresh: int}
     */
    public function qr(array $card, string $origin, bool $printed = false): array
    {
        $w = $printed ? 'p' : (string) intdiv($this->app->clock->now()->getTimestamp(), self::WINDOW);
        $url = rtrim($origin, '/') . '/eid/v/' . $card['qr_id'] . '.' . $w . '.' . $this->mac((string) $card['qr_id'], $w);
        $sym = QrEncoder::encode($url, 'M');
        $left = self::WINDOW - ($this->app->clock->now()->getTimestamp() % self::WINDOW);
        return ['url' => $url, 'svg' => QrRender::svg($sym['modules'], 6, null, $this->app->translator()->get('eid.qr_label')), 'refresh' => max(5, $left)];
    }

    /**
     * Check a scanned token. Never says more than needed: unknown / bad tokens and cards outside the verifier's scope
     * look the same to the scanner except "not allowed".
     *
     * @return array{result: string, card?: array<string, mixed>, face?: array<string, mixed>}
     */
    public function verify(Authorizer $az, string $token): array
    {
        if ($az->scope('eid.verify')->isEmpty()) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $result = 'unknown';
        $card = null;
        if (preg_match('/^([A-Za-z0-9_-]{22})\.(p|\d{1,12})\.([A-Za-z0-9_-]{16})$/', $token, $m) === 1) {
            $card = $db->one('SELECT * FROM eid_cards WHERE qr_id = ?', [$m[1]]);
            if ($card !== null && !hash_equals($this->mac($m[1], $m[2]), $m[3])) {
                $card = null;
            }
            if ($card !== null) {
                $job = $this->emp()->employment((int) $card['employee_id'], $this->today()) ?? ['legal_entity_id' => $card['legal_entity_id'], 'site_id' => null, 'department_id' => null];
                $now = intdiv($this->app->clock->now()->getTimestamp(), self::WINDOW);
                if (!EmployeeService::covers($az->scope('eid.verify'), $job)) {
                    $result = 'not_allowed';
                } elseif ($m[2] === 'p' && !$this->settings((int) $card['legal_entity_id'])['allow_print']) {
                    $result = 'expired_qr';
                } elseif ($m[2] !== 'p' && ((int) $m[2] > $now + 1 || (int) $m[2] < $now - self::WINDOWS_BACK)) {
                    $result = 'expired_qr';
                } else {
                    $result = $this->effective($card) === 'active' ? 'valid' : 'not_valid';
                }
            }
        }
        $db->run(
            'INSERT INTO eid_verifications (card_id, legal_entity_id, result, verifier_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$card['id'] ?? null, $card['legal_entity_id'] ?? null, $result, $az->userId, $this->now()]
        );
        if (in_array($result, ['valid', 'not_valid'], true) && $card !== null) {
            return ['result' => $result, 'card' => $card, 'face' => $this->face($card)];
        }
        return ['result' => $result];
    }

    // ---- lists ---------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> employees of the company in the manager's scope with their current/latest card */
    public function roster(Authorizer $az, int $le, string $q = ''): array
    {
        $out = [];
        $like = '%' . $q . '%';
        foreach (
            $this->app->db()->all(
                "SELECT e.id, e.public_id, e.employee_code, e.name_th, e.name_en, e.preferred_name, e.status AS emp_status FROM employees e
              WHERE EXISTS (SELECT 1 FROM employment_history h WHERE h.employee_id = e.id AND h.legal_entity_id = ?)" . ($q !== '' ? ' AND (e.employee_code LIKE ? OR e.name_th LIKE ? OR e.name_en LIKE ?)' : '') . ' ORDER BY e.employee_code LIMIT 500',
                $q !== '' ? [$le, $like, $like, $like] : [$le]
            ) as $e
        ) {
            $job = $this->emp()->employment((int) $e['id'], $this->today());
            if ($job === null || (int) $job['legal_entity_id'] !== $le || !$this->canManage($az, $job)) {
                continue;
            }
            $card = $this->latest((int) $e['id']);
            $out[] = $e + ['card' => $card, 'effective' => $card !== null ? $this->effective($card) : null, 'display' => $this->emp()->displayName($e)];
        }
        return $out;
    }

    // ---- jobs ---------------------------------------------------------------------------------------------------------

    public static function housekeepingJob(App $app): int
    {
        return self::of($app)->revokeFormer() + WalletService::of($app)->reconcileCheck();
    }

    /** Cards of employees who have left are revoked (they already show as not valid). */
    public function revokeFormer(): int
    {
        $n = 0;
        foreach ($this->app->db()->all("SELECT c.* FROM eid_cards c JOIN employees e ON e.id = c.employee_id WHERE e.status = 'former' AND c.status IN ('active','suspended','lost')") as $c) {
            $this->app->db()->run("UPDATE eid_cards SET status = 'revoked', status_reason = 'employment ended', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $c['id']]);
            $this->event((int) $c['id'], 'revoke', 'employment ended', null);
            $this->app->audit()->record('eid.card_revoke', 'success', 'eid_card', (string) $c['public_id'], (int) $c['legal_entity_id'], 'employment ended');
            $n++;
        }
        return $n;
    }
}
