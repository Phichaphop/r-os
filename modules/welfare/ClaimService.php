<?php

declare(strict_types=1);

namespace Blue\Modules\Welfare;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;

/**
 * Welfare claims (M37 · DEC-86).
 *
 *   draft ─submit→ submitted ─(reviewer passes the documents)→ reviewing ─(approver)→ approved | partially_approved ─(paid)→ paid
 *                       └──────── need_info (sent back with a question; the person edits and sends again) ┘
 *                       └──────── rejected (with a reason) · cancelled (by the person before a decision)
 *
 * The person (welfare.use) writes the claim and attaches receipts (photo or PDF, stored in the document register —
 * medical types as Restricted). Before sending, the system checks the type in force on the expense date, eligibility
 * (employment type, days of service, how long after the expense), the limits (per year — approved amounts plus claims
 * still waiting — per claim, number of claims) and duplicates: the same receipt file or the same receipt number on
 * another claim stops the claim; the same type, day and amount of the same person is shown to the reviewer.
 *
 * Review and approval go through the central workflow (route welfare.claim, two steps, "distinct_steps": the reviewer
 * never approves the same claim). The approver may approve less than claimed; the amount is checked again against the
 * limit at the moment of approval (inside a lock on the person), and only approved amounts use the limit.
 * Amounts never go into the audit log or notifications.
 */
final class ClaimService
{
    public const OPEN = ['submitted', 'reviewing', 'need_info'];
    public const COUNTED = ['approved', 'partially_approved', 'paid'];
    public const EDITABLE = ['draft', 'need_info'];
    public const MAX_FILES = 10;
    public const MAX_AMOUNT = 10_000_000 * 10000;

    private function __construct(private readonly App $app)
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

    private function types(): TypeService
    {
        return TypeService::of($this->app);
    }

    private function event(int $claimId, string $event, ?int $actorId, ?string $note = null): void
    {
        $this->app->db()->run('INSERT INTO wf_claim_events (claim_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$claimId, $event, $actorId, $note !== null ? mb_substr($note, 0, 500) : null, $this->now()]);
    }

    // ---- the person -----------------------------------------------------------------------------------------------------------

    /** @return array{employee: array<string, mixed>, le: int, job: array<string, mixed>}|null */
    public function me(int $userId): ?array
    {
        $db = $this->app->db();
        $emp = $db->scalar('SELECT employee_id FROM users WHERE id = ?', [$userId]);
        if ($emp === null) {
            return null;
        }
        $e = $db->one('SELECT id, public_id, employee_code, name_th, hire_date, termination_date, status FROM employees WHERE id = ?', [$emp]);
        $job = $this->jobOn((int) $emp, $this->types()->today());
        return $e !== null && $job !== null ? ['employee' => $e, 'le' => (int) $job['legal_entity_id'], 'job' => $job] : null;
    }

    /** @return array<string, mixed>|null the employment record in force on a day (else the latest one) */
    public function jobOn(int $employeeId, string $date): ?array
    {
        $db = $this->app->db();
        return $db->one('SELECT * FROM employment_history WHERE employee_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1', [$employeeId, $date, $date])
            ?? $db->one('SELECT * FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$employeeId]);
    }

    /**
     * The person's use of a type code in a calendar year: approved amounts, amounts still waiting (only claims sent
     * before $sentBefore when given — a claim never loses its place to one sent later) and the number of claims.
     *
     * @return array{used: int, pending: int, count: int}
     */
    public function usage(int $employeeId, int $le, string $code, int $year, int $excludeClaimId = 0, ?string $sentBefore = null): array
    {
        $r = $this->app->db()->one(
            "SELECT COALESCE(SUM(CASE WHEN c.status IN ('approved','partially_approved','paid') THEN c.approved_amount ELSE 0 END), 0) AS used,
                    COALESCE(SUM(CASE WHEN c.status IN ('submitted','reviewing','need_info') AND (? IS NULL OR c.submitted_at < ?) THEN c.amount_claimed ELSE 0 END), 0) AS pending,
                    SUM(c.status IN ('approved','partially_approved','paid','submitted','reviewing','need_info')) AS n
               FROM wf_claims c JOIN wf_types t ON t.id = c.type_id
              WHERE c.employee_id = ? AND c.legal_entity_id = ? AND t.code = ? AND c.expense_date BETWEEN ? AND ? AND c.id <> ?",
            [$sentBefore, $sentBefore, $employeeId, $le, $code, $year . '-01-01', $year . '-12-31', $excludeClaimId]
        ) ?? [];
        return ['used' => Money::fromDb($r['used'] ?? 0), 'pending' => Money::fromDb($r['pending'] ?? 0), 'count' => (int) ($r['n'] ?? 0)];
    }

    /**
     * The person's types with the limit left for a year (types in force today — or on 31 December for a past year).
     *
     * @return list<array<string, mixed>>
     */
    public function balances(int $employeeId, int $le, int $year): array
    {
        $today = $this->types()->today();
        $day = (int) substr($today, 0, 4) === $year ? $today : min($year . '-12-31', $today);
        $out = [];
        foreach ($this->types()->inForce($le, $day) as $t) {
            $u = $this->usage($employeeId, $le, (string) $t['code'], $year);
            $limit = $t['limit_year'] !== null ? Money::fromDb($t['limit_year']) : null;
            $out[] = ['type' => $t, 'limit' => $limit, 'used' => $u['used'], 'pending' => $u['pending'], 'count' => $u['count'],
                'remaining' => $limit !== null ? max(0, $limit - $u['used'] - $u['pending']) : null];
        }
        return $out;
    }

    // ---- checks -----------------------------------------------------------------------------------------------------------------

    private static function norm(string $v): string
    {
        return (string) preg_replace('/[\s\-\/.#]+/u', '', mb_strtoupper(trim($v)));
    }

    public static function receiptKey(int $le, ?string $no, ?string $from): ?string
    {
        return $no === null || self::norm($no) === '' ? null : hash('sha256', $le . '|' . self::norm($no) . '|' . self::norm((string) $from));
    }

    /**
     * Everything the reviewer and the person need to know before the claim goes on: eligibility, limits, duplicates.
     * Blocking reasons stop the claim from being sent; warnings go to the reviewer.
     *
     * @param array<string, mixed> $c wf_claims row
     * @return array<string, mixed>
     */
    public function check(array $c): array
    {
        $db = $this->app->db();
        $le = (int) $c['legal_entity_id'];
        $date = (string) $c['expense_date'];
        $today = $this->types()->today();
        $t = $db->one('SELECT * FROM wf_types WHERE id = ?', [$c['type_id']]) ?? [];
        $block = [];
        $warn = [];
        $inForce = $this->types()->inForceOn($le, (string) ($t['code'] ?? ''), $date);
        if ($inForce === null || (int) $inForce['id'] !== (int) $c['type_id']) {
            $block[] = 'type';
        }
        if ($date > $today) {
            $block[] = 'future';
        }
        if ($t !== [] && $t['window_days'] !== null) {
            $last = TypeService::ymd($date)?->modify('+' . (int) $t['window_days'] . ' days')->format('Y-m-d');
            if ($last !== null && $today > $last) {
                $block[] = 'window';
            }
        }
        $emp = $db->one('SELECT hire_date, termination_date FROM employees WHERE id = ?', [$c['employee_id']]) ?? [];
        if ($t !== [] && $t['min_service_days'] !== null && isset($emp['hire_date'])) {
            $from = TypeService::ymd((string) $emp['hire_date'])?->modify('+' . (int) $t['min_service_days'] . ' days')->format('Y-m-d');
            if ($from !== null && $date < $from) {
                $block[] = 'service';
            }
        }
        if (isset($emp['hire_date']) && $date < (string) $emp['hire_date']) {
            $block[] = 'not_employed';
        }
        $job = $this->jobOn((int) $c['employee_id'], $date);
        if ($t !== [] && $t['employment_types'] !== null && !in_array((string) ($job['employment_type'] ?? ''), explode(',', (string) $t['employment_types']), true)) {
            $block[] = 'employment_type';
        }
        $files = $db->all('SELECT id, sha256 FROM wf_claim_files WHERE claim_id = ? AND removed_at IS NULL', [$c['id']]);
        if ($t !== [] && (int) $t['receipt_required'] === 1 && $files === []) {
            $block[] = 'receipt';
        }
        // duplicates: the same file / receipt number on another live claim of the company
        $live = "('draft','cancelled','rejected')";
        foreach ($files as $f) {
            if ($db->scalar("SELECT c.id FROM wf_claim_files f JOIN wf_claims c ON c.id = f.claim_id WHERE f.sha256 = ? AND f.removed_at IS NULL AND c.id <> ? AND c.legal_entity_id = ? AND c.status NOT IN {$live} LIMIT 1", [$f['sha256'], $c['id'], $le]) !== null) {
                $block[] = 'duplicate_file';
                break;
            }
        }
        if ($c['receipt_key'] !== null && $db->scalar("SELECT id FROM wf_claims WHERE receipt_key = ? AND legal_entity_id = ? AND id <> ? AND status NOT IN {$live} LIMIT 1", [$c['receipt_key'], $le, $c['id']]) !== null) {
            $block[] = 'duplicate_receipt';
        }
        if (
            $db->scalar(
                "SELECT c.id FROM wf_claims c JOIN wf_types t ON t.id = c.type_id WHERE c.employee_id = ? AND t.code = ? AND c.expense_date = ? AND c.amount_claimed = ? AND c.id <> ? AND c.status NOT IN {$live} LIMIT 1",
                [$c['employee_id'], $t['code'] ?? '', $date, $c['amount_claimed'], $c['id']]
            ) !== null
        ) {
            $warn[] = 'same_day_amount';
        }
        // limits
        $claimed = Money::fromDb($c['amount_claimed']);
        $sent = in_array($c['status'], self::OPEN, true) && $c['submitted_at'] !== null ? (string) $c['submitted_at'] : null;
        $u = $this->usage((int) $c['employee_id'], $le, (string) ($t['code'] ?? ''), (int) substr($date, 0, 4), (int) $c['id'], $sent);
        $limitYear = ($t['limit_year'] ?? null) !== null ? Money::fromDb($t['limit_year']) : null;
        $limitEvent = ($t['limit_event'] ?? null) !== null ? Money::fromDb($t['limit_event']) : null;
        $remaining = $limitYear !== null ? max(0, $limitYear - $u['used'] - $u['pending']) : null;
        if (($t['max_claims'] ?? null) !== null && $u['count'] >= (int) $t['max_claims']) {
            $block[] = 'max_claims';
        }
        $eligible = $claimed;
        if ($limitEvent !== null) {
            $eligible = min($eligible, $limitEvent);
        }
        if ($remaining !== null) {
            $eligible = min($eligible, $remaining);
        }
        if ($remaining !== null && $remaining <= 0) {
            $block[] = 'no_balance';
        }
        if ($eligible < $claimed && $eligible > 0) {
            $warn[] = 'over_limit';
        }
        return ['ok' => $block === [], 'blocking' => array_values(array_unique($block)), 'warnings' => $warn, 'limit_year' => $limitYear, 'limit_event' => $limitEvent,
            'used' => $u['used'], 'pending' => $u['pending'], 'count' => $u['count'], 'max_claims' => $t['max_claims'] ?? null, 'remaining' => $remaining,
            'claimed' => $claimed, 'eligible' => max(0, $eligible), 'files' => count($files), 'checked_at' => $today];
    }

    // ---- the person writes and sends -------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in type (code), expense_date, description, amount, receipt_no, receipt_from, payee_name
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [input row values, type version]
     */
    private function validate(int $le, array $in): array
    {
        $e = [];
        $date = trim((string) ($in['expense_date'] ?? ''));
        if (TypeService::ymd($date) === null || $date < '2000-01-01') {
            $e['expense_date'] = 'welfare.error.date';
        } elseif ($date > $this->types()->today()) {
            $e['expense_date'] = 'welfare.error.future';
        }
        $code = strtoupper(trim((string) ($in['type'] ?? '')));
        $type = !isset($e['expense_date']) ? $this->types()->inForceOn($le, $code, $date) : null;
        if ($code === '') {
            $e['type'] = 'welfare.error.type_required';
        } elseif ($type === null && !isset($e['expense_date'])) {
            $e['type'] = 'welfare.error.type_not_in_force';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) < 3 || mb_strlen($desc) > 500) {
            $e['description'] = 'welfare.error.description';
        }
        $amount = Money::parse(trim((string) ($in['amount'] ?? '')), 2);
        if ($amount === null || $amount <= 0 || $amount > self::MAX_AMOUNT) {
            $e['amount'] = 'welfare.error.money';
        }
        $no = trim((string) ($in['receipt_no'] ?? ''));
        if (mb_strlen($no) > 60) {
            $e['receipt_no'] = 'welfare.error.text';
        }
        $from = trim((string) ($in['receipt_from'] ?? ''));
        $payee = trim((string) ($in['payee_name'] ?? ''));
        if (mb_strlen($from) > 160) {
            $e['receipt_from'] = 'welfare.error.text';
        }
        if (mb_strlen($payee) > 160) {
            $e['payee_name'] = 'welfare.error.text';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return [['expense_date' => $date, 'description' => $desc, 'amount_claimed' => Money::toDb((int) $amount), 'receipt_no' => $no === '' ? null : $no,
            'receipt_from' => $from === '' ? null : $from, 'receipt_key' => self::receiptKey($le, $no === '' ? null : $no, $from), 'payee_name' => $payee === '' ? null : $payee], $type ?? []];
    }

    /**
     * @param list<UploadedFile> $files
     * @param array<string, mixed> $type
     */
    private function addFiles(int $claimId, int $le, array $type, array $files, int $actorId): void
    {
        $db = $this->app->db();
        $have = array_column($db->all('SELECT sha256 FROM wf_claim_files WHERE claim_id = ? AND removed_at IS NULL', [$claimId]), 'sha256');
        $class = ($type['category'] ?? '') === 'medical' ? 'welfare.receipt_health' : 'welfare.receipt';
        foreach ($files as $f) {
            if (!$f->ok()) {
                throw new ValidationException(['files' => $f->errorKey()]);
            }
            $bytes = $f->bytes();
            $hash = hash('sha256', $bytes);
            if (in_array($hash, $have, true)) {
                continue;
            }
            if (count($have) >= self::MAX_FILES) {
                throw new ValidationException(['files' => 'welfare.error.too_many_files']);
            }
            try {
                $stored = $this->app->documents()->storeGenerated($actorId, $class, $bytes, $f->safeName(), ['title' => 'Welfare receipt', 'legal_entity_id' => $le], 'upload', true);
            } catch (ValidationException $e) {
                throw new ValidationException(['files' => (string) (array_values($e->errors)[0] ?? 'welfare.error.file')]);
            }
            $db->run('INSERT INTO wf_claim_files (claim_id, document_pid, sha256, filename, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?)', [$claimId, $stored['document'], $hash, mb_substr($f->safeName(), 0, 200), $actorId, $this->now()]);
            $have[] = $hash;
        }
    }

    /**
     * @param array<string, mixed> $in
     * @param list<UploadedFile> $files
     */
    public function create(Authorizer $az, array $in, array $files): string
    {
        $me = $this->me($az->userId) ?? throw new BusinessException('welfare.error.no_employee', [], 409);
        $le = $me['le'];
        [$row, $type] = $this->validate($le, $in);
        $db = $this->app->db();
        return $db->transactional(function () use ($db, $row, $type, $me, $le, $files, $az): string {
            $pid = Ulid::generate($this->app->clock);
            $now = $this->now();
            $db->run(
                "INSERT INTO wf_claims (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, employee_id, type_id, expense_date, description, amount_claimed, receipt_no, receipt_from, receipt_key, payee_name, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $me['employee']['id'], $type['id'], $row['expense_date'], $row['description'], $row['amount_claimed'], $row['receipt_no'], $row['receipt_from'], $row['receipt_key'], $row['payee_name']]
            );
            $id = (int) $db->pdo()->lastInsertId();
            $this->addFiles($id, $le, $type, $files, $az->userId);
            $this->event($id, 'created', $az->userId);
            return $pid;
        });
    }

    /** @return array<string, mixed> a claim the person owns and may still change */
    private function own(Authorizer $az, string $pid, bool $editable): array
    {
        $c = $this->app->db()->one('SELECT * FROM wf_claims WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $emp = $this->app->db()->scalar('SELECT employee_id FROM users WHERE id = ?', [$az->userId]);
        if ($emp === null || (int) $emp !== (int) $c['employee_id']) {
            throw HttpException::notFound();
        }
        if ($editable && !in_array($c['status'], self::EDITABLE, true)) {
            throw new BusinessException('welfare.error.not_editable', [], 409);
        }
        return $c;
    }

    /**
     * @param array<string, mixed> $in
     * @param list<UploadedFile> $files
     */
    public function update(Authorizer $az, string $pid, array $in, array $files): void
    {
        $c = $this->own($az, $pid, true);
        $le = (int) $c['legal_entity_id'];
        [$row, $type] = $this->validate($le, $in);
        $db = $this->app->db();
        $db->transactional(function () use ($db, $c, $row, $type, $le, $files, $az): void {
            $db->run(
                'UPDATE wf_claims SET type_id = ?, expense_date = ?, description = ?, amount_claimed = ?, receipt_no = ?, receipt_from = ?, receipt_key = ?, payee_name = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [$type['id'], $row['expense_date'], $row['description'], $row['amount_claimed'], $row['receipt_no'], $row['receipt_from'], $row['receipt_key'], $row['payee_name'], $this->now(), $az->userId, $c['id']]
            );
            $this->addFiles((int) $c['id'], $le, $type, $files, $az->userId);
            $this->event((int) $c['id'], 'edited', $az->userId);
        });
    }

    public function removeFile(Authorizer $az, string $pid, int $fileId): void
    {
        $c = $this->own($az, $pid, true);
        $n = $this->app->db()->run('UPDATE wf_claim_files SET removed_at = ? WHERE id = ? AND claim_id = ? AND removed_at IS NULL', [$this->now(), $fileId, $c['id']])->rowCount();
        if ($n === 1) {
            $this->event((int) $c['id'], 'file_removed', $az->userId);
        }
    }

    /** Sends a draft (or a claim sent back for more information) after the checks pass. */
    public function submit(Authorizer $az, string $pid): void
    {
        $c = $this->own($az, $pid, true);
        $check = $this->check($c);
        if (!$check['ok']) {
            throw new BusinessException('welfare.error.check_failed', [], 409);
        }
        $db = $this->app->db();
        $le = (int) $c['legal_entity_id'];
        $type = $db->one('SELECT * FROM wf_types WHERE id = ?', [$c['type_id']]) ?? [];
        $db->transactional(function () use ($db, $c, $check, $az, $le, $type): void {
            $now = $this->now();
            $no = $c['claim_no'] ?? $this->app->numbering()->next($le, null, 'welfare.claim', substr($this->types()->today(), 0, 4), 'WF-{YYYY}-{SEQ:5}', 'wf_claim', (string) $c['public_id'], $az->userId);
            $db->run(
                "UPDATE wf_claims SET claim_no = ?, status = 'submitted', check_json = ?, submitted_at = COALESCE(submitted_at, ?), proposed_amount = NULL, reviewed_by = NULL, reviewed_at = NULL, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?",
                [$no, json_encode($check), $now, $now, $az->userId, $c['id']]
            );
            $emp = $db->one('SELECT employee_code FROM employees WHERE id = ?', [$c['employee_id']]) ?? [];
            $title = $no . ' · ' . ($type['name_th'] ?? '') . ' · ' . ($emp['employee_code'] ?? '');
            if ($c['status'] === 'need_info' && $c['workflow_pid'] !== null) {
                $this->app->workflow()->resubmit((string) $c['workflow_pid'], $az->userId, '');
                $this->event((int) $c['id'], 'resubmitted', $az->userId);
            } else {
                $job = $this->jobOn((int) $c['employee_id'], (string) $c['expense_date']) ?? [];
                $inst = $this->app->workflow()->start('welfare.claim', (string) $c['public_id'], $title, $le, $az->userId, isset($job['site_id']) ? (int) $job['site_id'] : null, isset($job['department_id']) ? (int) $job['department_id'] : null);
                $db->run('UPDATE wf_claims SET workflow_pid = ? WHERE id = ?', [$inst, $c['id']]);
                $this->event((int) $c['id'], 'submitted', $az->userId);
            }
        });
        $this->app->audit()->record('welfare.claim_submitted', 'success', 'wf_claim', $pid, $le, null, ['type' => $type['code'] ?? null, 'warnings' => $check['warnings']]);
    }

    /** The person withdraws a claim before a decision. */
    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $c = $this->own($az, $pid, false);
        if (!in_array($c['status'], ['draft', 'submitted', 'reviewing', 'need_info'], true)) {
            throw new BusinessException('welfare.error.not_cancellable', [], 409);
        }
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 300) : $this->app->translator()->get('welfare.cancel_default');
        if ($c['workflow_pid'] !== null && $c['status'] !== 'draft') {
            $this->app->workflow()->cancel((string) $c['workflow_pid'], $az->userId, $reason); // → onClosed('cancelled')
        }
        $this->app->db()->run("UPDATE wf_claims SET status = 'cancelled', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status <> 'cancelled'", [$this->now(), $az->userId, $c['id']]);
        $this->event((int) $c['id'], 'cancelled', $az->userId, $reason);
        $this->app->audit()->record('welfare.claim_cancelled', 'success', 'wf_claim', $pid, (int) $c['legal_entity_id'], $reason);
    }

    // ---- review and approval ------------------------------------------------------------------------------------------------------

    /**
     * The workflow request of a claim with its current step and whether I have an open task on it.
     *
     * @param array<string, mixed> $c
     * @return array{pid: string, status: string, step: int, mine: bool}|null
     */
    public function task(array $c, int $userId): ?array
    {
        if ($c['workflow_pid'] === null) {
            return null;
        }
        $db = $this->app->db();
        $inst = $db->one('SELECT id, public_id, status, current_step FROM workflow_instances WHERE public_id = ?', [$c['workflow_pid']]);
        if ($inst === null) {
            return null;
        }
        $mine = $inst['status'] === 'pending' && $db->scalar("SELECT id FROM workflow_tasks WHERE instance_id = ? AND step_no = ? AND assignee_id = ? AND status = 'open'", [$inst['id'], $inst['current_step'], $userId]) !== null;
        return ['pid' => (string) $inst['public_id'], 'status' => (string) $inst['status'], 'step' => (int) $inst['current_step'], 'mine' => $mine];
    }

    /**
     * Keeps the claim status in step with its request when someone decided from the central approvals page:
     * past step 1 = the documents were reviewed.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    public function sync(array $c): array
    {
        if ($c['status'] !== 'submitted' || $c['workflow_pid'] === null) {
            return $c;
        }
        $db = $this->app->db();
        $inst = $db->one('SELECT id, status, current_step FROM workflow_instances WHERE public_id = ?', [$c['workflow_pid']]);
        if ($inst !== null && $inst['status'] === 'pending' && (int) $inst['current_step'] >= 2) {
            $d = $db->one("SELECT actor_id, decided_at, comment FROM workflow_decisions WHERE instance_id = ? AND step_no = 1 AND decision = 'approve' ORDER BY id DESC LIMIT 1", [$inst['id']]);
            $db->run("UPDATE wf_claims SET status = 'reviewing', reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'submitted'", [$d['actor_id'] ?? null, $d['decided_at'] ?? $this->now(), $this->now(), $c['id']]);
            $this->event((int) $c['id'], 'reviewed', isset($d['actor_id']) ? (int) $d['actor_id'] : null, isset($d['comment']) ? (string) $d['comment'] : null);
            return $db->one('SELECT * FROM wf_claims WHERE id = ?', [$c['id']]) ?? $c;
        }
        return $c;
    }

    /**
     * A reviewer / approver decides from the claim page.
     *   step 1 (review):   pass | return (ask for more information — a note is required) | reject (note required)
     *   step 2 (approval): approve with an amount (≤ what the limits allow now) | return | reject
     */
    public function decide(Authorizer $az, string $pid, string $action, string $amount, string $note): void
    {
        $c = $this->sync($this->row($pid));
        $task = $this->task($c, $az->userId);
        if ($task === null || !$task['mine'] || !in_array($c['status'], ['submitted', 'reviewing'], true)) {
            throw new BusinessException('welfare.error.no_task', [], 403);
        }
        $note = trim($note);
        if (in_array($action, ['return', 'reject'], true) && mb_strlen($note) < 3) {
            throw new ValidationException(['note' => 'welfare.error.note_required']);
        }
        $db = $this->app->db();
        if ($task['step'] === 1) {
            if (!in_array($action, ['pass', 'return', 'reject'], true)) {
                throw new BusinessException('workflow.error.bad_decision', [], 400);
            }
            $this->app->workflow()->decide($task['pid'], $az->userId, $action === 'pass' ? 'approve' : $action, $note);
            if ($action === 'pass') {
                $now = $this->now();
                $db->run("UPDATE wf_claims SET status = 'reviewing', reviewed_by = ?, reviewed_at = ?, updated_at = ?, updated_by = ? WHERE id = ? AND status = 'submitted'", [$az->userId, $now, $now, $az->userId, $c['id']]);
                $this->event((int) $c['id'], 'reviewed', $az->userId, $note !== '' ? $note : null);
            }
            return;
        }
        if (!in_array($action, ['approve', 'return', 'reject'], true)) {
            throw new BusinessException('workflow.error.bad_decision', [], 400);
        }
        if ($action === 'approve') {
            $u = Money::parse(trim($amount), 2);
            $check = $this->check($c);
            if ($u === null || $u <= 0) {
                throw new ValidationException(['amount' => 'welfare.error.money']);
            }
            if ($u > $check['eligible']) {
                throw new ValidationException(['amount' => 'welfare.error.over_eligible']);
            }
            $db->run('UPDATE wf_claims SET proposed_amount = ?, updated_at = ? WHERE id = ?', [Money::toDb($u), $this->now(), $c['id']]);
        }
        $this->app->workflow()->decide($task['pid'], $az->userId, $action, $note);
    }

    /**
     * Workflow execution (ClaimSubject::onApproved): the approved amount is fixed — never more than claimed, the per-claim
     * limit or what is left of the yearly limit right now (checked under a lock on the person).
     */
    public function onApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $out = $db->transactional(function () use ($db, $pid, $approverId): ?array {
            $c = $db->one('SELECT * FROM wf_claims WHERE public_id = ? FOR UPDATE', [$pid]) ?? throw HttpException::notFound();
            if (in_array($c['status'], self::COUNTED, true)) {
                return null;
            }
            if (!in_array($c['status'], ['submitted', 'reviewing'], true)) {
                throw new BusinessException('welfare.error.not_open', [], 409);
            }
            $db->scalar('SELECT id FROM employees WHERE id = ? FOR UPDATE', [$c['employee_id']]);
            $check = $this->check($c);
            $claimed = Money::fromDb($c['amount_claimed']);
            $amount = min($c['proposed_amount'] !== null ? Money::fromDb($c['proposed_amount']) : $claimed, $check['eligible']);
            if ($amount <= 0) {
                throw new BusinessException('welfare.error.no_balance', [], 409);
            }
            $inst = $db->one('SELECT id FROM workflow_instances WHERE public_id = ?', [$c['workflow_pid']]);
            $note = $inst !== null ? $db->scalar("SELECT comment FROM workflow_decisions WHERE instance_id = ? AND decision = 'approve' ORDER BY id DESC LIMIT 1", [$inst['id']]) : null;
            $reviewer = $c['reviewed_by'] ?? ($inst !== null ? $db->scalar("SELECT actor_id FROM workflow_decisions WHERE instance_id = ? AND step_no = 1 AND decision = 'approve' ORDER BY id DESC LIMIT 1", [$inst['id']]) : null);
            $status = $amount < $claimed ? 'partially_approved' : 'approved';
            $now = $this->now();
            $db->run(
                'UPDATE wf_claims SET status = ?, approved_amount = ?, reviewed_by = COALESCE(reviewed_by, ?), decided_by = ?, decided_at = ?, decision_note = ?, check_json = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?',
                [$status, Money::toDb($amount), $reviewer, $approverId, $now, $note !== null && $note !== '' ? mb_substr((string) $note, 0, 500) : null, json_encode($check), $now, $c['id']]
            );
            $this->event((int) $c['id'], $status, $approverId, $note !== null && $note !== '' ? (string) $note : null);
            return ['c' => $c, 'partial' => $status === 'partially_approved'];
        });
        if ($out === null) {
            return;
        }
        $c = $out['c'];
        $this->app->audit()->record('welfare.claim_approved', 'success', 'wf_claim', $pid, (int) $c['legal_entity_id'], null, ['partial' => $out['partial']], $approverId);
        $this->notifyOwner($c, 'welfare.approved', 'welfare.notify.approved');
    }

    /** @param 'rejected'|'returned'|'cancelled' $outcome */
    public function onClosed(string $pid, string $outcome): void
    {
        $db = $this->app->db();
        $c = $db->one('SELECT * FROM wf_claims WHERE public_id = ?', [$pid]);
        if ($c === null) {
            return;
        }
        $inst = $db->one('SELECT id FROM workflow_instances WHERE public_id = ?', [$c['workflow_pid']]);
        $d = $inst !== null ? $db->one('SELECT actor_id, comment FROM workflow_decisions WHERE instance_id = ? ORDER BY id DESC LIMIT 1', [$inst['id']]) : null;
        $note = isset($d['comment']) && $d['comment'] !== '' ? mb_substr((string) $d['comment'], 0, 500) : null;
        $now = $this->now();
        if ($outcome === 'rejected') {
            $db->run("UPDATE wf_claims SET status = 'rejected', decided_by = ?, decided_at = ?, decision_note = ?, updated_at = ? WHERE id = ? AND status IN ('submitted','reviewing')", [$d['actor_id'] ?? null, $now, $note, $now, $c['id']]);
            $this->event((int) $c['id'], 'rejected', isset($d['actor_id']) ? (int) $d['actor_id'] : null, $note);
        } elseif ($outcome === 'returned') {
            $db->run("UPDATE wf_claims SET status = 'need_info', decision_note = ?, proposed_amount = NULL, updated_at = ? WHERE id = ? AND status IN ('submitted','reviewing')", [$note, $now, $c['id']]);
            $this->event((int) $c['id'], 'need_info', isset($d['actor_id']) ? (int) $d['actor_id'] : null, $note);
        } else {
            $db->run("UPDATE wf_claims SET status = 'cancelled', updated_at = ? WHERE id = ? AND status IN ('submitted','reviewing','need_info')", [$now, $c['id']]);
        }
    }

    public function onResubmitted(string $pid): void
    {
        $this->app->db()->run("UPDATE wf_claims SET status = 'submitted', updated_at = ? WHERE public_id = ? AND status = 'need_info'", [$this->now(), $pid]);
    }

    /** @param array<string, mixed> $c */
    public function notifyOwner(array $c, string $event, string $key): void
    {
        foreach ($this->app->db()->all("SELECT id FROM users WHERE employee_id = ? AND status = 'active'", [$c['employee_id']]) as $u) {
            $this->app->notifier()->notify((int) $u['id'], $event, $key, ['no' => (string) ($c['claim_no'] ?? '')], '/welfare/claims/' . $c['public_id'], $event . '-' . $c['public_id']);
        }
    }

    // ---- reading --------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM wf_claims WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * A claim the person may see: their own, or any of a company they review / approve / pay / report for.
     *
     * @return array<string, mixed>
     */
    public function view(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $c = $this->sync($this->row($pid));
        $le = (int) $c['legal_entity_id'];
        $emp = $db->scalar('SELECT employee_id FROM users WHERE id = ?', [$az->userId]);
        $own = $emp !== null && (int) $emp === (int) $c['employee_id'];
        $staff = $az->scope('welfare.review')->coversLegalEntity($le) || $az->scope('welfare.approve')->coversLegalEntity($le);
        if (!$own && !$staff) {
            throw HttpException::notFound();
        }
        $c['own'] = $own;
        $c['staff'] = $staff;
        $c['type'] = $db->one('SELECT * FROM wf_types WHERE id = ?', [$c['type_id']]) ?? [];
        $c['employee'] = $db->one('SELECT id, public_id, employee_code, name_th FROM employees WHERE id = ?', [$c['employee_id']]) ?? [];
        $c['le_code'] = (string) $db->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $c['files'] = $db->all('SELECT id, filename, added_at FROM wf_claim_files WHERE claim_id = ? AND removed_at IS NULL ORDER BY id', [$c['id']]);
        $c['events'] = $db->all('SELECT e.*, u.display_name FROM wf_claim_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.claim_id = ? ORDER BY e.id', [$c['id']]);
        $c['check'] = in_array($c['status'], ['draft', 'need_info', 'submitted', 'reviewing'], true) ? $this->check($c) : (json_decode((string) ($c['check_json'] ?? 'null'), true) ?: null);
        $c['task'] = $this->task($c, $az->userId);
        $c['batch'] = $c['batch_id'] !== null ? $db->one('SELECT public_id, batch_no, method, status FROM wf_batches WHERE id = ?', [$c['batch_id']]) : null;
        foreach (['reviewed_by', 'decided_by'] as $k) {
            $c[$k . '_name'] = $c[$k] !== null ? (string) $db->scalar('SELECT display_name FROM users WHERE id = ?', [$c[$k]]) : null;
        }
        return $c;
    }

    /** @return array{bytes: string, mime: string, filename: string, health: bool} a receipt the person may see */
    public function file(Authorizer $az, string $pid, int $fileId): array
    {
        $c = $this->view($az, $pid);
        $f = $this->app->db()->one('SELECT * FROM wf_claim_files WHERE id = ? AND claim_id = ?', [$fileId, $c['id']]) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $f['document_pid'], 'welfare', $az->userId, $c['own'] ? 'own welfare claim' : 'welfare claim check');
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => (string) $f['filename'], 'health' => ($c['type']['category'] ?? '') === 'medical'];
    }

    /** @return list<array<string, mixed>> my claims of a year (by expense date) */
    public function mine(int $employeeId, int $year): array
    {
        return array_map(fn (array $c): array => $this->sync($c) + ['type_name' => $c['type_name'], 'type_code' => $c['type_code']], $this->app->db()->all(
            'SELECT c.*, t.name_th AS type_name, t.code AS type_code FROM wf_claims c JOIN wf_types t ON t.id = c.type_id WHERE c.employee_id = ? AND c.expense_date BETWEEN ? AND ? ORDER BY c.id DESC',
            [$employeeId, $year . '-01-01', $year . '-12-31']
        ));
    }

    /**
     * Claims of a company for the review screen.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(Authorizer $az, int $le, string $filter): array
    {
        if (!$az->scope('welfare.review')->coversLegalEntity($le) && !$az->scope('welfare.approve')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $where = [
            'review' => "c.status = 'submitted'", 'approve' => "c.status = 'reviewing'", 'info' => "c.status = 'need_info'",
            'approved' => "c.status IN ('approved','partially_approved')", 'paid' => "c.status = 'paid'", 'closed' => "c.status IN ('rejected','cancelled')",
            'all' => "c.status <> 'draft'",
        ][$filter] ?? "c.status IN ('submitted','reviewing')";
        $db = $this->app->db();
        $rows = $db->all(
            "SELECT c.*, t.name_th AS type_name, t.code AS type_code, t.category, e.employee_code, e.name_th AS employee_name FROM wf_claims c
               JOIN wf_types t ON t.id = c.type_id JOIN employees e ON e.id = c.employee_id WHERE c.legal_entity_id = ? AND {$where} ORDER BY c.submitted_at DESC, c.id DESC LIMIT 300",
            [$le]
        );
        $out = [];
        foreach ($rows as $c) {
            $c = $this->sync($c) + ['type_name' => $c['type_name'], 'type_code' => $c['type_code'], 'category' => $c['category'], 'employee_code' => $c['employee_code'], 'employee_name' => $c['employee_name']];
            $c['task'] = $this->task($c, $az->userId);
            $c['flags'] = json_decode((string) ($c['check_json'] ?? 'null'), true)['warnings'] ?? [];
            $out[] = $c;
        }
        return $out;
    }

    /** @return array<string, int> counts per screen filter */
    public function counts(int $le): array
    {
        $out = ['review' => 0, 'approve' => 0, 'info' => 0, 'approved' => 0];
        foreach ($this->app->db()->all("SELECT status, COUNT(*) AS n FROM wf_claims WHERE legal_entity_id = ? AND status IN ('submitted','reviewing','need_info','approved','partially_approved') GROUP BY status", [$le]) as $r) {
            $k = ['submitted' => 'review', 'reviewing' => 'approve', 'need_info' => 'info', 'approved' => 'approved', 'partially_approved' => 'approved'][(string) $r['status']];
            $out[$k] += (int) $r['n'];
        }
        return $out;
    }
}
