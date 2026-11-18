<?php

declare(strict_types=1);

namespace Blue\Modules\Complaints;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Auth\LoginThrottle;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Org\Translations;
use Blue\Core\Support\FrozenClock;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Privacy\PrivacyCenterService;

/**
 * M16 anonymous complaints (R6-3 · 0.44.0 · DEC-111).
 *
 *  submit     an employee of a company whose intake is open (published complainant notice + a person with legal knowledge
 *             confirmed the complainant-protection policy + at least two handlers) sends a category, subject, details and
 *             up to 3 pictures. The case keeps NO sender: no user id, a submission time rounded to the local day (also in
 *             the public id), the SHA-256 of a 100-bit tracking code shown once, pictures re-drawn so their metadata
 *             (camera, place, time) is gone, generic file names, no audit event and no immediate notification (handlers
 *             hear of new cases and replies the next day, so the alert's time cannot be matched to a sign-in). The daily
 *             limit counts per keyed account hash for today only. Optionally the code is kept in an encrypted "my
 *             complaints" list (keyed account tag, no times) — off by default and explained.
 *  follow     with the code (signed in, or on the public page /speak-up/track from any device): status, the anonymous
 *             message box, answer questions, accept the outcome with a rating, or reopen with a reason. Wrong codes are
 *             rate-limited per IP hash; successful look-ups are never logged.
 *  handle     route "normal" → complaints.manage of the company; route "alternate" (the complaint is about a handler or
 *             management) → complaints.alternate only, chosen by the sender. A manager triages (urgency), assigns an
 *             investigator who must declare no conflict of interest before the case opens for them (or decline), rejects
 *             as duplicate / out of scope with a reason, or closes a resolved case (not the investigator). The investigator
 *             asks questions (Action required), keeps restricted notes (never shown to the sender) and resolves with an
 *             outcome, a finding and a score. Managers see their route's cases; investigators only cases assigned to them.
 *  job        hourly: next-day digest · first-response / resolution SLAs when the company sets them (no defaults; one
 *             notice each) · retention after closing when the company sets it (no default; texts, notes and files removed,
 *             counts and ratings stay) · yesterday's daily-limit counters deleted.
 *  figures    counts by category, status and month, average days and ratings — any number below the company's minimum
 *             group (default 5) is shown as "fewer than n".
 * A lost code cannot be recovered (nothing links the case to a person) — the sender can send a new complaint naming the
 * old reference. Statuses: submitted → triage → investigating ⇄ action_required → resolved → closed · duplicate ·
 * out_of_scope · reopened (event; back to investigating / triage).
 */
final class ComplaintService
{
    public const CATEGORIES = ['harassment', 'discrimination', 'misconduct', 'fraud', 'safety', 'retaliation', 'policy', 'other'];
    public const OPEN = ['submitted', 'triage', 'investigating', 'action_required'];
    public const FINDINGS = ['substantiated', 'partly', 'not_substantiated', 'inconclusive'];
    public const MAX_FILES = 3;
    public const MAX_REOPEN = 3;
    private const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const TRACK_FAILS = 10;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- time --------------------------------------------------------------------------------------------------------------

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

    /** The start of today (local) in UTC — what anything the sender does is stamped with. */
    public function dayStamp(): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $this->today(), $this->tz());
        return ($d === false ? $this->app->clock->now() : $d)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** A public id whose time part is the start of the day, so it does not tell when the case was sent. */
    private function dayUlid(): string
    {
        return Ulid::generate(new FrozenClock(str_replace(' ', 'T', substr($this->dayStamp(), 0, 19)) . 'Z'));
    }

    // ---- company -----------------------------------------------------------------------------------------------------------

    public function companyName(int $le): string
    {
        $names = (new Translations($this->app->db()))->many('legal_entity', [$le]);
        $n = isset($names[$le]) ? Translations::pick($names[$le], 'th') : '';
        return $n !== '' && $n !== '—' ? $n : (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
    }

    public function employerOf(int $userId): ?int
    {
        $me = EmployeeService::of($this->app)->forUser($userId);
        return $me === null || $me['status'] === 'former' || $me['job'] === null ? null : (int) $me['job']['legal_entity_id'];
    }

    public function noticeUrl(int $le): ?string
    {
        return PrivacyCenterService::publicUrl($this->app, $le, 'complainant');
    }

    /** @return list<array{id: int, code: string, name: string}> */
    public function companies(Authorizer $az, string ...$perms): array
    {
        $out = [];
        foreach ($this->app->db()->all('SELECT id, code FROM legal_entities ORDER BY code') as $r) {
            foreach ($perms as $p) {
                if ($az->scope($p)->coversLegalEntity((int) $r['id'])) {
                    $out[] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => $this->companyName((int) $r['id'])];
                    break;
                }
            }
        }
        return $out;
    }

    /** @return list<int> active users holding $perm for the company */
    public function staff(int $le, string $perm): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN role_permissions p ON p.role_id = a.role_id WHERE a.status = 'active' AND u.status = 'active' AND p.permission_code = ?", [$perm]) as $r) {
            $az = new Authorizer($this->app->db(), $this->app->clock, (int) $r['user_id']);
            if ($az->scope($perm)->coversLegalEntity($le)) {
                $out[] = (int) $r['user_id'];
            }
        }
        sort($out);
        return $out;
    }

    /**
     * @param array<int|string, mixed> $ids
     * @return array<int, string> user id → user name
     */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn ($v): bool => $v !== null))));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->app->db()->all('SELECT id, username FROM users WHERE id IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['id']] = (string) $r['username'];
        }
        return $out;
    }

    // ---- settings ----------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function settings(int $le): array
    {
        $s = $this->app->db()->one('SELECT * FROM cp_settings WHERE legal_entity_id = ?', [$le]);
        $n = static fn (string $k): ?int => $s === null || $s[$k] === null ? null : (int) $s[$k];
        return [
            'open' => $s !== null && (int) $s['intake_open'] === 1,
            'opened_at' => $s['opened_at'] ?? null,
            'legal_reviewer' => $s['legal_reviewer'] ?? null,
            'legal_reviewed_on' => $s['legal_reviewed_on'] ?? null,
            'policy_text' => (string) ($s['policy_text'] ?? ''),
            'first_response_days' => $n('first_response_days'),
            'resolve_days' => $n('resolve_days'),
            'retention_days' => $n('retention_days'),
            'min_group' => $s === null ? 5 : (int) $s['min_group'],
            'daily_limit' => $s === null ? 3 : (int) $s['daily_limit'],
        ];
    }

    private function guard(Authorizer $az, int $le, string $perm): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
    }

    private function ensureSettings(int $le, ?int $by): void
    {
        $this->app->db()->run('INSERT IGNORE INTO cp_settings (legal_entity_id, updated_at, updated_by) VALUES (?, ?, ?)', [$le, $this->stamp(), $by]);
    }

    /** @param array<string, mixed> $in */
    public function saveSettings(Authorizer $az, int $le, array $in): void
    {
        $this->guard($az, $le, 'complaints.manage');
        $int = static function (mixed $v, int $min, int $max, string $field, bool $optional = true): ?int {
            $v = trim((string) $v);
            if ($v === '' && $optional) {
                return null;
            }
            if (preg_match('/^\d{1,5}$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
                throw new ValidationException([$field => 'complaints.error.range']);
            }
            return (int) $v;
        };
        $first = $int($in['first_response_days'] ?? '', 1, 90, 'first_response_days');
        $resolve = $int($in['resolve_days'] ?? '', 1, 365, 'resolve_days');
        $ret = $int($in['retention_days'] ?? '', 30, 3650, 'retention_days');
        $min = (int) $int($in['min_group'] ?? '5', 3, 20, 'min_group', false);
        $limit = (int) $int($in['daily_limit'] ?? '3', 1, 20, 'daily_limit', false);
        $policy = trim(str_replace("\r\n", "\n", (string) ($in['policy_text'] ?? '')));
        if (mb_strlen($policy) > 5000) {
            throw new ValidationException(['policy_text' => 'complaints.error.too_long']);
        }
        $this->ensureSettings($le, $az->userId);
        $this->app->db()->run(
            'UPDATE cp_settings SET policy_text = ?, first_response_days = ?, resolve_days = ?, retention_days = ?, min_group = ?, daily_limit = ?, updated_at = ?, updated_by = ? WHERE legal_entity_id = ?',
            [$policy === '' ? null : $policy, $first, $resolve, $ret, $min, $limit, $this->stamp(), $az->userId, $le]
        );
        $this->app->audit()->record('complaints.settings_changed', 'success', 'legal_entity', $le, $le, null, ['first_response_days' => $first, 'resolve_days' => $resolve, 'retention_days' => $ret, 'min_group' => $min, 'daily_limit' => $limit]);
    }

    /**
     * What is still missing before the intake can open.
     * @return list<string> translation keys
     */
    public function readiness(int $le): array
    {
        $out = [];
        if ($this->noticeUrl($le) === null) {
            $out[] = 'complaints.ready.notice';
        }
        $handlers = array_unique(array_merge($this->staff($le, 'complaints.manage'), $this->staff($le, 'complaints.investigate')));
        if (count($handlers) < 2) {
            $out[] = 'complaints.ready.handlers';
        }
        return $out;
    }

    /** @param array<string, mixed> $in legal_checked, legal_reviewer, legal_reviewed_on */
    public function openIntake(Authorizer $az, int $le, array $in): void
    {
        $this->guard($az, $le, 'complaints.manage');
        $e = [];
        if (empty($in['legal_checked'])) {
            $e['legal_checked'] = 'complaints.error.legal_checked';
        }
        $who = trim((string) ($in['legal_reviewer'] ?? ''));
        if (mb_strlen($who) < 3 || mb_strlen($who) > 160) {
            $e['legal_reviewer'] = 'complaints.error.legal_reviewer';
        }
        $on = (string) ($in['legal_reviewed_on'] ?? '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        if ($d === false || $d->format('Y-m-d') !== $on || $on > $this->today()) {
            $e['legal_reviewed_on'] = 'complaints.error.date';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $missing = $this->readiness($le);
        if ($missing !== []) {
            throw new BusinessException($missing[0]);
        }
        $this->ensureSettings($le, $az->userId);
        $now = $this->stamp();
        $this->app->db()->run('UPDATE cp_settings SET intake_open = 1, opened_by = ?, opened_at = ?, legal_reviewer = ?, legal_reviewed_on = ?, updated_at = ?, updated_by = ? WHERE legal_entity_id = ?', [$az->userId, $now, $who, $on, $now, $az->userId, $le]);
        $this->app->audit()->record('complaints.intake_opened', 'success', 'legal_entity', $le, $le, null, ['legal_reviewed_on' => $on]);
    }

    public function closeIntake(Authorizer $az, int $le, string $reason): void
    {
        $this->guard($az, $le, 'complaints.manage');
        if (mb_strlen(trim($reason)) < 10) {
            throw new ValidationException(['close_reason' => 'complaints.error.reason10']);
        }
        $this->app->db()->run('UPDATE cp_settings SET intake_open = 0, updated_at = ?, updated_by = ? WHERE legal_entity_id = ?', [$this->stamp(), $az->userId, $le]);
        $this->app->audit()->record('complaints.intake_closed', 'success', 'legal_entity', $le, $le, mb_substr(trim($reason), 0, 255));
    }

    public function alternateAvailable(int $le): bool
    {
        return $this->staff($le, 'complaints.alternate') !== [];
    }

    // ---- tracking codes ----------------------------------------------------------------------------------------------------

    /** 100 random bits as 20 Crockford base-32 characters in groups of four. */
    private function newCode(): string
    {
        $bytes = random_bytes(20);
        $c = '';
        for ($i = 0; $i < 20; $i++) {
            $c .= self::CODE_ALPHABET[ord($bytes[$i]) & 31];
        }
        return implode('-', str_split($c, 4));
    }

    /** Normalise what a person typed (case, spaces, dashes, O/I/L look-alikes); null = not a code. */
    public static function normalizeCode(string $code): ?string
    {
        $c = strtr(strtoupper((string) preg_replace('/[\s\-]+/', '', $code)), ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{20}$/', $c) !== 1) {
            return null;
        }
        return implode('-', str_split($c, 4));
    }

    private static function hashCode(string $normalized): string
    {
        return hash('sha256', 'cp|' . $normalized);
    }

    /** @return array<string, mixed>|null */
    public function byCode(string $code): ?array
    {
        $n = self::normalizeCode($code);
        return $n === null ? null : $this->app->db()->one('SELECT * FROM cp_cases WHERE track_hash = ?', [self::hashCode($n)]);
    }

    /**
     * A tracking look-up. Wrong codes count against the IP (keyed hash); right ones are not recorded anywhere.
     * @return array<string, mixed>|null
     */
    public function track(string $code, string $ip): ?array
    {
        $t = new LoginThrottle($this->app->db(), $this->app->clock, $this->app->crypto());
        if ($t->failures('cp.track', $ip, 15) >= self::TRACK_FAILS) {
            throw new BusinessException('complaints.error.too_many', [], 429);
        }
        $c = $this->byCode($code);
        if ($c === null) {
            $t->hit('cp.track', $ip, false);
        }
        return $c;
    }

    // ---- my list (optional, encrypted) ---------------------------------------------------------------------------------------

    private function tag(string $purpose, int $userId): string
    {
        return hash_hmac('sha256', $purpose . '|' . $userId, $this->app->crypto()->deriveKey('complaints.' . $purpose));
    }

    /** @return list<string> */
    private function vaultCodes(int $userId): array
    {
        $blob = $this->app->db()->scalar('SELECT blob_enc FROM cp_vault WHERE owner_tag = ?', [$this->tag('vault', $userId)]);
        if (!is_string($blob)) {
            return [];
        }
        try {
            $raw = base64_decode($blob, true);
            $list = $raw === false ? null : json_decode($this->app->crypto()->decrypt($raw), true);
        } catch (\Throwable) {
            return [];
        }
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** @param list<string> $codes */
    private function saveVault(int $userId, array $codes): void
    {
        $tag = $this->tag('vault', $userId);
        if ($codes === []) {
            $this->app->db()->run('DELETE FROM cp_vault WHERE owner_tag = ?', [$tag]);
            return;
        }
        $blob = base64_encode($this->app->crypto()->encrypt((string) json_encode(array_values(array_unique($codes)))));
        $this->app->db()->run('INSERT INTO cp_vault (owner_tag, blob_enc) VALUES (?, ?) ON DUPLICATE KEY UPDATE blob_enc = VALUES(blob_enc)', [$tag, $blob]);
    }

    public function remember(int $userId, string $code): void
    {
        $n = self::normalizeCode($code);
        if ($n === null || $this->byCode($n) === null) {
            throw new ValidationException(['code' => 'complaints.error.code']);
        }
        $codes = $this->vaultCodes($userId);
        $codes[] = $n;
        $this->saveVault($userId, $codes);
    }

    public function forget(int $userId, string $ref): void
    {
        $keep = [];
        foreach ($this->vaultCodes($userId) as $c) {
            $row = $this->byCode($c);
            if ($row !== null && (string) $row['ref'] === $ref) {
                continue;
            }
            $keep[] = $c;
        }
        $this->saveVault($userId, $keep);
    }

    /** @return list<array<string, mixed>> my remembered complaints with their status and the code to open them */
    public function mine(int $userId): array
    {
        $out = [];
        foreach ($this->vaultCodes($userId) as $c) {
            $row = $this->byCode($c);
            if ($row === null) {
                continue;
            }
            $last = $this->app->db()->one("SELECT MAX(created_at) AS at, SUM(side = 'staff') AS staff FROM cp_messages WHERE case_id = ?", [$row['id']]);
            $out[] = ['code' => $c, 'ref' => $row['ref'], 'category' => $row['category'], 'status' => $row['status'], 'created_at' => $row['created_at'],
                'replies' => (int) ($last['staff'] ?? 0), 'last_at' => $last['at'] ?? null];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['ref'], (string) $a['ref']));
        return $out;
    }

    // ---- submit ------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in category, title, body, route, ack, remember
     * @param list<UploadedFile> $files
     * @return array{ref: string, code: string, remembered: bool}
     */
    public function submit(Authorizer $az, array $in, array $files = []): array
    {
        if (!$az->can('complaints.submit')) {
            throw HttpException::forbidden();
        }
        $le = $this->employerOf($az->userId) ?? throw new BusinessException('complaints.error.not_employee');
        $set = $this->settings($le);
        if (!$set['open']) {
            throw new BusinessException('complaints.error.closed');
        }
        $db = $this->app->db();
        $cat = (string) ($in['category'] ?? '');
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? '')));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $route = (string) ($in['route'] ?? 'normal') === 'alternate' ? 'alternate' : 'normal';
        $e = [];
        if (!in_array($cat, self::CATEGORIES, true)) {
            $e['category'] = 'complaints.error.choice';
        }
        if (mb_strlen($title) < 5 || mb_strlen($title) > 200) {
            $e['title'] = 'complaints.error.title';
        }
        if (mb_strlen($body) < 20 || mb_strlen($body) > 8000) {
            $e['body'] = 'complaints.error.body';
        }
        if ($route === 'alternate' && !$this->alternateAvailable($le)) {
            $e['route'] = 'complaints.error.no_alternate';
        }
        if (empty($in['ack'])) {
            $e['ack'] = 'complaints.error.ack';
        }
        $pics = [];
        try {
            $pics = Evidence::scrubAll($files, self::MAX_FILES);
        } catch (ValidationException $x) {
            $e += $x->errors;
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        // daily limit: a counter per keyed account hash for today only (earlier days are deleted by the job)
        $qtag = $this->tag('quota', $az->userId);
        $used = (int) ($db->scalar('SELECT n FROM cp_quota WHERE owner_tag = ? AND day = ?', [$qtag, $this->today()]) ?? 0);
        if ($used >= $set['daily_limit']) {
            throw new BusinessException('complaints.error.limit', ['n' => (string) $set['daily_limit']], 429);
        }
        $code = $this->newCode();
        $at = $this->dayStamp();
        $pid = $this->dayUlid();
        $ref = $db->transactional(function () use ($db, $le, $pid, $at, $route, $cat, $title, $body, $code, $pics, $qtag): string {
            $db->one('SELECT id FROM legal_entities WHERE id = ? FOR UPDATE', [$le]);
            $prefix = 'CP' . $this->app->clock->now()->setTimezone($this->tz())->format('ym') . '-';
            $last = (string) ($db->scalar('SELECT MAX(ref) FROM cp_cases WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
            $ref = $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
            $db->run(
                'INSERT INTO cp_cases (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ref, route, category, title, body, track_hash) VALUES (?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $at, $at, $le, $ref, $route, $cat, $title, $body, self::hashCode($code)]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $this->storeFiles($id, null, 'reporter', $pics, $at);
            $this->event($id, 'submitted', null, null, $at);
            $db->run('INSERT INTO cp_quota (owner_tag, day, n) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1', [$qtag, $this->today()]);
            return $ref;
        });
        $remembered = false;
        if (!empty($in['remember'])) {
            try { // the case is sent either way — a failure here must never cost the person their only copy of the code
                $this->remember($az->userId, $code);
                $remembered = true;
            } catch (\Throwable $x) {
                $this->app->logger->warning('complaints_list_failed', ['error' => get_class($x)]);
            }
        }
        return ['ref' => $ref, 'code' => $code, 'remembered' => $remembered];
    }

    /** @param list<array{bytes: string, mime: string, ext: string}> $pics */
    private function storeFiles(int $caseId, ?int $messageId, string $side, array $pics, string $at): void
    {
        $n = (int) $this->app->db()->scalar('SELECT COUNT(*) FROM cp_files WHERE case_id = ?', [$caseId]);
        foreach ($pics as $p) {
            $n++;
            $this->app->db()->run(
                'INSERT INTO cp_files (case_id, message_id, side, filename, mime, size, content, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$caseId, $messageId, $side, 'evidence-' . $n . '.' . $p['ext'], $p['mime'], strlen($p['bytes']), $p['bytes'], $at]
            );
        }
    }

    private function event(int $caseId, string $event, ?int $actor, ?string $note = null, ?string $at = null): void
    {
        $this->app->db()->run(
            'INSERT INTO cp_events (case_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)',
            [$caseId, $event, $actor, $note === null ? null : mb_substr($note, 0, 500), $at ?? $this->stamp()]
        );
    }

    // ---- the sender's view (by code) -----------------------------------------------------------------------------------------

    /**
     * What the sender sees: never staff names, notes, investigator or staff ratings.
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    public function reporterView(array $c): array
    {
        $db = $this->app->db();
        $msgs = $db->all('SELECT id, side, body, asks_reply, created_at FROM cp_messages WHERE case_id = ? ORDER BY id', [$c['id']]);
        $files = $db->all('SELECT id, message_id, filename, side FROM cp_files WHERE case_id = ? AND content IS NOT NULL ORDER BY id', [$c['id']]);
        return [
            'ref' => $c['ref'], 'category' => $c['category'], 'status' => $c['status'], 'route' => $c['route'], 'title' => $c['title'], 'body' => $c['body'],
            'created_at' => $c['created_at'], 'outcome' => in_array($c['status'], ['resolved', 'closed'], true) ? $c['outcome'] : null,
            'closed_reason' => in_array($c['status'], ['duplicate', 'out_of_scope', 'closed'], true) ? $c['closed_reason'] : null,
            'rating' => $c['reporter_rating'], 'messages' => $msgs, 'files' => $files, 'purged' => $c['purged_at'] !== null,
            'can_reply' => in_array($c['status'], self::OPEN, true), 'can_rate' => $c['status'] === 'resolved',
            'can_reopen' => $c['status'] === 'resolved' && (int) $c['reopen_count'] < self::MAX_REOPEN,
        ];
    }

    /**
     * @param array<string, mixed> $in
     * @param list<UploadedFile> $files
     * @return string what happened (flash key suffix)
     */
    public function reporterAct(string $code, string $action, array $in, array $files = []): string
    {
        $c = $this->byCode($code) ?? throw new ValidationException(['code' => 'complaints.error.code']);
        $db = $this->app->db();
        $id = (int) $c['id'];
        $at = $this->dayStamp();
        if ($action === 'reply') {
            if (!in_array($c['status'], self::OPEN, true)) {
                throw new BusinessException('complaints.error.state');
            }
            $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
            $e = [];
            if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) {
                $e['body'] = 'complaints.error.message';
            }
            $pics = [];
            try {
                $pics = Evidence::scrubAll($files, self::MAX_FILES);
            } catch (ValidationException $x) {
                $e += $x->errors;
            }
            if ($e !== []) {
                throw new ValidationException($e);
            }
            $lastMine = $db->one('SELECT side, body FROM cp_messages WHERE case_id = ? ORDER BY id DESC LIMIT 1', [$id]);
            if ($lastMine !== null && $lastMine['side'] === 'reporter' && (string) $lastMine['body'] === $body && $pics === []) {
                return 'reply'; // the same message sent again (page reloaded) — keep one
            }
            $db->transactional(function () use ($db, $id, $body, $pics, $at, $c): void {
                $db->run("INSERT INTO cp_messages (case_id, side, author_id, body, created_at) VALUES (?, 'reporter', NULL, ?, ?)", [$id, $body, $at]);
                $this->storeFiles($id, (int) $db->pdo()->lastInsertId(), 'reporter', $pics, $at);
                if ($c['status'] === 'action_required') {
                    $db->run("UPDATE cp_cases SET status = 'investigating', row_version = row_version + 1 WHERE id = ?", [$id]);
                    $this->event($id, 'answered', null, null, $at);
                }
            });
            return 'reply';
        }
        if ($action === 'accept') {
            if ($c['status'] !== 'resolved') {
                throw new BusinessException('complaints.error.state');
            }
            $rating = (int) ($in['rating'] ?? 0);
            $comment = trim((string) ($in['comment'] ?? ''));
            if ($rating < 1 || $rating > 5) {
                throw new ValidationException(['rating' => 'complaints.error.rating']);
            }
            if (mb_strlen($comment) > 1000) {
                throw new ValidationException(['comment' => 'complaints.error.too_long']);
            }
            $db->run("UPDATE cp_cases SET status = 'closed', closed_at = ?, reporter_rating = ?, reporter_comment = ?, row_version = row_version + 1 WHERE id = ? AND status = 'resolved'", [$at, $rating, $comment === '' ? null : $comment, $id]);
            $this->event($id, 'accepted', null, null, $at);
            return 'accept';
        }
        if ($action === 'reopen') {
            if ($c['status'] !== 'resolved' || (int) $c['reopen_count'] >= self::MAX_REOPEN) {
                throw new BusinessException('complaints.error.state');
            }
            $reason = trim(str_replace("\r\n", "\n", (string) ($in['reason'] ?? '')));
            if (mb_strlen($reason) < 10 || mb_strlen($reason) > 3000) {
                throw new ValidationException(['reason' => 'complaints.error.reason10']);
            }
            $to = $c['investigator_id'] !== null && $c['accepted_at'] !== null ? 'investigating' : 'triage';
            $db->transactional(function () use ($db, $id, $reason, $at, $to): void {
                $db->run("INSERT INTO cp_messages (case_id, side, author_id, body, created_at) VALUES (?, 'reporter', NULL, ?, ?)", [$id, $reason, $at]);
                $db->run("UPDATE cp_cases SET status = ?, resolved_at = NULL, reopen_count = reopen_count + 1, row_version = row_version + 1 WHERE id = ?", [$to, $id]);
                $this->event($id, 'reopened', null, null, $at);
            });
            return 'reopen';
        }
        throw HttpException::notFound();
    }

    // ---- handlers ----------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $c
     * @return array{manage: bool, investigator: bool, any: bool}
     */
    public function roles(Authorizer $az, array $c): array
    {
        $le = (int) $c['legal_entity_id'];
        $manage = $az->scope($c['route'] === 'alternate' ? 'complaints.alternate' : 'complaints.manage')->coversLegalEntity($le);
        $inv = $c['investigator_id'] !== null && (int) $c['investigator_id'] === $az->userId && $az->scope('complaints.investigate')->coversLegalEntity($le);
        return ['manage' => $manage, 'investigator' => $inv, 'any' => $manage || $inv];
    }

    /** @return array{0: array<string, mixed>, 1: array{manage: bool, investigator: bool, any: bool}} */
    private function guarded(Authorizer $az, string $pid): array
    {
        $c = $this->app->db()->one('SELECT * FROM cp_cases WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $r = $this->roles($az, $c);
        // an investigator who has not declared "no conflict of interest" sees only the summary needed to decide
        if (!$r['any']) {
            throw HttpException::notFound();
        }
        return [$c, $r];
    }

    /**
     * Cases this person may handle in a company: their route's cases (manage / alternate) and cases assigned to them.
     * @return list<array<string, mixed>>
     */
    public function inbox(Authorizer $az, int $le, string $filter = 'open'): array
    {
        $routes = [];
        if ($az->scope('complaints.manage')->coversLegalEntity($le)) {
            $routes[] = "'normal'";
        }
        if ($az->scope('complaints.alternate')->coversLegalEntity($le)) {
            $routes[] = "'alternate'";
        }
        $where = '(' . ($routes === [] ? '0' : 'route IN (' . implode(',', $routes) . ')') . ' OR investigator_id = ' . $az->userId . ')';
        $status = match ($filter) {
            'done' => "status IN ('resolved','closed','duplicate','out_of_scope')",
            'all' => '1',
            default => "status IN ('submitted','triage','investigating','action_required')",
        };
        $rows = $this->app->db()->all(
            "SELECT id, public_id, ref, route, category, urgency, status, investigator_id, accepted_at, created_at, first_response_at, purged_at,
                (SELECT MAX(id) FROM cp_messages m WHERE m.case_id = cp_cases.id AND m.side = 'reporter') AS last_reporter,
                (SELECT MAX(id) FROM cp_messages m WHERE m.case_id = cp_cases.id AND m.side = 'staff') AS last_staff
             FROM cp_cases WHERE legal_entity_id = ? AND {$where} AND {$status} ORDER BY (urgency = 'high') DESC, id DESC LIMIT 300",
            [$le]
        );
        $names = $this->names(array_column($rows, 'investigator_id'));
        $set = $this->settings($le);
        foreach ($rows as &$r) {
            $r['investigator'] = $r['investigator_id'] === null ? null : ($names[(int) $r['investigator_id']] ?? null);
            $r['waiting'] = $r['last_reporter'] !== null && (int) $r['last_reporter'] > (int) ($r['last_staff'] ?? 0);
            $r['due'] = $this->due($r, $set);
        }
        unset($r);
        return $rows;
    }

    /**
     * First-response / resolution dates when the company sets them.
     * @param array<string, mixed> $c
     * @param array<string, mixed> $set
     * @return array{response: ?string, resolve: ?string, late: bool}
     */
    public function due(array $c, array $set): array
    {
        $from = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $c['created_at'], new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr((string) $c['created_at'], 0, 19), new DateTimeZone('UTC'));
        $out = ['response' => null, 'resolve' => null, 'late' => false];
        if ($from === false) {
            return $out;
        }
        $now = $this->app->clock->now();
        if ($set['first_response_days'] !== null && $c['first_response_at'] === null && in_array($c['status'], ['submitted', 'triage'], true)) {
            $d = $from->modify('+' . (int) $set['first_response_days'] . ' days');
            $out['response'] = $d->format('Y-m-d H:i:s.u');
            $out['late'] = $d < $now;
        }
        if ($set['resolve_days'] !== null && in_array($c['status'], self::OPEN, true)) {
            $d = $from->modify('+' . (int) $set['resolve_days'] . ' days');
            $out['resolve'] = $d->format('Y-m-d H:i:s.u');
            $out['late'] = $out['late'] || $d < $now;
        }
        return $out;
    }

    /** @return array<string, mixed> the case as a handler may see it (no tracking hash) */
    public function caseFor(Authorizer $az, string $pid): array
    {
        [$c, $r] = $this->guarded($az, $pid);
        $db = $this->app->db();
        $id = (int) $c['id'];
        unset($c['track_hash']);
        $c['roles'] = $r;
        // assigned but not yet accepted: the investigator decides on the summary only (category, subject)
        $c['summary_only'] = !$r['manage'] && $r['investigator'] && $c['accepted_at'] === null;
        $c['messages'] = $c['summary_only'] ? [] : $db->all('SELECT id, side, author_id, body, asks_reply, created_at FROM cp_messages WHERE case_id = ? ORDER BY id', [$id]);
        $c['notes'] = $c['summary_only'] ? [] : $db->all('SELECT id, author_id, body, created_at FROM cp_notes WHERE case_id = ? ORDER BY id', [$id]);
        $c['files'] = $c['summary_only'] ? [] : $db->all('SELECT id, message_id, side, filename, size FROM cp_files WHERE case_id = ? AND content IS NOT NULL ORDER BY id', [$id]);
        $c['events'] = $db->all('SELECT event, actor_id, note, created_at FROM cp_events WHERE case_id = ? ORDER BY id', [$id]);
        $c['names'] = $this->names(array_merge(array_column($c['messages'], 'author_id'), array_column($c['notes'], 'author_id'), array_column($c['events'], 'actor_id'), [$c['investigator_id']]));
        $c['duplicate_ref'] = $c['duplicate_of'] === null ? null : $db->scalar('SELECT ref FROM cp_cases WHERE id = ?', [$c['duplicate_of']]);
        $c['candidates'] = $r['manage'] ? $this->candidates((int) $c['legal_entity_id'], (string) $c['route']) : [];
        $c['due'] = $this->due($c, $this->settings((int) $c['legal_entity_id']));
        if ($c['summary_only']) {
            $c['body'] = null;
        }
        return $c;
    }

    /** @return array<int, string> people who may investigate a case of this route */
    public function candidates(int $le, string $route): array
    {
        $ids = $route === 'alternate'
            ? array_values(array_intersect($this->staff($le, 'complaints.alternate'), $this->staff($le, 'complaints.investigate')))
            : $this->staff($le, 'complaints.investigate');
        return $this->names($ids);
    }

    /**
     * Handler actions.
     * @param array<string, mixed> $in
     * @param list<UploadedFile> $files
     */
    public function act(Authorizer $az, string $pid, string $action, array $in, array $files = []): void
    {
        [$c, $r] = $this->guarded($az, $pid);
        $db = $this->app->db();
        $id = (int) $c['id'];
        $le = (int) $c['legal_entity_id'];
        $now = $this->stamp();
        $st = (string) $c['status'];
        $reason = trim(str_replace("\r\n", "\n", (string) ($in['reason'] ?? '')));
        $need = static function (bool $ok): void {
            if (!$ok) {
                throw HttpException::forbidden();
            }
        };
        $state = static function (bool $ok): void {
            if (!$ok) {
                throw new BusinessException('complaints.error.state');
            }
        };
        switch ($action) {
            case 'triage':
                $need($r['manage']);
                $state($st === 'submitted');
                $urgency = (string) ($in['urgency'] ?? '');
                if (!in_array($urgency, ['normal', 'high'], true)) {
                    throw new ValidationException(['urgency' => 'complaints.error.choice']);
                }
                $cat = (string) ($in['category'] ?? $c['category']);
                if (!in_array($cat, self::CATEGORIES, true)) {
                    throw new ValidationException(['category' => 'complaints.error.choice']);
                }
                $db->run("UPDATE cp_cases SET status = 'triage', urgency = ?, category = ?, first_response_at = COALESCE(first_response_at, ?), updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$urgency, $cat, $now, $now, $az->userId, $id]);
                $this->event($id, 'triaged', $az->userId);
                break;
            case 'assign':
                $need($r['manage']);
                $state(in_array($st, ['triage', 'investigating', 'action_required'], true));
                $to = (int) ($in['investigator'] ?? 0);
                if (!isset($this->candidates($le, (string) $c['route'])[$to])) {
                    throw new ValidationException(['investigator' => 'complaints.error.investigator']);
                }
                $db->run("UPDATE cp_cases SET investigator_id = ?, accepted_at = NULL, coi_note = NULL, status = 'triage', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$to, $now, $az->userId, $id]);
                $this->event($id, 'assigned', $az->userId, $this->names([$to])[$to] ?? null);
                if ($to !== $az->userId) {
                    $this->app->notifier()->notify($to, 'complaints.assigned', 'complaints.notify.assigned', ['ref' => (string) $c['ref']], '/complaints/c/' . $pid, 'cp.as.' . $id . '.' . $to . '.' . (int) $c['row_version']);
                }
                break;
            case 'accept':
                $need($r['investigator']);
                $state($st === 'triage' && $c['accepted_at'] === null);
                if (empty($in['no_conflict'])) {
                    throw new ValidationException(['no_conflict' => 'complaints.error.no_conflict']);
                }
                $note = mb_substr(trim((string) ($in['coi_note'] ?? '')), 0, 500);
                $db->run("UPDATE cp_cases SET status = 'investigating', accepted_at = ?, coi_note = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $note === '' ? null : $note, $now, $az->userId, $id]);
                $this->event($id, 'accepted_case', $az->userId);
                break;
            case 'decline':
                $need($r['investigator']);
                $state($st === 'triage' && $c['accepted_at'] === null);
                if (mb_strlen($reason) < 10) {
                    throw new ValidationException(['reason' => 'complaints.error.reason10']);
                }
                $db->run("UPDATE cp_cases SET investigator_id = NULL, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $az->userId, $id]);
                $this->event($id, 'declined', $az->userId, $reason);
                foreach ($this->managers($le, (string) $c['route']) as $u) {
                    if ($u !== $az->userId) {
                        $this->app->notifier()->notify($u, 'complaints.declined', 'complaints.notify.declined', ['ref' => (string) $c['ref']], '/complaints/c/' . $pid, 'cp.dc.' . $id . '.' . $u . '.' . (int) $c['row_version']);
                    }
                }
                break;
            case 'message':
                $need($r['manage'] || ($r['investigator'] && $c['accepted_at'] !== null));
                $state(in_array($st, array_merge(self::OPEN, ['resolved']), true));
                $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
                if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) {
                    throw new ValidationException(['body' => 'complaints.error.message']);
                }
                $pics = Evidence::scrubAll($files, self::MAX_FILES);
                $asks = !empty($in['asks_reply']) && in_array($st, ['investigating', 'action_required'], true);
                $db->transactional(function () use ($db, $id, $az, $body, $asks, $now, $pics, $st): void {
                    $db->run("INSERT INTO cp_messages (case_id, side, author_id, body, asks_reply, digested, created_at) VALUES (?, 'staff', ?, ?, ?, 1, ?)", [$id, $az->userId, $body, $asks ? 1 : 0, $now]);
                    $this->storeFiles($id, (int) $db->pdo()->lastInsertId(), 'staff', $pics, $now);
                    $db->run('UPDATE cp_cases SET first_response_at = COALESCE(first_response_at, ?), status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$now, $asks ? 'action_required' : $st, $now, $az->userId, $id]);
                });
                $this->event($id, $asks ? 'asked' : 'messaged', $az->userId);
                break;
            case 'note':
                $need($r['manage'] || ($r['investigator'] && $c['accepted_at'] !== null));
                $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
                if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) {
                    throw new ValidationException(['note' => 'complaints.error.message']);
                }
                $state($c['purged_at'] === null);
                $db->run('INSERT INTO cp_notes (case_id, author_id, body, created_at) VALUES (?, ?, ?, ?)', [$id, $az->userId, $body, $now]);
                break;
            case 'resolve':
                $need($r['investigator'] && $c['accepted_at'] !== null);
                $state(in_array($st, ['investigating', 'action_required'], true));
                $outcome = trim(str_replace("\r\n", "\n", (string) ($in['outcome'] ?? '')));
                $finding = (string) ($in['finding'] ?? '');
                $score = (int) ($in['score'] ?? 0);
                $e = [];
                if (mb_strlen($outcome) < 20 || mb_strlen($outcome) > 5000) {
                    $e['outcome'] = 'complaints.error.outcome';
                }
                if (!in_array($finding, self::FINDINGS, true)) {
                    $e['finding'] = 'complaints.error.choice';
                }
                if ($score < 1 || $score > 5) {
                    $e['score'] = 'complaints.error.rating';
                }
                if ($e !== []) {
                    throw new ValidationException($e);
                }
                $db->run("UPDATE cp_cases SET status = 'resolved', outcome = ?, finding = ?, staff_score = ?, resolved_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$outcome, $finding, $score, $now, $now, $az->userId, $id]);
                $this->event($id, 'resolved', $az->userId);
                break;
            case 'close':
                $need($r['manage']);
                $state($st === 'resolved');
                if ((int) ($c['investigator_id'] ?? 0) === $az->userId) {
                    throw new BusinessException('complaints.error.own_case');
                }
                $db->run("UPDATE cp_cases SET status = 'closed', closed_at = ?, closed_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $reason === '' ? null : mb_substr($reason, 0, 500), $now, $az->userId, $id]);
                $this->event($id, 'closed', $az->userId);
                break;
            case 'duplicate':
            case 'out_of_scope':
                $need($r['manage']);
                $state(in_array($st, ['submitted', 'triage'], true));
                if (mb_strlen($reason) < 10) {
                    throw new ValidationException(['reason' => 'complaints.error.reason10']);
                }
                $dup = null;
                if ($action === 'duplicate') {
                    $dup = $db->scalar('SELECT id FROM cp_cases WHERE legal_entity_id = ? AND ref = ? AND id <> ?', [$le, strtoupper(trim((string) ($in['duplicate_ref'] ?? ''))), $id]);
                    if ($dup === null) {
                        throw new ValidationException(['duplicate_ref' => 'complaints.error.duplicate_ref']);
                    }
                }
                $db->run(
                    'UPDATE cp_cases SET status = ?, duplicate_of = ?, closed_reason = ?, closed_at = ?, first_response_at = COALESCE(first_response_at, ?), updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                    [$action, $dup, mb_substr($reason, 0, 500), $now, $now, $now, $az->userId, $id]
                );
                $this->event($id, $action, $az->userId, $reason);
                break;
            case 'reopen':
                $need($r['manage']);
                $state(in_array($st, ['closed', 'duplicate', 'out_of_scope'], true) && $c['purged_at'] === null);
                if (mb_strlen($reason) < 10) {
                    throw new ValidationException(['reason' => 'complaints.error.reason10']);
                }
                $db->run("UPDATE cp_cases SET status = 'triage', closed_at = NULL, resolved_at = NULL, duplicate_of = NULL, reopen_count = reopen_count + 1, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$now, $az->userId, $id]);
                $this->event($id, 'reopened', $az->userId, $reason);
                break;
            default:
                throw HttpException::notFound();
        }
        $this->app->audit()->record('complaints.' . $action, 'success', 'cp_case', $pid, $le);
    }

    /** @return list<int> */
    private function managers(int $le, string $route): array
    {
        return $this->staff($le, $route === 'alternate' ? 'complaints.alternate' : 'complaints.manage');
    }

    /**
     * A picture of a case, for a handler (or, with the code, the sender).
     * @return array{bytes: string, mime: string, filename: string}
     */
    public function file(?Authorizer $az, ?string $code, string $pid, int $fid): array
    {
        if ($az !== null) {
            $c = $this->caseFor($az, $pid);
            if ($c['summary_only']) {
                throw HttpException::notFound();
            }
            $caseId = (int) $this->app->db()->scalar('SELECT id FROM cp_cases WHERE public_id = ?', [$pid]);
        } else {
            $c = $this->byCode((string) $code) ?? throw HttpException::notFound();
            $caseId = (int) $c['id'];
        }
        $f = $this->app->db()->one('SELECT filename, mime, content FROM cp_files WHERE id = ? AND case_id = ? AND content IS NOT NULL', [$fid, $caseId]) ?? throw HttpException::notFound();
        return ['bytes' => (string) $f['content'], 'mime' => (string) $f['mime'], 'filename' => (string) $f['filename']];
    }

    // ---- figures -----------------------------------------------------------------------------------------------------------

    /**
     * Anonymous figures for a year; every count below the minimum group is hidden.
     * @return array<string, mixed>
     */
    public function stats(Authorizer $az, int $le, int $year): array
    {
        $this->guard($az, $le, 'complaints.stats');
        $db = $this->app->db();
        $k = $this->settings($le)['min_group'];
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-01-01', $year + 1);
        $rows = $db->all('SELECT category, status, created_at, first_response_at, resolved_at, closed_at, reporter_rating FROM cp_cases WHERE legal_entity_id = ? AND created_at >= ? AND created_at < ?', [$le, $from, $to]);
        $hide = static fn (int $n): ?int => $n === 0 || $n >= $k ? $n : null;
        $byCat = array_fill_keys(self::CATEGORIES, 0);
        $byStatus = ['open' => 0, 'resolved' => 0, 'closed' => 0, 'rejected' => 0];
        $byMonth = array_fill(1, 12, 0);
        $resp = [];
        $res = [];
        $ratings = [];
        $days = static function (string $a, ?string $b): ?float {
            if ($b === null) {
                return null;
            }
            $x = strtotime(substr($a, 0, 19) . ' UTC');
            $y = strtotime(substr($b, 0, 19) . ' UTC');
            return $x === false || $y === false ? null : max(0.0, ($y - $x) / 86400);
        };
        foreach ($rows as $r) {
            $byCat[(string) $r['category']] = ($byCat[(string) $r['category']] ?? 0) + 1;
            $g = in_array($r['status'], self::OPEN, true) ? 'open' : (in_array($r['status'], ['duplicate', 'out_of_scope'], true) ? 'rejected' : (string) $r['status']);
            $byStatus[$g]++;
            $local = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr((string) $r['created_at'], 0, 19), new DateTimeZone('UTC'));
            $m = $local === false ? 1 : (int) $local->setTimezone($this->tz())->format('n');
            $byMonth[$m]++;
            if (($d = $days((string) $r['created_at'], $r['first_response_at'])) !== null) {
                $resp[] = $d;
            }
            if (($d = $days((string) $r['created_at'], $r['resolved_at'] ?? $r['closed_at'])) !== null && in_array($r['status'], ['resolved', 'closed'], true)) {
                $res[] = $d;
            }
            if ($r['reporter_rating'] !== null) {
                $ratings[] = (int) $r['reporter_rating'];
            }
        }
        $avg = static fn (array $v): ?float => count($v) >= $k ? round(array_sum($v) / count($v), 1) : null;
        return [
            'k' => $k, 'year' => $year, 'total' => $hide(count($rows)),
            'category' => array_map($hide, $byCat), 'status' => array_map($hide, $byStatus), 'month' => array_map($hide, $byMonth),
            'response_days' => $avg($resp), 'resolve_days' => $avg($res), 'rating' => $avg($ratings), 'rating_n' => $hide(count($ratings)),
        ];
    }

    // ---- job ---------------------------------------------------------------------------------------------------------------

    /** @return array{digest: int, sla: int, purged: int} */
    public function tick(): array
    {
        $db = $this->app->db();
        $todayStart = $this->dayStamp();
        $out = ['digest' => 0, 'sla' => 0, 'purged' => 0];

        // 1. next-day digest of new cases and senders' replies (stamped with their day, so only earlier days go out)
        foreach ($db->all("SELECT id, public_id, ref, legal_entity_id, route FROM cp_cases WHERE digested = 0 AND created_at < ?", [$todayStart]) as $c) {
            foreach ($this->managers((int) $c['legal_entity_id'], (string) $c['route']) as $u) {
                $this->app->notifier()->notify($u, 'complaints.new', 'complaints.notify.new', ['ref' => (string) $c['ref']], '/complaints/c/' . $c['public_id'], 'cp.new.' . $c['id'] . '.' . $u);
            }
            $db->run('UPDATE cp_cases SET digested = 1 WHERE id = ?', [$c['id']]);
            $out['digest']++;
        }
        foreach (
            $db->all("SELECT m.id, c.id AS case_id, c.public_id, c.ref, c.legal_entity_id, c.route, c.investigator_id, c.accepted_at FROM cp_messages m JOIN cp_cases c ON c.id = m.case_id
            WHERE m.side = 'reporter' AND m.digested = 0 AND m.created_at < ?", [$todayStart]) as $m
        ) {
            $to = $m['investigator_id'] !== null && $m['accepted_at'] !== null ? [(int) $m['investigator_id']] : $this->managers((int) $m['legal_entity_id'], (string) $m['route']);
            foreach ($to as $u) {
                $this->app->notifier()->notify($u, 'complaints.reply', 'complaints.notify.reply', ['ref' => (string) $m['ref']], '/complaints/c/' . $m['public_id'], 'cp.re.' . $m['id'] . '.' . $u);
            }
            $db->run('UPDATE cp_messages SET digested = 1 WHERE id = ?', [$m['id']]);
            $out['digest']++;
        }

        // 2. SLAs (only when the company sets them) — one notice each
        $now = $this->app->clock->now();
        foreach ($db->all("SELECT * FROM cp_cases WHERE status IN ('submitted','triage','investigating','action_required') AND (response_overdue_sent = 0 OR resolve_overdue_sent = 0)") as $c) {
            $set = $this->settings((int) $c['legal_entity_id']);
            $due = $this->due($c, $set);
            $mgr = $this->managers((int) $c['legal_entity_id'], (string) $c['route']);
            if ($due['response'] !== null && (int) $c['response_overdue_sent'] === 0 && $due['response'] < $now->format('Y-m-d H:i:s.u')) {
                foreach ($mgr as $u) {
                    $this->app->notifier()->notify($u, 'complaints.overdue', 'complaints.notify.response_overdue', ['ref' => (string) $c['ref']], '/complaints/c/' . $c['public_id'], 'cp.ro.' . $c['id'] . '.' . $u);
                }
                $db->run('UPDATE cp_cases SET response_overdue_sent = 1 WHERE id = ?', [$c['id']]);
                $out['sla']++;
            }
            if ($due['resolve'] !== null && (int) $c['resolve_overdue_sent'] === 0 && $due['resolve'] < $now->format('Y-m-d H:i:s.u')) {
                $to = $mgr;
                if ($c['investigator_id'] !== null) {
                    $to[] = (int) $c['investigator_id'];
                }
                foreach (array_unique($to) as $u) {
                    $this->app->notifier()->notify($u, 'complaints.overdue', 'complaints.notify.resolve_overdue', ['ref' => (string) $c['ref']], '/complaints/c/' . $c['public_id'], 'cp.rs.' . $c['id'] . '.' . $u);
                }
                $db->run('UPDATE cp_cases SET resolve_overdue_sent = 1 WHERE id = ?', [$c['id']]);
                $out['sla']++;
            }
        }

        // 3. retention after closing (only when the company sets it)
        foreach ($db->all('SELECT legal_entity_id, retention_days FROM cp_settings WHERE retention_days IS NOT NULL') as $s) {
            $cut = $now->modify('-' . (int) $s['retention_days'] . ' days')->format('Y-m-d H:i:s.u');
            foreach ($db->all("SELECT id FROM cp_cases WHERE legal_entity_id = ? AND purged_at IS NULL AND status IN ('closed','duplicate','out_of_scope') AND closed_at < ?", [$s['legal_entity_id'], $cut]) as $c) {
                $db->transactional(function () use ($db, $c): void {
                    $stamp = $this->stamp();
                    $db->run('UPDATE cp_cases SET title = NULL, body = NULL, outcome = NULL, closed_reason = NULL, reporter_comment = NULL, coi_note = NULL, track_hash = NULL, purged_at = ? WHERE id = ?', [$stamp, $c['id']]);
                    $db->run('UPDATE cp_messages SET body = NULL WHERE case_id = ?', [$c['id']]);
                    $db->run('DELETE FROM cp_notes WHERE case_id = ?', [$c['id']]);
                    $db->run('UPDATE cp_files SET content = NULL WHERE case_id = ?', [$c['id']]);
                    $db->run('UPDATE cp_events SET note = NULL WHERE case_id = ?', [$c['id']]);
                    $this->event((int) $c['id'], 'purged', null);
                });
                $out['purged']++;
            }
        }

        // 4. daily-limit counters of earlier days
        $db->run('DELETE FROM cp_quota WHERE day < ?', [$this->today()]);
        return $out;
    }

    public static function tickJob(App $app): void
    {
        self::of($app)->tick();
    }
}
