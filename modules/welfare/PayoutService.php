<?php

declare(strict_types=1);

namespace Blue\Modules\Welfare;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Modules\Payroll\PayrollService;

/**
 * Paying approved welfare claims (M37 · DEC-86) — by someone with welfare.pay who is NOT the approver or the
 * claimant of any claim in the batch (segregation of duties).
 *
 *  payroll  a frozen batch (lines + SHA-256) that a payroll run takes as a source, like OT batches; the amounts become
 *           earnings on each type's component. When that run is approved the claims are paid.
 *  payment  a payment batch (core Payments, type "claim") per person to the bank account on their approved pay profile;
 *           the batch then needs its own approval, bank file and results. Paid items mark their claims paid; failed or
 *           returned items free their claims for a new batch.
 * sync() reads the payroll runs / payment batches and is run every hour and when the payout screen opens.
 */
final class PayoutService
{
    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public static function syncJob(App $app): int
    {
        return self::of($app)->sync(null);
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    /** @return array{payroll: list<array<string, mixed>>, payment: list<array<string, mixed>>} approved claims not yet in a batch */
    public function ready(Authorizer $az, int $le): array
    {
        $this->require($az, 'welfare.pay', $le);
        $out = ['payroll' => [], 'payment' => []];
        foreach (
            $this->app->db()->all(
                "SELECT c.*, t.name_th AS type_name, t.code AS type_code, t.payout, t.component_code, e.employee_code, e.name_th AS employee_name
                   FROM wf_claims c JOIN wf_types t ON t.id = c.type_id JOIN employees e ON e.id = c.employee_id
                  WHERE c.legal_entity_id = ? AND c.status IN ('approved','partially_approved') AND c.batch_id IS NULL ORDER BY e.employee_code, c.claim_no",
                [$le]
            ) as $c
        ) {
            $c['blocked'] = (int) $c['decided_by'] === $az->userId || (int) $c['reviewed_by'] === $az->userId || (int) $c['created_by'] === $az->userId;
            $out[(string) $c['payout']][] = $c;
        }
        return $out;
    }

    /**
     * Puts chosen approved claims into a batch.
     *
     * @param list<string> $claimPids
     */
    public function create(Authorizer $az, int $le, string $method, array $claimPids, string $payDate, string $note): string
    {
        $this->require($az, 'welfare.pay', $le);
        if (!in_array($method, ['payroll', 'payment'], true)) {
            throw new ValidationException(['method' => 'form.invalid_choice']);
        }
        $ready = $this->ready($az, $le)[$method];
        $pick = array_values(array_filter($ready, static fn (array $c): bool => in_array((string) $c['public_id'], $claimPids, true)));
        if ($pick === [] || count($pick) !== count(array_unique($claimPids))) {
            throw new ValidationException(['claims' => 'welfare.error.pick_claims']);
        }
        foreach ($pick as $c) {
            if ($c['blocked']) {
                throw new BusinessException('welfare.error.payer_sod', ['no' => (string) $c['claim_no']], 403);
            }
        }
        if ($method === 'payment' && (!PayrollService::isDate($payDate) || $payDate < PayrollService::of($this->app)->today())) {
            throw new ValidationException(['pay_date' => 'welfare.error.pay_date']);
        }
        $lines = [];
        $total = 0;
        foreach ($pick as $c) {
            $amount = Money::fromDb($c['approved_amount']);
            $lines[] = ['claim_no' => (string) $c['claim_no'], 'claim' => (string) $c['public_id'], 'employee_id' => (int) $c['employee_id'], 'employee_code' => (string) $c['employee_code'],
                'name' => (string) $c['employee_name'], 'type' => (string) $c['type_code'], 'component_code' => $method === 'payroll' ? (string) $c['component_code'] : null,
                'expense_date' => (string) $c['expense_date'], 'amount' => Money::toDb($amount)];
            $total += $amount;
        }
        $items = [];
        if ($method === 'payment') {
            $per = [];
            foreach ($lines as $l) {
                $per[$l['employee_id']] ??= ['code' => $l['employee_code'], 'name' => $l['name'], 'amount' => 0];
                $per[$l['employee_id']]['amount'] += Money::fromDb($l['amount']);
            }
            $missing = [];
            foreach ($per as $eid => $p) {
                $bank = PayrollService::of($this->app)->bankAccount((int) $eid, $le, PayrollService::of($this->app)->today());
                if ($bank === null) {
                    $missing[] = $p['code'];
                    continue;
                }
                $site = $this->app->db()->scalar('SELECT site_id FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$eid]);
                $items[] = ['payee_type' => 'employee', 'payee_code' => $p['code'], 'payee_name' => $p['name'], 'site_id' => $site !== null ? (int) $site : null,
                    'bank_code' => $bank['bank_code'], 'account_no' => $bank['account_no'], 'amount' => Money::format($p['amount'], 2, false)];
            }
            if ($missing !== []) {
                throw new BusinessException('welfare.error.no_bank', ['codes' => implode(', ', $missing)], 409);
            }
            if (!$az->scope('core.payment.prepare')->coversLegalEntity($le)) {
                throw new BusinessException('welfare.error.no_payment_right', [], 403);
            }
        }
        $json = json_encode($lines, JSON_UNESCAPED_UNICODE) ?: '[]';
        $db = $this->app->db();
        $pid = Ulid::generate($this->app->clock);
        $year = substr(PayrollService::of($this->app)->today(), 0, 4);
        $no = $this->app->numbering()->next($le, null, 'welfare.batch', $year, 'WFB-{YYYY}-{SEQ:4}', 'wf_batch', $pid, $az->userId);
        $db->transactional(function () use ($db, $pid, $no, $le, $method, $lines, $json, $total, $note, $pick, $az, $items, $payDate): void {
            $now = $this->now();
            $db->run(
                "INSERT INTO wf_batches (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, batch_no, method, status, claim_count, total_amount, lines_json, lines_hash, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ready', ?, ?, ?, ?, ?)",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $no, $method, count($lines), Money::toDb($total), $json, hash('sha256', $json), $note !== '' ? mb_substr($note, 0, 300) : null]
            );
            $bid = (int) $db->pdo()->lastInsertId();
            foreach ($pick as $c) {
                $n = $db->run("UPDATE wf_claims SET batch_id = ?, updated_at = ? WHERE id = ? AND batch_id IS NULL AND status IN ('approved','partially_approved')", [$bid, $now, $c['id']])->rowCount();
                if ($n !== 1) {
                    throw new BusinessException('welfare.error.claim_taken', [], 409);
                }
                $db->run('INSERT INTO wf_claim_events (claim_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$c['id'], 'batched', $az->userId, $no, $now]);
            }
            if ($method === 'payment') {
                $payment = $this->app->payments()->createFromSource([
                    'legal_entity_id' => $le, 'payment_type' => 'claim', 'source_type' => 'welfare_batch', 'source_id' => $pid, 'idempotency_key' => 'welfare.batch:' . $pid,
                    'pay_date' => $payDate, 'period_label' => $no, 'currency_code' => 'THB', 'expected_total' => Money::format($total, 2, false), 'actor_user_id' => $az->userId, 'items' => $items,
                ]);
                $db->run("UPDATE wf_batches SET payment_batch_pid = ?, status = 'sent' WHERE id = ?", [$payment, $bid]);
            }
        });
        $this->app->audit()->record('welfare.batch_created', 'success', 'wf_batch', $pid, $le, null, ['method' => $method, 'claims' => count($lines)]);
        return $pid;
    }

    /** A payroll batch no run uses yet can be taken back (claims free again). */
    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $db = $this->app->db();
        $b = $db->one('SELECT * FROM wf_batches WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $le = (int) $b['legal_entity_id'];
        $this->require($az, 'welfare.pay', $le);
        if ($b['method'] !== 'payroll' || $b['status'] !== 'ready' || $this->runOf((int) $b['id'], $le) !== null) {
            throw new BusinessException('welfare.error.batch_in_use', [], 409);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['reason' => 'welfare.error.reason']);
        }
        $db->transactional(function () use ($db, $b, $az, $reason): void {
            $now = $this->now();
            foreach ($db->all('SELECT id FROM wf_claims WHERE batch_id = ?', [$b['id']]) as $c) {
                $db->run('INSERT INTO wf_claim_events (claim_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$c['id'], 'unbatched', $az->userId, mb_substr(trim($reason), 0, 300), $now]);
            }
            $db->run('UPDATE wf_claims SET batch_id = NULL, updated_at = ? WHERE batch_id = ?', [$now, $b['id']]);
            $db->run("UPDATE wf_batches SET status = 'closed', note = CONCAT_WS(' · ', note, ?), updated_at = ?, updated_by = ? WHERE id = ?", [mb_substr(trim($reason), 0, 200), $now, $az->userId, $b['id']]);
        });
        $this->app->audit()->record('welfare.batch_cancelled', 'success', 'wf_batch', $pid, $le, mb_substr(trim($reason), 0, 300));
    }

    /** @return array<string, mixed>|null the newest payroll run (not cancelled) that took the batch */
    private function runOf(int $batchId, int $le): ?array
    {
        return $this->app->db()->one(
            "SELECT public_id, label, status, pay_date FROM pay_runs WHERE legal_entity_id = ? AND FIND_IN_SET(?, welfare_batch_ids) > 0 AND status <> 'cancelled' ORDER BY id DESC LIMIT 1",
            [$le, $batchId]
        );
    }

    /**
     * Brings batches and claims up to date with payroll runs and payment batches. Returns how many claims changed.
     */
    public function sync(?int $le): int
    {
        $db = $this->app->db();
        $n = 0;
        $open = $db->all("SELECT * FROM wf_batches WHERE status IN ('ready','sent')" . ($le !== null ? ' AND legal_entity_id = ' . (int) $le : ''));
        foreach ($open as $b) {
            $now = $this->now();
            if ($b['method'] === 'payroll') {
                $run = $this->runOf((int) $b['id'], (int) $b['legal_entity_id']);
                if ($run !== null && $run['status'] === 'approved') {
                    $claims = $db->all("SELECT * FROM wf_claims WHERE batch_id = ? AND status IN ('approved','partially_approved')", [$b['id']]);
                    $db->transactional(function () use ($db, $b, $run, $now, $claims): void {
                        $db->run("UPDATE wf_claims SET status = 'paid', paid_at = ?, paid_ref = ?, updated_at = ? WHERE batch_id = ? AND status IN ('approved','partially_approved')", [$now, mb_substr((string) $run['label'], 0, 80), $now, $b['id']]);
                        foreach ($claims as $c) {
                            $db->run('INSERT INTO wf_claim_events (claim_id, event, actor_id, note, created_at) VALUES (?, ?, NULL, ?, ?)', [$c['id'], 'paid', (string) $run['label'], $now]);
                        }
                        $db->run("UPDATE wf_batches SET status = 'paid', pay_run_pid = ?, paid_at = ?, updated_at = ? WHERE id = ?", [$run['public_id'], $now, $now, $b['id']]);
                    });
                    foreach ($claims as $c) {
                        ClaimService::of($this->app)->notifyOwner($c, 'welfare.paid', 'welfare.notify.paid');
                    }
                    $n += count($claims);
                } else {
                    $status = $run !== null && $run['status'] === 'pending' ? 'sent' : 'ready';
                    $db->run('UPDATE wf_batches SET status = ?, pay_run_pid = ? WHERE id = ? AND status <> ?', [$status, $run['public_id'] ?? null, $b['id'], $status]);
                }
                continue;
            }
            // payment batch
            $p = $b['payment_batch_pid'] !== null ? $db->one('SELECT id, status, batch_no FROM pmt_batches WHERE public_id = ?', [$b['payment_batch_pid']]) : null;
            if ($p === null) {
                continue;
            }
            if ($p['status'] === 'cancelled') {
                $n += $this->release($b, null, 'payment_cancelled');
                $db->run("UPDATE wf_batches SET status = 'closed', updated_at = ? WHERE id = ?", [$now, $b['id']]);
                continue;
            }
            if ($p['status'] !== 'reconciled') {
                continue;
            }
            $paidCodes = array_column($db->all("SELECT payee_code FROM pmt_batch_items WHERE batch_id = ? AND status = 'paid'", [$p['id']]), 'payee_code');
            $claims = $db->all("SELECT c.*, e.employee_code FROM wf_claims c JOIN employees e ON e.id = c.employee_id WHERE c.batch_id = ? AND c.status IN ('approved','partially_approved')", [$b['id']]);
            $failed = false;
            foreach ($claims as $c) {
                if (in_array((string) $c['employee_code'], $paidCodes, true)) {
                    $db->run("UPDATE wf_claims SET status = 'paid', paid_at = ?, paid_ref = ?, updated_at = ? WHERE id = ?", [$now, (string) $p['batch_no'], $now, $c['id']]);
                    $db->run('INSERT INTO wf_claim_events (claim_id, event, actor_id, note, created_at) VALUES (?, ?, NULL, ?, ?)', [$c['id'], 'paid', (string) $p['batch_no'], $now]);
                    ClaimService::of($this->app)->notifyOwner($c, 'welfare.paid', 'welfare.notify.paid');
                } else {
                    $failed = true;
                    $db->run('UPDATE wf_claims SET batch_id = NULL, updated_at = ? WHERE id = ?', [$now, $c['id']]);
                    $db->run('INSERT INTO wf_claim_events (claim_id, event, actor_id, note, created_at) VALUES (?, ?, NULL, ?, ?)', [$c['id'], 'payment_failed', (string) $p['batch_no'], $now]);
                }
                $n++;
            }
            $db->run('UPDATE wf_batches SET status = ?, paid_at = ?, updated_at = ? WHERE id = ?', [$failed ? 'closed' : 'paid', $now, $now, $b['id']]);
        }
        return $n;
    }

    /** @param array<string, mixed> $b */
    private function release(array $b, ?int $actorId, string $event): int
    {
        $db = $this->app->db();
        $now = $this->now();
        $claims = $db->all('SELECT id FROM wf_claims WHERE batch_id = ?', [$b['id']]);
        foreach ($claims as $c) {
            $db->run('INSERT INTO wf_claim_events (claim_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?)', [$c['id'], $event, $actorId, (string) $b['batch_no'], $now]);
        }
        $db->run('UPDATE wf_claims SET batch_id = NULL, updated_at = ? WHERE batch_id = ?', [$now, $b['id']]);
        return count($claims);
    }

    /** @return list<array<string, mixed>> batches of a company, newest first */
    public function batches(Authorizer $az, int $le): array
    {
        $this->require($az, 'welfare.pay', $le);
        $this->sync($le);
        return array_map(function (array $b) use ($le): array {
            $b['run'] = $b['method'] === 'payroll' ? $this->runOf((int) $b['id'], $le) : null;
            return $b;
        }, $this->app->db()->all('SELECT b.*, u.display_name AS creator FROM wf_batches b LEFT JOIN users u ON u.id = b.created_by WHERE b.legal_entity_id = ? ORDER BY b.id DESC LIMIT 100', [$le]));
    }

    // ---- report -------------------------------------------------------------------------------------------------------------------

    /**
     * Use of welfare per person and type in a calendar year (by expense date): claims, claimed, approved, paid, limit.
     *
     * @return list<array<string, mixed>>
     */
    public function usage(Authorizer $az, int $le, int $year): array
    {
        $this->require($az, 'welfare.report', $le);
        $rows = $this->app->db()->all(
            "SELECT e.employee_code, e.name_th, t.code, MAX(t.name_th) AS type_name, COUNT(*) AS claims,
                    SUM(c.amount_claimed) AS claimed, SUM(COALESCE(c.approved_amount, 0)) AS approved,
                    SUM(CASE WHEN c.status = 'paid' THEN c.approved_amount ELSE 0 END) AS paid,
                    SUM(c.status IN ('submitted','reviewing','need_info')) AS waiting, SUM(c.status = 'rejected') AS rejected
               FROM wf_claims c JOIN wf_types t ON t.id = c.type_id JOIN employees e ON e.id = c.employee_id
              WHERE c.legal_entity_id = ? AND c.expense_date BETWEEN ? AND ? AND c.status NOT IN ('draft','cancelled')
              GROUP BY e.employee_code, e.name_th, t.code ORDER BY e.employee_code, t.code",
            [$le, $year . '-01-01', $year . '-12-31']
        );
        $day = min($year . '-12-31', PayrollService::of($this->app)->today());
        $limits = [];
        foreach (TypeService::of($this->app)->inForce($le, $day) as $t) {
            $limits[(string) $t['code']] = $t['limit_year'] !== null ? Money::fromDb($t['limit_year']) : null;
        }
        return array_map(static function (array $r) use ($limits): array {
            $r['limit'] = $limits[(string) $r['code']] ?? null;
            foreach (['claimed', 'approved', 'paid'] as $k) {
                $r[$k] = Money::fromDb($r[$k]);
            }
            $r['remaining'] = $r['limit'] !== null ? max(0, $r['limit'] - $r['approved']) : null;
            return $r;
        }, $rows);
    }

    /** @return array{bytes: string, name: string} the usage report as Excel (recorded in the audit log) */
    public function export(Authorizer $az, int $le, int $year): array
    {
        $rows = $this->usage($az, $le, $year);
        $t = $this->app->translator();
        $m = static fn (?int $u): string => $u === null ? '' : Money::format($u, 2, false);
        $code = (string) $this->app->db()->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $bytes = Xlsx::write(['usage' => [
            'headers' => [$t->get('welfare.col.employee'), $t->get('welfare.col.name'), $t->get('welfare.col.type'), $t->get('welfare.col.type_name'), $t->get('welfare.col.claims'),
                $t->get('welfare.col.claimed'), $t->get('welfare.col.approved'), $t->get('welfare.col.paid'), $t->get('welfare.col.limit'), $t->get('welfare.col.remaining'), $t->get('welfare.col.waiting')],
            'rows' => array_map(static fn (array $r): array => [(string) $r['employee_code'], (string) $r['name_th'], (string) $r['code'], (string) $r['type_name'], (string) $r['claims'],
                $m($r['claimed']), $m($r['approved']), $m($r['paid']), $m($r['limit']), $m($r['remaining']), (string) $r['waiting']], $rows),
            'numeric' => [4, 5, 6, 7, 8, 9, 10], 'widths' => [12, 28, 10, 28, 8, 14, 14, 14, 14, 14, 8],
        ]], 'Blue OS welfare usage');
        $this->app->audit()->record('welfare.report_exported', 'success', 'wf_report', $code . '-' . $year, $le, null, ['rows' => count($rows), 'sha256' => hash('sha256', $bytes)]);
        return ['bytes' => $bytes, 'name' => 'welfare-usage-' . $code . '-' . $year . '.xlsx'];
    }
}
