<?php

declare(strict_types=1);

namespace Blue\Core\Privacy;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Throwable;

/**
 * P-04 / P-05 (phase 5 gate, DEC-82): a person's own data export, and data-subject requests handled by the privacy
 * officer (core.privacy.manage).
 *
 *  export     every enabled module that declares "personal_data" contributes its sections; JSON file, after a fresh
 *             password confirmation, audited.
 *  request    access / correction / deletion / objection / other → received → identity verified → (deletion: erasure
 *             prepared with a dry run by one officer, approved and executed by ANOTHER) → completed or rejected with a
 *             written answer. The due day comes from config privacy.dsr_response_days (set by Legal — no default).
 *             Everything is in the request history; the erasure result (counts and what was kept, with why) is the
 *             evidence. Legal holds stop erasure (each module checks its own records).
 */
final class PrivacyService
{
    public const TYPES = ['access', 'correction', 'deletion', 'objection', 'other'];

    public function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function today(): string
    {
        return $this->app->clock->now()->setTimezone(new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->format('Y-m-d');
    }

    /** @return array<string, class-string<PersonalDataProvider>> module code => provider */
    public function providers(): array
    {
        $out = [];
        foreach ($this->app->modules()->enabled() as $code => $m) {
            $class = (string) ($m['personal_data'] ?? '');
            if ($class !== '' && class_exists($class) && is_subclass_of($class, PersonalDataProvider::class)) {
                $out[(string) $code] = $class;
            }
        }
        ksort($out);
        return $out;
    }

    public function employeeIdOf(int $userId): ?int
    {
        $id = $this->app->db()->scalar('SELECT employee_id FROM users WHERE id = ?', [$userId]);
        return $id !== null ? (int) $id : null;
    }

    /** @return list<array{module: string, key: string, label: string, rows: list<array<string, scalar|null>>}> */
    public function collect(int $userId): array
    {
        $emp = $this->employeeIdOf($userId);
        $out = [];
        foreach ($this->providers() as $module => $p) {
            try {
                foreach ($p::export($this->app, $userId, $emp) as $s) {
                    $out[] = ['module' => $module] + $s;
                }
            } catch (Throwable $e) {
                $this->app->logger->error('privacy.export_failed', ['module' => $module, 'error' => $e->getMessage()]);
                $out[] = ['module' => $module, 'key' => 'error', 'label' => 'privacy.section_failed', 'rows' => []];
            }
        }
        return $out;
    }

    /** The JSON package for one person (own export or an access request). */
    public function package(int $userId, string $purpose, ?string $requestNo = null): string
    {
        $t = $this->app->translator();
        $u = (array) $this->app->db()->one('SELECT username, display_name, email FROM users WHERE id = ?', [$userId]);
        $sections = [];
        foreach ($this->collect($userId) as $s) {
            $sections[] = ['module' => $s['module'], 'section' => $s['key'], 'title' => $t->get($s['label']), 'rows' => $s['rows']];
        }
        return (string) json_encode([
            'generated_at' => $this->app->clock->now()->format('c'), 'purpose' => $purpose, 'request' => $requestNo,
            'person' => ['username' => $u['username'] ?? null, 'display_name' => $u['display_name'] ?? null, 'email' => $u['email'] ?? null],
            'note' => $t->get('privacy.package_note'), 'sections' => $sections,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // ---- requests -------------------------------------------------------------------------------------------------------------

    public function submit(int $userId, string $type, string $details): string
    {
        $details = mb_substr(trim($details), 0, 2000);
        $errors = [];
        if (!in_array($type, self::TYPES, true)) {
            $errors['type'] = 'form.invalid_choice';
        }
        if (mb_strlen($details) < 10) {
            $errors['details'] = 'privacy.error.details';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        $emp = $this->employeeIdOf($userId);
        $le = null; // requests are handled at organization level by the privacy officer
        $days = $this->app->registry()->resolveOrNull('privacy.dsr_response_days');
        $due = $days !== null && is_int($days->value) ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $this->today()) ?: $this->app->clock->now())->modify('+' . $days->value . ' days')->format('Y-m-d') : null;
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->run('INSERT INTO privacy_requests (public_id, created_at, created_by, updated_at, updated_by, user_id, employee_id, legal_entity_id, request_type, details, due_on)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $userId, $now, $userId, $userId, $emp, $le, $type, $details, $due]);
        $id = (int) $db->scalar('SELECT id FROM privacy_requests WHERE public_id = ?', [$pid]);
        $no = 'DSR-' . substr($this->today(), 0, 4) . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
        $db->run('UPDATE privacy_requests SET request_no = ? WHERE id = ?', [$no, $id]);
        $this->event($id, 'received', null, $userId);
        $this->app->audit()->record('privacy.request_received', 'success', 'privacy_request', $pid, $le, null, ['type' => $type]);
        foreach ($this->officers() as $uid) {
            $this->app->notifier()->notify($uid, 'privacy.request', 'privacy.notify.received', ['no' => $no], '/privacy/requests/' . $pid, 'dsr.new:' . $pid . ':' . $uid);
        }
        return $pid;
    }

    /** @return list<int> people who handle requests */
    private function officers(): array
    {
        return array_map('intval', array_column($this->app->db()->all(
            "SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN role_permissions rp ON rp.role_id = a.role_id JOIN users u ON u.id = a.user_id
              WHERE rp.permission_code = 'core.privacy.manage' AND a.status = 'active' AND u.status = 'active'"
        ), 'user_id'));
    }

    /** @return list<array<string, mixed>> */
    public function mine(int $userId): array
    {
        return $this->app->db()->all('SELECT * FROM privacy_requests WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    /** @return list<array<string, mixed>> */
    public function all(Authorizer $az, string $status = ''): array
    {
        $this->guard($az);
        return $this->app->db()->all(
            'SELECT r.*, u.display_name, u.username FROM privacy_requests r JOIN users u ON u.id = r.user_id' . ($status !== '' ? ' WHERE r.status = ?' : '') . ' ORDER BY r.status IN (\'completed\',\'rejected\'), r.due_on IS NULL, r.due_on, r.id DESC LIMIT 200',
            $status !== '' ? [$status] : []
        );
    }

    private function guard(Authorizer $az): void
    {
        if ($az->scope('core.privacy.manage')->isEmpty()) {
            throw HttpException::forbidden();
        }
    }

    /** @return array<string, mixed> request + subject + history (officer, or the requester for their own) */
    public function request(Authorizer $az, string $pid): array
    {
        $r = $this->app->db()->one('SELECT r.*, u.display_name, u.username, u.status AS user_status FROM privacy_requests r JOIN users u ON u.id = r.user_id WHERE r.public_id = ?', [$pid]);
        $officer = !$az->scope('core.privacy.manage')->isEmpty();
        if ($r === null || (!$officer && (int) $r['user_id'] !== $az->userId)) {
            throw HttpException::notFound();
        }
        $r['is_officer'] = $officer;
        $r['events'] = $this->app->db()->all('SELECT e.*, u.display_name AS actor FROM privacy_request_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.request_id = ? ORDER BY e.id', [$r['id']]);
        if ($officer) {
            $this->app->audit()->record('privacy.request_viewed', 'success', 'privacy_request', $pid, $r['legal_entity_id'] !== null ? (int) $r['legal_entity_id'] : null);
        }
        return $r;
    }

    /** @return list<array{module: string, label: string, rows: int}> what each module holds about the person (counts only) */
    public function coverage(int $userId): array
    {
        return array_map(static fn (array $s): array => ['module' => $s['module'], 'label' => $s['label'], 'rows' => count($s['rows'])], $this->collect($userId));
    }

    public function verify(Authorizer $az, string $pid, string $note): void
    {
        $r = $this->open($az, $pid, ['received']);
        $note = mb_substr(trim($note), 0, 500);
        if (mb_strlen($note) < 5) {
            throw new ValidationException(['identity_note' => 'privacy.error.note']);
        }
        $this->app->db()->run("UPDATE privacy_requests SET status = 'verified', identity_note = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$note, $this->now(), $az->userId, $r['id']]);
        $this->event((int) $r['id'], 'verified', $note, $az->userId);
    }

    /** Deletion: dry run of every module, kept as the plan another officer approves. */
    public function prepareErasure(Authorizer $az, string $pid): void
    {
        $r = $this->open($az, $pid, ['verified']);
        if ($r['request_type'] !== 'deletion') {
            throw new BusinessException('privacy.error.not_deletion', [], 409);
        }
        if ((int) $r['user_id'] === $az->userId) {
            throw new BusinessException('privacy.error.own', [], 409);
        }
        $plan = $this->runErase((int) $r['user_id'], $az->userId, true);
        $this->app->db()->run(
            "UPDATE privacy_requests SET status = 'erasure_pending', erasure_plan = ?, prepared_by = ?, updated_at = ?, updated_by = ? WHERE id = ?",
            [(string) json_encode($plan, JSON_UNESCAPED_UNICODE), $az->userId, $this->now(), $az->userId, $r['id']]
        );
        $this->event((int) $r['id'], 'erasure_prepared', null, $az->userId);
    }

    /** A second officer approves → the erasure runs; the result is kept as the evidence. */
    public function approveErasure(Authorizer $az, string $pid): void
    {
        $r = $this->open($az, $pid, ['erasure_pending']);
        if ((int) $r['prepared_by'] === $az->userId || (int) $r['user_id'] === $az->userId) {
            throw new BusinessException('privacy.error.same_person', [], 409);
        }
        $result = $this->runErase((int) $r['user_id'], $az->userId, false);
        $this->app->db()->run(
            "UPDATE privacy_requests SET status = 'erasure_done', erasure_result = ?, approved_by = ?, erased_at = ?, updated_at = ?, updated_by = ? WHERE id = ?",
            [(string) json_encode($result, JSON_UNESCAPED_UNICODE), $az->userId, $this->now(), $this->now(), $az->userId, $r['id']]
        );
        $this->event((int) $r['id'], 'erased', (string) json_encode(array_map(static fn (array $m): array => $m['done'], $result)), $az->userId);
        $this->app->audit()->record('privacy.erased', 'success', 'privacy_request', $pid, $r['legal_entity_id'] !== null ? (int) $r['legal_entity_id'] : null, null, ['modules' => array_keys($result)]);
    }

    /** @return array<string, array{done: array<string, int>, kept: array<string, string>}> */
    private function runErase(int $userId, int $actor, bool $dry): array
    {
        $emp = $this->employeeIdOf($userId);
        $out = [];
        foreach ($this->providers() as $module => $p) {
            try {
                $out[$module] = $p::erase($this->app, $userId, $emp, $actor, $dry);
            } catch (Throwable $e) {
                $this->app->logger->error('privacy.erase_failed', ['module' => $module, 'error' => $e->getMessage()]);
                $out[$module] = ['done' => [], 'kept' => ['privacy.section_failed' => 'privacy.kept.error']];
            }
        }
        return $out;
    }

    /** Answer and close: completed (with the answer) or rejected (with the reason). */
    public function close(Authorizer $az, string $pid, string $outcome, string $answer): void
    {
        $r = $this->open($az, $pid, ['received', 'verified', 'erasure_done']);
        $answer = mb_substr(trim($answer), 0, 2000);
        if (mb_strlen($answer) < 10) {
            throw new ValidationException(['answer' => 'privacy.error.answer']);
        }
        if ($outcome === 'completed' && $r['status'] === 'received') {
            throw new BusinessException('privacy.error.verify_first', [], 409);
        }
        if ($outcome === 'completed' && $r['request_type'] === 'deletion' && $r['status'] !== 'erasure_done') {
            throw new BusinessException('privacy.error.erase_first', [], 409);
        }
        $status = $outcome === 'completed' ? 'completed' : 'rejected';
        $this->app->db()->run('UPDATE privacy_requests SET status = ?, answer = ?, closed_at = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$status, $answer, $this->now(), $this->now(), $az->userId, $r['id']]);
        $this->event((int) $r['id'], $status, $answer, $az->userId);
        $this->app->audit()->record('privacy.request_' . $status, 'success', 'privacy_request', $pid, $r['legal_entity_id'] !== null ? (int) $r['legal_entity_id'] : null);
        $this->app->notifier()->notify((int) $r['user_id'], 'privacy.request', 'privacy.notify.' . $status, ['no' => (string) $r['request_no']], '/me/privacy/' . $pid, 'dsr.close:' . $pid);
    }

    /** Officer downloads the person's package for an access request (audited). */
    public function accessPackage(Authorizer $az, string $pid): string
    {
        $r = $this->open($az, $pid, ['verified', 'erasure_pending', 'erasure_done', 'completed']);
        $this->event((int) $r['id'], 'package', null, $az->userId);
        $this->app->audit()->record('privacy.package_exported', 'success', 'privacy_request', $pid, $r['legal_entity_id'] !== null ? (int) $r['legal_entity_id'] : null);
        return $this->package((int) $r['user_id'], 'dsr', (string) $r['request_no']);
    }

    /**
     * @param list<string> $states
     * @return array<string, mixed>
     */
    private function open(Authorizer $az, string $pid, array $states): array
    {
        $this->guard($az);
        $r = $this->app->db()->one('SELECT * FROM privacy_requests WHERE public_id = ?', [$pid]);
        if ($r === null) {
            throw HttpException::notFound();
        }
        if (!in_array($r['status'], $states, true)) {
            throw new BusinessException('privacy.error.state', [], 409);
        }
        return $r;
    }

    private function event(int $id, string $action, ?string $note, int $actor): void
    {
        $this->app->db()->run('INSERT INTO privacy_request_events (request_id, action, note, actor_id, occurred_at) VALUES (?, ?, ?, ?, ?)', [$id, $action, $note !== null ? mb_substr($note, 0, 1000) : null, $actor, $this->now()]);
    }

    /** True when a legal hold covers the record class (company or everyone) or the person's employee record. */
    public static function held(App $app, string $recordClass, ?int $legalEntityId, ?string $employeePid): bool
    {
        return $app->db()->scalar(
            "SELECT id FROM legal_holds WHERE status IN ('active','release_requested') AND (
                (scope_type = 'record_class' AND record_class = ? AND (legal_entity_id IS NULL OR legal_entity_id <=> ?))
             OR (scope_type = 'related' AND related_type = 'employee' AND related_id = ?)) LIMIT 1",
            [$recordClass, $legalEntityId, $employeePid ?? '']
        ) !== null;
    }
}
