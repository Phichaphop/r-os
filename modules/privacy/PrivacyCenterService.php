<?php

declare(strict_types=1);

namespace Blue\Modules\Privacy;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Org\Translations;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;

/**
 * Privacy centre (R6-1 · 0.42.0 · DEC-109) — Q-07 Master Privacy Notice, Q-08 / Q-13 privacy practice for a company
 * without a DPO yet.
 *
 *  notices     one series per company × audience (employee, applicant, visitor, …). draft → pending (submitted by a
 *              privacy officer) → published by ANOTHER officer (privacy.approve) who confirms that a person with legal
 *              knowledge checked the text, and only when no [[…]] gap is left · the published version replaces the earlier
 *              one (superseded) · a change = a new version · withdraw with a reason. Published notices are public at
 *              /privacy-notice/<company code>/<audience>. Employee notices may require an acknowledgement (kept with the
 *              SHA-256 of the text, IP and browser); publishing tells the employees.
 *  activities  the record of processing activities: drafts can be made from the enabled modules (AI text, no legal basis
 *              or retention filled in) · activating needs a legal basis and a retention and is done by an officer other
 *              than the last editor.
 *  breaches    the personal-data breach log: found → contained → risk assessed → decision (notify / not) by an officer with
 *              a reason → dates of notification → closed with lessons. The time limit comes only from
 *              privacy.breach_notify_hours (set by the company's legal adviser — no default); overdue = one notice.
 * AI-written drafts carry ai_draft = 1 and the label "ร่างโดย AI — ต้องตรวจก่อนใช้" until a person publishes / activates them.
 */
final class PrivacyCenterService
{
    public const RISKS = ['unknown', 'low', 'medium', 'high'];

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

    private function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function today(): string
    {
        return $this->app->clock->now()->setTimezone($this->tz())->format('Y-m-d');
    }

    private function event(string $entity, int $id, string $action, ?int $actor, ?string $note = null): void
    {
        $this->app->db()->run(
            'INSERT INTO pv_events (entity, entity_id, action, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$entity, $id, $action, $actor, $note === null ? null : mb_substr($note, 0, 1000), $this->stamp()]
        );
    }

    /** @return list<array<string, mixed>> */
    public function events(string $entity, int $id): array
    {
        return $this->app->db()->all(
            "SELECT e.action, e.note, e.created_at, COALESCE(u.display_name, u.username) AS actor FROM pv_events e LEFT JOIN users u ON u.id = e.actor_id
             WHERE e.entity = ? AND e.entity_id = ? ORDER BY e.id",
            [$entity, $id]
        );
    }

    /** The company's name in a language (falls back to the code). */
    public function companyName(int $le, string $locale = 'th'): string
    {
        $names = (new Translations($this->app->db()))->many('legal_entity', [$le]);
        $name = isset($names[$le]) ? Translations::pick($names[$le], $locale) : '';
        return $name !== '' && $name !== '—' ? $name : (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
    }

    // ---- scope -----------------------------------------------------------------------------------------------------------

    /** @return list<array{id: int, code: string, name: string}> companies this person manages privacy for */
    public function companies(Authorizer $az, string $perm = 'privacy.manage'): array
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

    private function guard(Authorizer $az, int $le, string $perm = 'privacy.manage'): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
    }

    private function azFor(int $userId): Authorizer
    {
        return new Authorizer($this->app->db(), $this->app->clock, $userId);
    }

    /** @return list<int> officers of a company (privacy.manage) */
    private function officers(int $le): array
    {
        $ids = [];
        foreach (
            $this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN role_permissions p ON p.role_id = a.role_id
            WHERE a.status = 'active' AND u.status = 'active' AND p.permission_code = 'privacy.manage'") as $r
        ) {
            $uid = (int) $r['user_id'];
            if ($this->azFor($uid)->scope('privacy.manage')->coversLegalEntity($le)) {
                $ids[] = $uid;
            }
        }
        return $ids;
    }

    // ---- notices ---------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> every series of a company: newest version of each audience plus the published one */
    public function notices(int $le): array
    {
        return $this->app->db()->all(
            "SELECT n.*, (SELECT COUNT(*) FROM pv_acks a WHERE a.notice_id = n.id) AS acks FROM pv_notices n
             WHERE n.legal_entity_id = ? AND n.status IN ('draft','pending','published') ORDER BY FIELD(n.audience, 'employee','applicant','visitor','complainant','gps','contractor','customer','general'), n.version DESC",
            [$le]
        );
    }

    /** @return array<string, mixed> */
    public function notice(Authorizer $az, string $pid): array
    {
        $n = $this->app->db()->one('SELECT * FROM pv_notices WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->guard($az, (int) $n['legal_entity_id']);
        $n['history'] = $this->events('notice', (int) $n['id']);
        $n['older'] = $this->app->db()->all('SELECT public_id, version, status, approved_at FROM pv_notices WHERE legal_entity_id = ? AND audience = ? AND id <> ? ORDER BY version DESC', [$n['legal_entity_id'], $n['audience'], $n['id']]);
        return $n;
    }

    /** A draft from the AI template, or a new version copied from the newest one. */
    public function startNotice(Authorizer $az, int $le, string $audience, bool $fromTemplate): string
    {
        $this->guard($az, $le);
        if (!in_array($audience, Templates::AUDIENCES, true)) {
            throw new ValidationException(['audience' => 'privacy.error.audience']);
        }
        $db = $this->app->db();
        if ($db->scalar("SELECT COUNT(*) FROM pv_notices WHERE legal_entity_id = ? AND audience = ? AND status IN ('draft','pending')", [$le, $audience]) > 0) {
            throw new BusinessException('privacy.error.draft_exists');
        }
        $last = $db->one('SELECT * FROM pv_notices WHERE legal_entity_id = ? AND audience = ? ORDER BY version DESC LIMIT 1', [$le, $audience]);
        if ($fromTemplate || $last === null) {
            $company = $this->companyName($le);
            $tpl = Templates::notice($audience, $company);
            [$title, $body, $en, $ack, $ai] = [$tpl['title'], $tpl['body'], null, $audience === 'employee' ? 1 : 0, 1];
        } else {
            [$title, $body, $en, $ack, $ai] = [$last['title'], $last['body'], $last['body_en'], (int) $last['requires_ack'], (int) $last['ai_draft']];
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $version = (int) ($last['version'] ?? 0) + 1;
        $db->run('INSERT INTO pv_notices (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, audience, version, title, body, body_en, requires_ack, ai_draft)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $audience, $version, $title, $body, $en, $ack, $ai]);
        $this->event('notice', (int) $db->pdo()->lastInsertId(), $fromTemplate || $last === null ? 'drafted_ai' : 'new_version', $az->userId);
        $this->app->audit()->record('privacy.notice_drafted', 'success', 'pv_notice', $pid, $le, null, ['audience' => $audience, 'version' => $version, 'ai' => $ai]);
        return $pid;
    }

    /** @param array<string, mixed> $in */
    public function saveNotice(Authorizer $az, string $pid, array $in): void
    {
        $n = $this->notice($az, $pid);
        if ($n['status'] !== 'draft') {
            throw new BusinessException('privacy.error.not_draft');
        }
        $title = trim((string) ($in['title'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $en = trim(str_replace("\r\n", "\n", (string) ($in['body_en'] ?? '')));
        $errors = [];
        if (mb_strlen($title) < 5 || mb_strlen($title) > 160) {
            $errors['title'] = 'privacy.error.title';
        }
        if (mb_strlen($body) < 50 || mb_strlen($body) > 60000) {
            $errors['body'] = 'privacy.error.body';
        }
        if (mb_strlen($en) > 60000) {
            $errors['body_en'] = 'privacy.error.body';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->app->db()->run(
            'UPDATE pv_notices SET title = ?, body = ?, body_en = ?, requires_ack = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$title, $body, $en === '' ? null : $en, !empty($in['requires_ack']) && $n['audience'] !== 'complainant' ? 1 : 0, $this->stamp(), $az->userId, $n['id']]
        );
        $this->event('notice', (int) $n['id'], 'edited', $az->userId);
    }

    /** @return list<string> gaps ([[…]]) still in the text */
    public static function gaps(string $text): array
    {
        preg_match_all(Templates::GAP, $text, $m);
        return array_values(array_unique($m[0]));
    }

    public function submitNotice(Authorizer $az, string $pid): void
    {
        $n = $this->notice($az, $pid);
        if ($n['status'] !== 'draft') {
            throw new BusinessException('privacy.error.not_draft');
        }
        if (self::gaps($n['body'] . "\n" . ($n['body_en'] ?? '')) !== []) {
            throw new ValidationException(['body' => 'privacy.error.gaps']);
        }
        $now = $this->stamp();
        $this->app->db()->run("UPDATE pv_notices SET status = 'pending', submitted_by = ?, submitted_at = ?, return_note = NULL, updated_at = ?, updated_by = ? WHERE id = ?", [$az->userId, $now, $now, $az->userId, $n['id']]);
        $this->event('notice', (int) $n['id'], 'submitted', $az->userId);
        foreach ($this->officers((int) $n['legal_entity_id']) as $u) {
            if ($u !== $az->userId && $this->azFor($u)->can('privacy.approve')) {
                $this->app->notifier()->notify($u, 'privacy.review', 'privacy.notify.review', ['title' => $n['title']], '/privacy/center/n/' . $pid, 'pv.n.' . $n['id'] . '.' . $n['version']);
            }
        }
    }

    /** Publish (another officer, confirms the legal check) or return to draft with a note. */
    public function decideNotice(Authorizer $az, string $pid, bool $publish, string $note, string $effective, bool $legalChecked): void
    {
        $n = $this->notice($az, $pid);
        $le = (int) $n['legal_entity_id'];
        $this->guard($az, $le, 'privacy.approve');
        if ($n['status'] !== 'pending') {
            throw new BusinessException('privacy.error.not_pending');
        }
        if ((int) $n['submitted_by'] === $az->userId || (int) $n['updated_by'] === $az->userId) {
            throw new BusinessException('privacy.error.same_person');
        }
        $note = trim($note);
        $now = $this->stamp();
        $db = $this->app->db();
        if (!$publish) {
            if (mb_strlen($note) < 5) {
                throw new ValidationException(['note' => 'privacy.error.note']);
            }
            $db->run("UPDATE pv_notices SET status = 'draft', return_note = ?, updated_at = ? WHERE id = ?", [mb_substr($note, 0, 500), $now, $n['id']]);
            $this->event('notice', (int) $n['id'], 'returned', $az->userId, $note);
            if ($n['submitted_by'] !== null) {
                $this->app->notifier()->notify((int) $n['submitted_by'], 'privacy.returned', 'privacy.notify.returned', ['title' => $n['title']], '/privacy/center/n/' . $pid);
            }
            return;
        }
        $errors = [];
        if (!$legalChecked || mb_strlen($note) < 5) {
            $errors['legal_checked'] = 'privacy.error.legal_checked';
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $effective);
        if ($d === false || $d->format('Y-m-d') !== $effective || $effective < $this->today()) {
            $errors['effective_from'] = 'privacy.error.effective';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $hash = hash('sha256', $n['title'] . "\n" . $n['body'] . "\n" . ($n['body_en'] ?? ''));
        $db->transactional(function () use ($db, $n, $az, $now, $note, $effective, $hash): void {
            $db->run("UPDATE pv_notices SET status = 'superseded', updated_at = ? WHERE legal_entity_id = ? AND audience = ? AND status = 'published'", [$now, $n['legal_entity_id'], $n['audience']]);
            $db->run(
                "UPDATE pv_notices SET status = 'published', approved_by = ?, approved_at = ?, legal_checked_note = ?, effective_from = ?, sha256 = ?, updated_at = ? WHERE id = ?",
                [$az->userId, $now, mb_substr($note, 0, 300), $effective, $hash, $now, $n['id']]
            );
        });
        $this->event('notice', (int) $n['id'], 'published', $az->userId, $note);
        $this->app->audit()->record('privacy.notice_published', 'success', 'pv_notice', $pid, $le, null, ['audience' => $n['audience'], 'version' => (int) $n['version'], 'sha256' => $hash]);
        if ((int) $n['requires_ack'] === 1) {
            foreach ($this->audienceUsers($le) as $u) {
                $this->app->notifier()->notify($u, 'privacy.ack', 'privacy.notify.ack', ['title' => $n['title']], '/privacy/notices/' . $pid, 'pv.ack.' . $n['id']);
            }
        }
    }

    public function withdrawNotice(Authorizer $az, string $pid, string $reason): void
    {
        $n = $this->notice($az, $pid);
        $this->guard($az, (int) $n['legal_entity_id'], 'privacy.approve');
        if ($n['status'] !== 'published') {
            throw new BusinessException('privacy.error.not_published');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['reason' => 'privacy.error.note']);
        }
        $this->app->db()->run("UPDATE pv_notices SET status = 'withdrawn', withdrawn_reason = ?, updated_at = ?, updated_by = ? WHERE id = ?", [mb_substr(trim($reason), 0, 500), $this->stamp(), $az->userId, $n['id']]);
        $this->event('notice', (int) $n['id'], 'withdrawn', $az->userId, $reason);
        $this->app->audit()->record('privacy.notice_withdrawn', 'success', 'pv_notice', $pid, (int) $n['legal_entity_id']);
    }

    public function deleteDraft(Authorizer $az, string $pid): void
    {
        $n = $this->notice($az, $pid);
        if ($n['status'] !== 'draft') {
            throw new BusinessException('privacy.error.not_draft');
        }
        $this->app->db()->run('DELETE FROM pv_notices WHERE id = ?', [$n['id']]);
        $this->app->db()->run("DELETE FROM pv_events WHERE entity = 'notice' AND entity_id = ?", [$n['id']]);
        $this->app->audit()->record('privacy.notice_draft_deleted', 'success', 'pv_notice', $pid, (int) $n['legal_entity_id']);
    }

    /** @return list<int> active users employed by the company today */
    private function audienceUsers(int $le): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT id FROM users WHERE status = 'active' AND employee_id IS NOT NULL") as $u) {
            if ($this->employerOf((int) $u['id']) === $le) {
                $out[] = (int) $u['id'];
            }
        }
        return $out;
    }

    private function employerOf(int $userId): ?int
    {
        $emp = $this->app->db()->scalar('SELECT employee_id FROM users WHERE id = ?', [$userId]);
        if ($emp === null) {
            return null;
        }
        $me = EmployeeService::of($this->app)->forUser($userId);
        return $me === null || $me['status'] === 'former' || $me['job'] === null ? null : (int) $me['job']['legal_entity_id'];
    }

    /**
     * Public: the published notice of a company and audience (null = none).
     * @return array<string, mixed>|null
     */
    public function published(string $companyCode, string $audience): ?array
    {
        if (!in_array($audience, Templates::AUDIENCES, true)) {
            return null;
        }
        $n = $this->app->db()->one(
            "SELECT n.title, n.body, n.body_en, n.version, n.effective_from, n.audience, n.public_id, n.legal_entity_id FROM pv_notices n JOIN legal_entities l ON l.id = n.legal_entity_id
             WHERE l.code = ? AND n.audience = ? AND n.status = 'published'",
            [$companyCode, $audience]
        );
        if ($n !== null) {
            $n['company'] = $this->companyName((int) $n['legal_entity_id']);
        }
        return $n;
    }

    /** Public link of a company's published notice — for other modules (applicants, visitors, complainants). */
    public static function publicUrl(App $app, int $le, string $audience): ?string
    {
        $code = $app->db()->scalar("SELECT l.code FROM pv_notices n JOIN legal_entities l ON l.id = n.legal_entity_id WHERE n.legal_entity_id = ? AND n.audience = ? AND n.status = 'published'", [$le, $audience]);
        return $code === null ? null : '/privacy-notice/' . rawurlencode((string) $code) . '/' . $audience;
    }

    // ---- my notices / acknowledgement ------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> published notices of my employer, with whether I acknowledged */
    public function mine(int $userId): array
    {
        $le = $this->employerOf($userId);
        if ($le === null) {
            return [];
        }
        return $this->app->db()->all(
            "SELECT n.public_id, n.audience, n.title, n.version, n.effective_from, n.requires_ack, a.acked_at FROM pv_notices n
             LEFT JOIN pv_acks a ON a.notice_id = n.id AND a.user_id = ? WHERE n.legal_entity_id = ? AND n.status = 'published'
             ORDER BY n.requires_ack DESC, FIELD(n.audience, 'employee','gps','applicant','visitor','complainant','contractor','customer','general')",
            [$userId, $le]
        );
    }

    /** @return array<string, mixed> a published notice of my employer */
    public function mineOne(int $userId, string $pid): array
    {
        $le = $this->employerOf($userId);
        $n = $this->app->db()->one("SELECT n.*, a.acked_at FROM pv_notices n LEFT JOIN pv_acks a ON a.notice_id = n.id AND a.user_id = ? WHERE n.public_id = ? AND n.status = 'published'", [$userId, $pid]);
        if ($n === null || $le === null || (int) $n['legal_entity_id'] !== $le) {
            throw HttpException::notFound();
        }
        return $n;
    }

    public function acknowledge(int $userId, string $pid, bool $confirmed, ?string $ip, ?string $ua): void
    {
        $n = $this->mineOne($userId, $pid);
        if ((int) $n['requires_ack'] !== 1) {
            throw new BusinessException('privacy.error.no_ack');
        }
        if (!$confirmed) {
            throw new ValidationException(['confirm' => 'privacy.error.confirm']);
        }
        $this->app->db()->run(
            'INSERT IGNORE INTO pv_acks (notice_id, user_id, acked_at, sha256, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)',
            [$n['id'], $userId, $this->stamp(), $n['sha256'], $ip === null ? null : substr($ip, 0, 45), $ua === null ? null : mb_substr($ua, 0, 255)]
        );
        $this->app->audit()->record('privacy.notice_acknowledged', 'success', 'pv_notice', $pid, (int) $n['legal_entity_id']);
    }

    /** @return array{done: list<array<string, mixed>>, waiting: list<array<string, mixed>>} who acknowledged a published notice */
    public function ackReport(Authorizer $az, string $pid): array
    {
        $n = $this->notice($az, $pid);
        $acked = [];
        foreach ($this->app->db()->all('SELECT a.user_id, a.acked_at, COALESCE(u.display_name, u.username) AS name FROM pv_acks a JOIN users u ON u.id = a.user_id WHERE a.notice_id = ? ORDER BY a.acked_at', [$n['id']]) as $r) {
            $acked[(int) $r['user_id']] = $r;
        }
        $waiting = [];
        foreach ($this->audienceUsers((int) $n['legal_entity_id']) as $u) {
            if (!isset($acked[$u])) {
                $waiting[] = ['name' => (string) $this->app->db()->scalar('SELECT COALESCE(display_name, username) FROM users WHERE id = ?', [$u])];
            }
        }
        return ['done' => array_values($acked), 'waiting' => $waiting];
    }

    /** Employees who still have to acknowledge — a home reminder count. */
    public function pendingCount(int $userId): int
    {
        return count(array_filter($this->mine($userId), static fn (array $n): bool => (int) $n['requires_ack'] === 1 && $n['acked_at'] === null));
    }

    // ---- processing activities -------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function activities(int $le): array
    {
        return $this->app->db()->all("SELECT * FROM pv_activities WHERE legal_entity_id = ? ORDER BY FIELD(status, 'draft','active','retired'), code", [$le]);
    }

    /** @return array<string, mixed> */
    public function activity(Authorizer $az, string $pid): array
    {
        $a = $this->app->db()->one('SELECT * FROM pv_activities WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->guard($az, (int) $a['legal_entity_id']);
        $a['history'] = $this->events('activity', (int) $a['id']);
        return $a;
    }

    /** Drafts for the enabled modules that have no activity yet. @return int how many were added */
    public function seedActivities(Authorizer $az, int $le): int
    {
        $this->guard($az, $le);
        $enabled = array_keys($this->app->modules()->enabled());
        $db = $this->app->db();
        $now = $this->stamp();
        $n = 0;
        foreach (Templates::activities() as $code => $t) {
            if ($t['module'] !== '' && !in_array($t['module'], $enabled, true)) {
                continue;
            }
            if ($db->scalar('SELECT COUNT(*) FROM pv_activities WHERE legal_entity_id = ? AND code = ?', [$le, $code]) > 0) {
                continue;
            }
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO pv_activities (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, name, module, purpose, subjects, data_categories, is_sensitive, recipients, ai_draft)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $code, $t['name'], $t['module'] === '' ? null : $t['module'], $t['purpose'], $t['subjects'], $t['data'], $t['sensitive'] ? 1 : 0, $t['recipients']]
            );
            $this->event('activity', (int) $db->pdo()->lastInsertId(), 'drafted_ai', $az->userId);
            $n++;
        }
        $this->app->audit()->record('privacy.activities_seeded', 'success', 'legal_entity', $le, $le, null, ['added' => $n]);
        return $n;
    }

    /** @param array<string, mixed> $in */
    public function saveActivity(Authorizer $az, int $le, ?string $pid, array $in): string
    {
        $this->guard($az, $le);
        $f = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $row = ['code' => strtoupper($f('code', 40)), 'name' => $f('name', 160), 'purpose' => $f('purpose', 5000), 'legal_basis' => $f('legal_basis', 30),
            'basis_note' => $f('basis_note', 500), 'subjects' => $f('subjects', 300), 'data_categories' => $f('data_categories', 600), 'sensitive_note' => $f('sensitive_note', 500),
            'recipients' => $f('recipients', 600), 'transfer_abroad' => $f('transfer_abroad', 300), 'retention' => $f('retention', 300), 'security' => $f('security', 600), 'owner' => $f('owner', 160)];
        $errors = [];
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $row['code']) !== 1) {
            $errors['code'] = 'privacy.error.code';
        }
        foreach (['name' => 2, 'purpose' => 5, 'subjects' => 2, 'data_categories' => 2] as $k => $min) {
            if (mb_strlen($row[$k]) < $min) {
                $errors[$k] = 'privacy.error.required';
            }
        }
        if ($row['legal_basis'] !== '' && !in_array($row['legal_basis'], Templates::BASES, true)) {
            $errors['legal_basis'] = 'privacy.error.basis';
        }
        $sensitive = !empty($in['sensitive']) ? 1 : 0;
        $db = $this->app->db();
        $existing = $pid === null ? null : ($db->one('SELECT * FROM pv_activities WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) ?? throw HttpException::notFound());
        if ($db->scalar('SELECT COUNT(*) FROM pv_activities WHERE legal_entity_id = ? AND code = ? AND public_id <> ?', [$le, $row['code'], $pid ?? '']) > 0) {
            $errors['code'] = 'privacy.error.code_taken';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $vals = array_map(static fn (string $v): ?string => $v === '' ? null : $v, $row);
        $now = $this->stamp();
        if ($existing === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO pv_activities (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, name, purpose, legal_basis, basis_note, subjects, data_categories, is_sensitive, sensitive_note, recipients, transfer_abroad, retention, security, owner)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $vals['code'], $vals['name'], $vals['purpose'], $vals['legal_basis'], $vals['basis_note'], $vals['subjects'], $vals['data_categories'],
                $sensitive,
                $vals['sensitive_note'],
                $vals['recipients'],
                $vals['transfer_abroad'],
                $vals['retention'],
                $vals['security'],
                $vals['owner']]
            );
            $this->event('activity', (int) $db->pdo()->lastInsertId(), 'created', $az->userId);
            return $pid;
        }
        // an active activity that is edited goes back to draft: somebody else has to activate the new content
        $db->run(
            "UPDATE pv_activities SET code = ?, name = ?, purpose = ?, legal_basis = ?, basis_note = ?, subjects = ?, data_categories = ?, is_sensitive = ?, sensitive_note = ?, recipients = ?,
            transfer_abroad = ?, retention = ?, security = ?, owner = ?, status = IF(status = 'retired', 'retired', 'draft'), updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
            [$vals['code'], $vals['name'], $vals['purpose'], $vals['legal_basis'], $vals['basis_note'], $vals['subjects'], $vals['data_categories'], $sensitive, $vals['sensitive_note'], $vals['recipients'],
            $vals['transfer_abroad'],
            $vals['retention'],
            $vals['security'],
            $vals['owner'],
            $now,
            $az->userId,
            $existing['id']]
        );
        $this->event('activity', (int) $existing['id'], 'edited', $az->userId);
        return (string) $pid;
    }

    public function activateActivity(Authorizer $az, string $pid, bool $retire = false): void
    {
        $a = $this->activity($az, $pid);
        $this->guard($az, (int) $a['legal_entity_id'], 'privacy.approve');
        $now = $this->stamp();
        if ($retire) {
            $this->app->db()->run("UPDATE pv_activities SET status = 'retired', updated_at = ?, updated_by = ? WHERE id = ?", [$now, $az->userId, $a['id']]);
            $this->event('activity', (int) $a['id'], 'retired', $az->userId);
            return;
        }
        if ($a['status'] !== 'draft') {
            throw new BusinessException('privacy.error.not_draft');
        }
        if ((int) $a['updated_by'] === $az->userId) {
            throw new BusinessException('privacy.error.same_person');
        }
        if ($a['legal_basis'] === null || $a['retention'] === null) {
            throw new BusinessException('privacy.error.activate_missing');
        }
        if ((int) $a['is_sensitive'] === 1 && $a['sensitive_note'] === null) {
            throw new BusinessException('privacy.error.sensitive_note');
        }
        $this->app->db()->run("UPDATE pv_activities SET status = 'active', ai_draft = 0, activated_by = ?, activated_at = ?, updated_at = ? WHERE id = ?", [$az->userId, $now, $now, $a['id']]);
        $this->event('activity', (int) $a['id'], 'activated', $az->userId);
        $this->app->audit()->record('privacy.activity_activated', 'success', 'pv_activity', $pid, (int) $a['legal_entity_id']);
    }

    // ---- breaches --------------------------------------------------------------------------------------------------------

    public function notifyHours(): ?int
    {
        $v = $this->app->registry()->resolveOrNull('privacy.breach_notify_hours')?->value;
        return is_int($v) ? $v : null;
    }

    /**
     * Deadline set by the company (null = not set).
     * @param array<string, mixed> $b
     */
    public function deadline(array $b): ?string
    {
        $h = $this->notifyHours();
        if ($h === null) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', (string) $b['discovered_at'], new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr((string) $b['discovered_at'], 0, 19), new DateTimeZone('UTC'));
        return $at === false ? null : $at->modify('+' . $h . ' hours')->format('Y-m-d H:i:s.u');
    }

    /** @return list<array<string, mixed>> */
    public function breaches(int $le): array
    {
        $rows = $this->app->db()->all("SELECT * FROM pv_breaches WHERE legal_entity_id = ? ORDER BY status = 'closed', discovered_at DESC", [$le]);
        foreach ($rows as &$r) {
            $r['deadline'] = $this->deadline($r);
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    public function breach(Authorizer $az, string $pid): array
    {
        $b = $this->app->db()->one('SELECT * FROM pv_breaches WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->guard($az, (int) $b['legal_entity_id']);
        $b['deadline'] = $this->deadline($b);
        $b['history'] = $this->events('breach', (int) $b['id']);
        return $b;
    }

    private function localToUtc(string $local, string $field): string
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $local, $this->tz());
        if ($d === false) {
            throw new ValidationException([$field => 'privacy.error.when']);
        }
        $utc = $d->setTimezone(new DateTimeZone('UTC'));
        if ($utc > $this->app->clock->now()) {
            throw new ValidationException([$field => 'privacy.error.future']);
        }
        return $utc->format('Y-m-d H:i:s.u');
    }

    /** @param array<string, mixed> $in */
    public function recordBreach(Authorizer $az, int $le, array $in): string
    {
        $this->guard($az, $le);
        $summary = trim((string) ($in['summary'] ?? ''));
        $cats = trim((string) ($in['data_categories'] ?? ''));
        $errors = [];
        if (mb_strlen($summary) < 10) {
            $errors['summary'] = 'privacy.error.summary';
        }
        if (mb_strlen($cats) < 2) {
            $errors['data_categories'] = 'privacy.error.required';
        }
        $people = trim((string) ($in['people_estimate'] ?? ''));
        if ($people !== '' && (preg_match('/^\d{1,9}$/', $people) !== 1)) {
            $errors['people_estimate'] = 'privacy.error.number';
        }
        try {
            $found = $this->localToUtc((string) ($in['discovered_at'] ?? ''), 'discovered_at');
        } catch (ValidationException $e) {
            $errors += $e->errors;
            $found = '';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->transactional(function () use ($db, $le, $pid, $now, $az, $found, $summary, $cats, $people): void {
            $db->one('SELECT id FROM legal_entities WHERE id = ? FOR UPDATE', [$le]);
            $prefix = 'BR' . $this->app->clock->now()->setTimezone($this->tz())->format('ym') . '-';
            $last = (string) ($db->scalar('SELECT MAX(ref) FROM pv_breaches WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
            $ref = $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
            $db->run('INSERT INTO pv_breaches (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ref, discovered_at, summary, data_categories, people_estimate)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, $ref, $found, mb_substr($summary, 0, 5000), mb_substr($cats, 0, 600), $people === '' ? null : (int) $people]);
            $this->event('breach', (int) $db->pdo()->lastInsertId(), 'recorded', $az->userId);
        });
        $this->app->audit()->record('privacy.breach_recorded', 'success', 'pv_breach', $pid, $le); // no details in the audit
        foreach ($this->officers($le) as $u) {
            if ($u !== $az->userId) {
                $this->app->notifier()->notify($u, 'privacy.breach', 'privacy.notify.breach', [], '/privacy/center/b/' . $pid, 'pv.b.' . $pid . '.' . $u);
            }
        }
        return $pid;
    }

    /** @param array<string, mixed> $in */
    public function updateBreach(Authorizer $az, string $pid, string $step, array $in): void
    {
        $b = $this->breach($az, $pid);
        if ($b['status'] === 'closed' && $step !== 'reopen') {
            throw new BusinessException('privacy.error.closed');
        }
        $db = $this->app->db();
        $now = $this->stamp();
        $text = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        switch ($step) {
            case 'contain':
                $c = $text('containment', 5000);
                if (mb_strlen($c) < 5) {
                    throw new ValidationException(['containment' => 'privacy.error.note']);
                }
                $db->run('UPDATE pv_breaches SET containment = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$c, $now, $az->userId, $b['id']]);
                break;
            case 'assess':
                $risk = (string) ($in['risk'] ?? '');
                $why = $text('risk_reason', 1000);
                if (!in_array($risk, self::RISKS, true) || mb_strlen($why) < 5) {
                    throw new ValidationException(['risk_reason' => 'privacy.error.note']);
                }
                $db->run('UPDATE pv_breaches SET risk = ?, risk_reason = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$risk, $why, $now, $az->userId, $b['id']]);
                break;
            case 'decide':
                $this->guard($az, (int) $b['legal_entity_id'], 'privacy.approve');
                $dec = (string) ($in['decision'] ?? '');
                $why = $text('decision_reason', 1000);
                if (!in_array($dec, ['notify', 'no_notify'], true) || mb_strlen($why) < 10) {
                    throw new ValidationException(['decision_reason' => 'privacy.error.decision']);
                }
                $db->run('UPDATE pv_breaches SET decision = ?, decision_reason = ?, decided_by = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$dec, $why, $az->userId, $now, $az->userId, $b['id']]);
                break;
            case 'notified':
                if ($b['decision'] !== 'notify') {
                    throw new BusinessException('privacy.error.not_notify');
                }
                $sets = [];
                $vals = [];
                foreach (['authority_notified_at', 'subjects_notified_at'] as $k) {
                    if (trim((string) ($in[$k] ?? '')) !== '') {
                        $sets[] = $k . ' = ?';
                        $vals[] = $this->localToUtc((string) $in[$k], $k);
                    }
                }
                if ($sets === []) {
                    throw new ValidationException(['authority_notified_at' => 'privacy.error.when']);
                }
                $sets[] = 'authority_ref = ?';
                $vals[] = $text('authority_ref', 120) === '' ? $b['authority_ref'] : $text('authority_ref', 120);
                $db->run('UPDATE pv_breaches SET ' . implode(', ', $sets) . ', updated_at = ?, updated_by = ? WHERE id = ?', [...$vals, $now, $az->userId, $b['id']]);
                break;
            case 'close':
                $lessons = $text('lessons', 5000);
                if ($b['containment'] === null || $b['risk_reason'] === null || $b['decision'] === 'pending') {
                    throw new BusinessException('privacy.error.close_missing');
                }
                if ($b['decision'] === 'notify' && $b['authority_notified_at'] === null && $b['subjects_notified_at'] === null) {
                    throw new BusinessException('privacy.error.close_notify');
                }
                if (mb_strlen($lessons) < 10) {
                    throw new ValidationException(['lessons' => 'privacy.error.lessons']);
                }
                $db->run("UPDATE pv_breaches SET status = 'closed', lessons = ?, closed_at = ?, closed_by = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$lessons, $now, $az->userId, $now, $az->userId, $b['id']]);
                break;
            case 'reopen':
                $why = $text('reason', 500);
                if ($b['status'] !== 'closed' || mb_strlen($why) < 5) {
                    throw new ValidationException(['reason' => 'privacy.error.note']);
                }
                $db->run("UPDATE pv_breaches SET status = 'open', closed_at = NULL, closed_by = NULL, updated_at = ?, updated_by = ? WHERE id = ?", [$now, $az->userId, $b['id']]);
                $in['note'] = $why;
                break;
            default:
                throw HttpException::notFound();
        }
        $this->event('breach', (int) $b['id'], $step, $az->userId, $step === 'reopen' ? (string) $in['note'] : null);
        $this->app->audit()->record('privacy.breach_' . $step, 'success', 'pv_breach', $pid, (int) $b['legal_entity_id']);
    }

    // ---- job -------------------------------------------------------------------------------------------------------------

    /** Breaches still undecided or not notified past the company's time limit: one notice to the officers. */
    public function tick(): int
    {
        if ($this->notifyHours() === null) {
            return 0;
        }
        $n = 0;
        $now = $this->app->clock->now()->format('Y-m-d H:i:s.u');
        foreach ($this->app->db()->all("SELECT * FROM pv_breaches WHERE status = 'open' AND overdue_notified = 0 AND (decision = 'pending' OR (decision = 'notify' AND authority_notified_at IS NULL AND subjects_notified_at IS NULL))") as $b) {
            $due = $this->deadline($b);
            if ($due !== null && $due < $now) {
                foreach ($this->officers((int) $b['legal_entity_id']) as $u) {
                    $this->app->notifier()->notify($u, 'privacy.breach_overdue', 'privacy.notify.overdue', ['ref' => $b['ref']], '/privacy/center/b/' . $b['public_id'], 'pv.bo.' . $b['id'] . '.' . $u);
                }
                $this->app->db()->run('UPDATE pv_breaches SET overdue_notified = 1 WHERE id = ?', [$b['id']]);
                $n++;
            }
        }
        return $n;
    }

    public static function tickJob(App $app): void
    {
        self::of($app)->tick();
    }

    /** Text rendering for notices: escaped, paragraphs and line breaks, https links clickable. */
    public static function render(string $text): string
    {
        $out = [];
        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $p) {
            $h = htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $h = (string) preg_replace('~https://[^\s<>"\']+~u', '<a href="$0" rel="noopener noreferrer">$0</a>', $h);
            $out[] = '<p>' . nl2br($h, false) . '</p>';
        }
        return implode("\n", $out);
    }
}
