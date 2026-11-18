<?php

declare(strict_types=1);

namespace Blue\Modules\Eid;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;
use Throwable;

/**
 * M13 wallet (phase 5C, DEC-24 / DEC-71) — a CLOSED internal benefit balance per employee.
 *
 *  ledger    eid_wallet_entries is append-only: top-up (+), spend (−), refund (+, against one spend), reversal.
 *            The cached balance on eid_wallets is changed only in the same transaction, under a row lock, and the
 *            daily check compares it with the sum of entries. Every entry has an idempotency key: a repeated call
 *            (double click, retry, two tills) returns the first entry instead of moving money twice.
 *  top-up    a maker (eid.wallet_topup) lists employees + amounts within the company rules → a checker who is not the
 *            maker (eid.wallet_approve) approves → all lines are applied together (or none: the batch "failed") and a
 *            posting request goes to Finance Core (DEC-12, source eid.wallet / wallet_topup).
 *  spend     spend() / refund() are the interface POS (M22, phase 8) will call; there is no spending screen yet.
 *            Spending needs an active card (not suspended / lost / revoked / expired) and an employee who has not left;
 *            never below zero.
 * Money never leaves the wallet as cash and cannot move between people (DEC-24, pending the Legal / BOT review).
 */
final class WalletService
{
    public const MAX_LINES = 500;

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

    private function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    // ---- rules --------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null rules in force today (+ version_pid) */
    public function rules(int $le, ?string $date = null): ?array
    {
        $date ??= $this->emp()->today();
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', $this->tz()) ?: $this->app->clock->now();
        $r = $this->app->registry()->resolveOrNull('eid.wallet_rules', $le, null, $at);
        return $r !== null && is_array($r->value) ? $r->value + ['version_pid' => $r->versionPublicId] : null;
    }

    /** @param array<string, mixed> $in */
    public function proposeRules(Authorizer $az, int $le, array $in, string $from, string $source, string $reason): string
    {
        if (!$az->scope('eid.wallet_policy')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        [$value, $errors] = WalletRules::validate($in);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $this->tz());
        if ($d === false || $d->format('Y-m-d') !== $from) {
            $errors['effective_from'] = 'eid.error.date';
        }
        if (mb_strlen(trim($source)) < 5) {
            $errors['source_reference'] = 'eid.rules_error.source';
        }
        if (mb_strlen(trim($reason)) < 3) {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== [] || $value === null || $d === false) {
            throw new ValidationException($errors);
        }
        $reg = $this->app->registry();
        $pid = $reg->propose($az, 'eid.wallet_rules', 'legal_entity', $le, (string) json_encode($value, JSON_UNESCAPED_UNICODE), $d, null, trim($reason), trim($source));
        $reg->submit($az, $pid);
        return $pid;
    }

    // ---- reading ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function wallet(int $employeeId): ?array
    {
        return $this->app->db()->one('SELECT * FROM eid_wallets WHERE employee_id = ?', [$employeeId]);
    }

    /** @return list<array<string, mixed>> newest first */
    public function entries(int $walletId, int $limit = 50): array
    {
        return $this->app->db()->all('SELECT * FROM eid_wallet_entries WHERE wallet_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$walletId]);
    }

    /** @return array{topup: int, spend: int, refund: int} units in a calendar month */
    public function month(int $walletId, string $ym): array
    {
        $out = ['topup' => 0, 'spend' => 0, 'refund' => 0];
        foreach (
            $this->app->db()->all(
                "SELECT entry_type, SUM(amount) AS s FROM eid_wallet_entries WHERE wallet_id = ? AND created_at >= ? AND created_at < ? GROUP BY entry_type",
                [$walletId, $ym . '-01 00:00:00', EmployeeService::ymd($ym . '-01')->modify('+1 month')->format('Y-m-d 00:00:00')]
            ) as $r
        ) {
            $k = (string) $r['entry_type'];
            if (isset($out[$k])) {
                $out[$k] = abs(Money::fromDb($r['s']));
            }
        }
        return $out;
    }

    /** @return array<string, mixed> a wallet created on first use (closed internal balance in the rules' currency) */
    private function lockWallet(int $employeeId, int $le, string $currency): array
    {
        $db = $this->app->db();
        $db->run(
            'INSERT IGNORE INTO eid_wallets (employee_id, legal_entity_id, currency_code, balance, status, created_at, updated_at) VALUES (?, ?, ?, 0, \'active\', ?, ?)',
            [$employeeId, $le, $currency, $this->now(), $this->now()]
        );
        $w = $db->one('SELECT * FROM eid_wallets WHERE employee_id = ? FOR UPDATE', [$employeeId]);
        if ($w === null) {
            throw new \RuntimeException('wallet missing');
        }
        return $w;
    }

    /**
     * Append one entry and move the cached balance (caller holds the row lock inside a transaction).
     *
     * @param array<string, mixed> $w locked wallet row
     */
    private function append(array $w, string $type, int $units, string $key, ?string $refType, ?string $refId, ?int $reverses, ?int $topupId, ?string $note, ?int $actorId): int
    {
        $balance = Money::fromDb($w['balance']) + $units;
        if ($balance < 0) {
            throw new BusinessException('eid.error.insufficient', [], 409);
        }
        $db = $this->app->db();
        $db->run(
            'INSERT INTO eid_wallet_entries (public_id, wallet_id, entry_type, amount, balance_after, idempotency_key, ref_type, ref_id, reverses_entry_id, topup_id, note, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Ulid::generate($this->app->clock), $w['id'], $type, Money::toDb($units), Money::toDb($balance), $key, $refType, $refId, $reverses, $topupId, $note !== null ? mb_substr($note, 0, 300) : null, $this->now(), $actorId]
        );
        $id = (int) $db->pdo()->lastInsertId();
        $db->run('UPDATE eid_wallets SET balance = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?', [Money::toDb($balance), $this->now(), $w['id']]);
        return $id;
    }

    // ---- top-up -------------------------------------------------------------------------------------------------------

    /**
     * Maker: a top-up batch for one company.
     *
     * @param list<array{employee: string, amount: string}> $lines
     */
    public function createTopup(Authorizer $az, int $le, array $lines, string $reason): string
    {
        $scope = $az->scope('eid.wallet_topup');
        if (!$scope->coversLegalEntity($le) && $scope->isEmpty()) {
            throw HttpException::forbidden();
        }
        $rules = $this->rules($le);
        if ($rules === null) {
            throw new BusinessException('eid.error.no_rules', [], 409);
        }
        $reason = trim($reason);
        $errors = [];
        if (mb_strlen($reason) < 5) {
            $errors['reason'] = 'eid.error.reason';
        }
        $max = Money::parse((string) $rules['max_topup'], 2) ?? 0;
        $cap = Money::parse((string) $rules['max_balance'], 2) ?? 0;
        $db = $this->app->db();
        $clean = [];
        $total = 0;
        foreach ($lines as $i => $l) {
            if (trim($l['employee']) === '' && trim($l['amount']) === '') {
                continue;
            }
            $id = $db->scalar('SELECT id FROM employees WHERE public_id = ?', [trim($l['employee'])]);
            $e = $id !== null ? $this->emp()->byId((int) $id) : null;
            if ($e === null || $e['job'] === null || (int) $e['job']['legal_entity_id'] !== $le || !in_array($e['status'], ['active', 'on_leave'], true) || !EmployeeService::covers($scope, $e['job'])) {
                $errors['lines.' . $i] = 'eid.error.line_employee';
                continue;
            }
            $u = Money::parse($l['amount'], 2);
            if ($u === null || $u <= 0 || $u > $max) {
                $errors['lines.' . $i] = 'eid.error.line_amount';
                continue;
            }
            $w = $this->wallet((int) $e['id']);
            if ($w !== null && $w['currency_code'] !== $rules['currency']) {
                $errors['lines.' . $i] = 'eid.error.currency';
                continue;
            }
            if (($w !== null ? Money::fromDb($w['balance']) : 0) + $u > $cap) {
                $errors['lines.' . $i] = 'eid.error.over_balance';
                continue;
            }
            if (isset($clean[(int) $e['id']])) {
                $errors['lines.' . $i] = 'eid.error.line_twice';
                continue;
            }
            $clean[(int) $e['id']] = $u;
            $total += $u;
        }
        if ($clean === [] && $errors === []) {
            $errors['lines'] = 'eid.error.no_lines';
        }
        if (count($clean) > self::MAX_LINES) {
            $errors['lines'] = 'eid.error.too_many';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $db->transactional(function () use ($db, $az, $le, $clean, $total, $reason, $rules): string {
            $no = (int) $db->scalar('SELECT COALESCE(MAX(topup_no), 0) + 1 FROM eid_topups WHERE legal_entity_id = ? FOR UPDATE', [$le]);
            $pid = Ulid::generate($this->app->clock);
            $now = $this->now();
            $db->run(
                "INSERT INTO eid_topups (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, topup_no, status, reason, currency_code, line_count, total_amount, rules_version_pid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $no, mb_substr($reason, 0, 300), $rules['currency'], count($clean), Money::toDb($total), $rules['version_pid']]
            );
            $tid = (int) $db->pdo()->lastInsertId();
            foreach ($clean as $empId => $u) {
                $db->run('INSERT INTO eid_topup_lines (topup_id, employee_id, amount) VALUES (?, ?, ?)', [$tid, $empId, Money::toDb($u)]);
            }
            $title = $this->app->translator()->get('eid.topup_line', ['n' => (string) $no, 'count' => (string) count($clean), 'total' => Money::format($total) . ' ' . $rules['currency']]);
            $inst = $this->app->workflow()->start('eid.wallet_topup', $pid, $title, $le, $az->userId);
            $db->run('UPDATE eid_topups SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$inst, $tid]);
            $this->app->audit()->record('eid.topup_created', 'success', 'wallet_topup', $pid, $le, $reason, ['lines' => count($clean), 'total' => Money::toDb($total), 'currency' => $rules['currency']]);
            return $pid;
        });
    }

    /** Checker approved: apply every line (all or none) and request the posting. */
    public function onApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $t = $db->one("SELECT * FROM eid_topups WHERE public_id = ? AND status = 'pending'", [$pid]);
        if ($t === null) {
            return;
        }
        try {
            $db->transactional(function () use ($db, $t, $approverId): void {
                $rules = $this->rules((int) $t['legal_entity_id']);
                $cap = $rules !== null ? (Money::parse((string) $rules['max_balance'], 2) ?? 0) : 0;
                if ($rules === null || $rules['currency'] !== $t['currency_code']) {
                    throw new BusinessException('eid.error.no_rules', [], 409);
                }
                foreach ($db->all('SELECT * FROM eid_topup_lines WHERE topup_id = ? ORDER BY id', [$t['id']]) as $l) {
                    $e = $this->emp()->byId((int) $l['employee_id']);
                    if ($e === null || !in_array($e['status'], ['active', 'on_leave'], true)) {
                        throw new BusinessException('eid.error.line_employee', [], 409);
                    }
                    $w = $this->lockWallet((int) $l['employee_id'], (int) $t['legal_entity_id'], (string) $t['currency_code']);
                    if ($w['currency_code'] !== $t['currency_code'] || $w['status'] !== 'active') {
                        throw new BusinessException('eid.error.currency', [], 409);
                    }
                    $u = Money::fromDb($l['amount']);
                    if (Money::fromDb($w['balance']) + $u > $cap) {
                        throw new BusinessException('eid.error.over_balance', [], 409);
                    }
                    $entry = $this->append($w, 'topup', $u, 'topup:' . $t['public_id'] . ':' . $l['id'], 'wallet_topup', (string) $t['public_id'], null, (int) $t['id'], null, $approverId);
                    $db->run('UPDATE eid_topup_lines SET entry_id = ? WHERE id = ?', [$entry, $l['id']]);
                }
                $posting = $this->app->posting()->request([
                    'source_module' => 'eid.wallet', 'source_document_type' => 'wallet_topup', 'source_document_id' => (string) $t['public_id'], 'legal_entity_id' => (int) $t['legal_entity_id'],
                    'period_date' => $this->emp()->today(), 'currency_code' => (string) $t['currency_code'],
                    'lines' => [['line_type' => 'topup', 'amount' => Money::toDb(Money::fromDb($t['total_amount'])), 'description' => 'Wallet top-up #' . $t['topup_no']]],
                    'description' => 'Wallet top-up #' . $t['topup_no'], 'idempotency_key' => 'eid.topup:' . $t['public_id'], 'actor_user_id' => $approverId,
                ]);
                $db->run(
                    "UPDATE eid_topups SET status = 'approved', approved_by = ?, approved_at = ?, posting_request_pid = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
                    [$approverId, $this->now(), $posting, $this->now(), $t['id']]
                );
                $this->app->audit()->record('eid.topup_applied', 'success', 'wallet_topup', (string) $t['public_id'], (int) $t['legal_entity_id'], null, ['total' => $t['total_amount'], 'posting' => $posting], $approverId);
            });
        } catch (Throwable $e) {
            $code = $e instanceof BusinessException ? $e->messageKey : 'eid.error.apply';
            $db->run("UPDATE eid_topups SET status = 'failed', error_code = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [mb_substr($code, 0, 60), $this->now(), $t['id']]);
            $this->app->audit()->record('eid.topup_failed', 'failure', 'wallet_topup', (string) $t['public_id'], (int) $t['legal_entity_id'], null, ['error' => $code], $approverId);
            return;
        }
        foreach ($db->all('SELECT l.employee_id FROM eid_topup_lines l WHERE l.topup_id = ?', [$t['id']]) as $l) {
            $uid = $db->scalar("SELECT id FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1", [$l['employee_id']]);
            if ($uid !== null) {
                $this->app->notifier()->notify((int) $uid, 'eid.topup', 'eid.notify.topup', [], '/eid', 'eid.topup:' . $t['public_id'] . ':' . $uid);
            }
        }
    }

    public function onClosed(string $pid, string $outcome): void
    {
        $status = match ($outcome) {
            'rejected' => 'rejected',
            'returned' => 'returned',
            default => 'cancelled',
        };
        $this->app->db()->run("UPDATE eid_topups SET status = ?, updated_at = ? WHERE public_id = ? AND status IN ('pending','returned')", [$status, $this->now(), $pid]);
    }

    /** @return list<array<string, mixed>> */
    public function topups(int $le, int $limit = 50): array
    {
        return $this->app->db()->all(
            'SELECT t.*, u.display_name AS created_name, a.display_name AS approved_name FROM eid_topups t LEFT JOIN users u ON u.id = t.created_by LEFT JOIN users a ON a.id = t.approved_by
              WHERE t.legal_entity_id = ? ORDER BY t.topup_no DESC LIMIT ' . max(1, min(200, $limit)),
            [$le]
        );
    }

    /** @return array<string, mixed> top-up + lines, for wallet viewers / makers / checkers of its company */
    public function topup(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $t = $db->one(
            'SELECT t.*, l.code AS le_code, u.display_name AS created_name, a.display_name AS approved_name, i.public_id AS instance_pid FROM eid_topups t JOIN legal_entities l ON l.id = t.legal_entity_id
               LEFT JOIN users u ON u.id = t.created_by LEFT JOIN users a ON a.id = t.approved_by LEFT JOIN workflow_instances i ON i.id = t.workflow_instance_id WHERE t.public_id = ?',
            [$pid]
        );
        $le = $t !== null ? (int) $t['legal_entity_id'] : null;
        if ($t === null || !($az->scope('eid.wallet_view')->coversLegalEntity($le) || $az->scope('eid.wallet_topup')->coversLegalEntity($le) || $az->scope('eid.wallet_approve')->coversLegalEntity($le))) {
            throw HttpException::notFound();
        }
        $t['lines'] = $db->all('SELECT l.*, e.employee_code, e.name_th FROM eid_topup_lines l JOIN employees e ON e.id = l.employee_id WHERE l.topup_id = ? ORDER BY e.employee_code', [$t['id']]);
        return $t;
    }

    // ---- spending interface (POS, phase 8) ----------------------------------------------------------------------------

    /**
     * Debit the employee's wallet for a sale. Same idempotency key = the first entry (no second debit).
     *
     * @return string entry public id
     */
    public function spend(int $employeeId, int $units, string $idempotencyKey, string $refType, string $refId, ?int $actorId): string
    {
        $db = $this->app->db();
        $seen = $db->scalar('SELECT public_id FROM eid_wallet_entries WHERE idempotency_key = ?', [$idempotencyKey]);
        if ($seen !== null) {
            return (string) $seen;
        }
        if ($units <= 0 || strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 120) {
            throw new ValidationException(['amount' => 'eid.error.line_amount']);
        }
        $card = CardService::of($this->app)->current($employeeId);
        if ($card === null || CardService::of($this->app)->effective($card) !== 'active') {
            throw new BusinessException('eid.error.card_blocked', [], 409);
        }
        $pid = $db->transactional(function () use ($db, $employeeId, $units, $idempotencyKey, $refType, $refId, $actorId): string {
            $w = $db->one('SELECT * FROM eid_wallets WHERE employee_id = ? FOR UPDATE', [$employeeId]);
            if ($w === null || $w['status'] !== 'active') {
                throw new BusinessException('eid.error.insufficient', [], 409);
            }
            $again = $db->scalar('SELECT public_id FROM eid_wallet_entries WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($again !== null) {
                return (string) $again; // a parallel call with the same key won the lock
            }
            $id = $this->append($w, 'spend', -$units, $idempotencyKey, mb_substr($refType, 0, 40), mb_substr($refId, 0, 60), null, null, null, $actorId);
            return (string) $db->scalar('SELECT public_id FROM eid_wallet_entries WHERE id = ?', [$id]);
        });
        $this->lowBalance($employeeId);
        return $pid;
    }

    /** Give back (part of) a spend — never more than was spent minus earlier refunds. */
    public function refund(string $spendPid, int $units, string $idempotencyKey, ?int $actorId): string
    {
        $db = $this->app->db();
        $seen = $db->scalar('SELECT public_id FROM eid_wallet_entries WHERE idempotency_key = ?', [$idempotencyKey]);
        if ($seen !== null) {
            return (string) $seen;
        }
        return $db->transactional(function () use ($db, $spendPid, $units, $idempotencyKey, $actorId): string {
            $s = $db->one("SELECT * FROM eid_wallet_entries WHERE public_id = ? AND entry_type = 'spend'", [$spendPid]);
            if ($s === null) {
                throw HttpException::notFound();
            }
            $w = $db->one('SELECT * FROM eid_wallets WHERE id = ? FOR UPDATE', [$s['wallet_id']]);
            $done = Money::fromDb($db->scalar("SELECT COALESCE(SUM(amount), 0) FROM eid_wallet_entries WHERE reverses_entry_id = ? AND entry_type = 'refund'", [$s['id']]));
            if ($w === null || $units <= 0 || $done + $units > -Money::fromDb($s['amount'])) {
                throw new ValidationException(['amount' => 'eid.error.refund_amount']);
            }
            $id = $this->append($w, 'refund', $units, $idempotencyKey, $s['ref_type'], $s['ref_id'], (int) $s['id'], null, null, $actorId);
            return (string) $db->scalar('SELECT public_id FROM eid_wallet_entries WHERE id = ?', [$id]);
        });
    }

    private function lowBalance(int $employeeId): void
    {
        $w = $this->wallet($employeeId);
        $rules = $w !== null ? $this->rules((int) $w['legal_entity_id']) : null;
        if ($w === null || $rules === null || ($rules['low_balance'] ?? null) === null) {
            return;
        }
        $bal = Money::fromDb($w['balance']);
        if ($bal < (Money::parse((string) $rules['low_balance'], 2) ?? 0)) {
            $uid = $this->app->db()->scalar("SELECT id FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1", [$employeeId]);
            if ($uid !== null) {
                $this->app->notifier()->notify((int) $uid, 'eid.low_balance', 'eid.notify.low_balance', ['amount' => Money::format($bal) . ' ' . $w['currency_code']], '/eid', 'eid.low:' . $w['id'] . ':' . $w['row_version']);
            }
        }
    }

    // ---- reconciliation -----------------------------------------------------------------------------------------------

    /** @return array{wallets: int, balance_total: int, ledger_total: int, mismatches: list<array<string, mixed>>} */
    public function reconcile(?int $le = null): array
    {
        $db = $this->app->db();
        $rows = $db->all(
            'SELECT w.id, w.employee_id, w.balance, w.currency_code, e.employee_code, (SELECT COALESCE(SUM(x.amount), 0) FROM eid_wallet_entries x WHERE x.wallet_id = w.id) AS ledger,
                    (SELECT x.balance_after FROM eid_wallet_entries x WHERE x.wallet_id = w.id ORDER BY x.id DESC LIMIT 1) AS last_after
               FROM eid_wallets w JOIN employees e ON e.id = w.employee_id' . ($le !== null ? ' WHERE w.legal_entity_id = ?' : ''),
            $le !== null ? [$le] : []
        );
        $out = ['wallets' => count($rows), 'balance_total' => 0, 'ledger_total' => 0, 'mismatches' => []];
        foreach ($rows as $r) {
            $b = Money::fromDb($r['balance']);
            $l = Money::fromDb($r['ledger']);
            $out['balance_total'] += $b;
            $out['ledger_total'] += $l;
            if ($b !== $l || ($r['last_after'] !== null && Money::fromDb($r['last_after']) !== $b)) {
                $out['mismatches'][] = ['employee_code' => $r['employee_code'], 'balance' => $b, 'ledger' => $l];
            }
        }
        return $out;
    }

    /** Daily job: an audit record (and a "failure" outcome) when any wallet does not match its ledger. */
    public function reconcileCheck(): int
    {
        if (!$this->app->db()->tableExists('eid_wallets')) {
            return 0;
        }
        $r = $this->reconcile();
        if ($r['mismatches'] !== []) {
            $this->app->audit()->record('eid.wallet_mismatch', 'failure', 'eid_wallet', null, null, null, ['count' => count($r['mismatches'])]);
        }
        return count($r['mismatches']);
    }
}
