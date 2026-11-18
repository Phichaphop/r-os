<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Org\Translations;
use Blue\Core\Pdf\SimpleDocument;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;

/**
 * Phase 6B (DEC-84): payslips (M36) and year-to-date totals.
 *
 *  ytd       one entry per person per approved run (written when the run is approved): gross, taxable income
 *            (earnings marked taxable − deductions marked taxable), withholding tax (components marked WHT) and
 *            employee social security — plus opening amounts for a company that starts mid-year, typed by Payroll and
 *            approved by another person. Tax year = year of the pay date. Nothing is ever changed: a mistake in an
 *            opening amount is fixed by another opening entry approved the same way.
 *  payslips  Payroll publishes the payslips of an approved run: one PDF per person built from the run result (never
 *            from live data), stored as a frozen, encrypted document (class payroll.payslip), with the totals of the
 *            tax year up to that pay date. The person gets a notification; they see their own payslips only after
 *            typing their password again; numbers are not shown before that. Every opening is logged — without amounts.
 */
final class PayslipService
{
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

    // ---- year to date ----------------------------------------------------------------------------------------------------

    /**
     * Writes the run's year-to-date entries (once — a repeated call adds nothing).
     *
     * @param array<string, mixed> $run
     */
    public function ytdFromRun(array $run): int
    {
        $db = $this->app->db();
        $year = (int) substr((string) $run['pay_date'], 0, 4);
        $n = 0;
        foreach ($db->all('SELECT id, employee_id, gross FROM pay_run_employees WHERE run_id = ?', [$run['id']]) as $re) {
            if ($db->scalar("SELECT id FROM pay_ytd_entries WHERE source_type = 'run' AND source_ref = ? AND employee_id = ?", [$run['public_id'], $re['employee_id']]) !== null) {
                continue;
            }
            $sum = ['taxable' => 0, 'wht' => 0, 'sso' => 0];
            foreach ($db->all('SELECT l.kind, l.amount, c.taxable, c.statutory FROM pay_result_lines l JOIN pay_components c ON c.id = l.component_id WHERE l.run_employee_id = ?', [$re['id']]) as $l) {
                $u = Money::fromDb($l['amount']);
                if ((int) $l['taxable'] === 1) {
                    $sum['taxable'] += $l['kind'] === 'earning' ? $u : ($l['kind'] === 'deduction' ? -$u : 0);
                }
                if ($l['statutory'] === 'wht') {
                    $sum['wht'] += $u;
                }
                if ($l['statutory'] === 'sso_employee') {
                    $sum['sso'] += $u;
                }
            }
            $now = $this->now();
            $db->run(
                "INSERT INTO pay_ytd_entries (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, tax_year, source_type, source_ref, as_of, gross, taxable_income, wht, sso_employee, status, decided_by, decided_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'run', ?, ?, ?, ?, ?, ?, 'approved', ?, ?)",
                [Ulid::generate($this->app->clock), $now, $run['approved_by'], $now, $run['approved_by'], $re['employee_id'], $run['legal_entity_id'], $year, $run['public_id'], $run['pay_date'],
                    $re['gross'], Money::toDb($sum['taxable']), Money::toDb($sum['wht']), Money::toDb($sum['sso']), $run['approved_by'], $run['approved_at'] ?? $now]
            );
            $n++;
        }
        return $n;
    }

    /** @return array{gross: int, taxable: int, wht: int, sso: int} approved totals of a tax year up to (and including) a day */
    public function ytd(int $employeeId, int $le, int $year, string $upTo): array
    {
        $r = $this->app->db()->one(
            "SELECT COALESCE(SUM(gross), 0) AS g, COALESCE(SUM(taxable_income), 0) AS t, COALESCE(SUM(wht), 0) AS w, COALESCE(SUM(sso_employee), 0) AS s
               FROM pay_ytd_entries WHERE employee_id = ? AND legal_entity_id = ? AND tax_year = ? AND status = 'approved' AND as_of <= ?",
            [$employeeId, $le, $year, $upTo]
        ) ?? [];
        return ['gross' => Money::fromDb($r['g'] ?? 0), 'taxable' => Money::fromDb($r['t'] ?? 0), 'wht' => Money::fromDb($r['w'] ?? 0), 'sso' => Money::fromDb($r['s'] ?? 0)];
    }

    /** @return list<array<string, mixed>> every entry of a person in a company, newest first */
    public function ytdEntries(int $employeeId, int $le): array
    {
        return $this->app->db()->all(
            "SELECT y.*, u.display_name AS created_name, d.display_name AS decided_name, r.label AS run_label FROM pay_ytd_entries y LEFT JOIN users u ON u.id = y.created_by LEFT JOIN users d ON d.id = y.decided_by
               LEFT JOIN pay_runs r ON y.source_type = 'run' AND r.public_id = y.source_ref
              WHERE y.employee_id = ? AND y.legal_entity_id = ? ORDER BY y.tax_year DESC, y.as_of DESC, y.id DESC",
            [$employeeId, $le]
        );
    }

    /**
     * Opening amounts (taxable income, tax withheld, social security already paid this year before using Blue OS) —
     * another person with payroll.approve must approve them.
     *
     * @param array<string, mixed> $in tax_year, as_of, gross, taxable_income, wht, sso_employee, note
     */
    public function proposeOpening(Authorizer $az, string $empPid, array $in): string
    {
        $svc = PayrollService::of($this->app);
        $e = $svc->employee($az, $empPid);
        $le = (int) $e['legal_entity_id'];
        if (!$az->scope('payroll.prepare')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $errors = [];
        $year = trim((string) ($in['tax_year'] ?? ''));
        $asOf = (string) ($in['as_of'] ?? '');
        if (!ctype_digit($year) || (int) $year < 2000 || (int) $year > 2100) {
            $errors['tax_year'] = 'payroll.error.year';
        }
        if (!PayrollService::isDate($asOf) || substr($asOf, 0, 4) !== $year) {
            $errors['as_of'] = 'payroll.error.as_of';
        }
        $db = $this->app->db();
        if (
            !isset($errors['as_of']) && !isset($errors['tax_year'])
            && $db->scalar("SELECT id FROM pay_runs WHERE legal_entity_id = ? AND status = 'approved' AND pay_date <= ? AND YEAR(pay_date) = ? LIMIT 1", [$le, $asOf, (int) $year]) !== null
        ) {
            $errors['as_of'] = 'payroll.error.opening_after_run';
        }
        $vals = [];
        foreach (['gross', 'taxable_income', 'wht', 'sso_employee'] as $k) {
            $raw = trim((string) ($in[$k] ?? ''));
            $u = Money::parse($raw === '' ? '0' : $raw, 2);
            if ($u === null || $u < 0) {
                $errors[$k] = 'payroll.error.amount0';
            }
            $vals[$k] = (int) $u;
        }
        if ($errors === [] && array_sum($vals) === 0) {
            $errors['gross'] = 'payroll.error.opening_empty';
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) < 5) {
            $errors['note'] = 'payroll.error.opening_note';
        }
        if ($db->scalar("SELECT id FROM pay_ytd_entries WHERE employee_id = ? AND legal_entity_id = ? AND source_type = 'opening' AND status = 'pending'", [$e['id'], $le]) !== null) {
            throw new BusinessException('payroll.error.opening_pending', [], 409);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->run(
            "INSERT INTO pay_ytd_entries (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, tax_year, source_type, source_ref, as_of, gross, taxable_income, wht, sso_employee, status, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'opening', ?, ?, ?, ?, ?, ?, 'pending', ?)",
            [$pid, $now, $az->userId, $now, $az->userId, $e['id'], $le, (int) $year, $pid, $asOf, Money::toDb($vals['gross']), Money::toDb($vals['taxable_income']), Money::toDb($vals['wht']), Money::toDb($vals['sso_employee']), $note]
        );
        $this->app->audit()->record('payroll.opening_proposed', 'success', 'pay_ytd_entry', $pid, $le, $note, ['employee' => (string) $e['public_id'], 'tax_year' => (int) $year]);
        foreach ($this->approvers($le, $az->userId) as $uid) {
            $this->app->notifier()->notify($uid, 'payroll.opening', 'payroll.notify.opening', ['code' => (string) $e['employee_code']], '/payroll/people/' . $e['public_id'], 'pay-open-' . $pid . '-' . $uid);
        }
        return $pid;
    }

    /** @return list<int> people with payroll.approve for the company, except one */
    private function approvers(int $le, int $except): array
    {
        $out = [];
        foreach (
            $this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN role_permissions rp ON rp.role_id = a.role_id
                                         JOIN users u ON u.id = a.user_id WHERE rp.permission_code = 'payroll.approve' AND a.status = 'active' AND u.status = 'active'") as $x
        ) {
            $uid = (int) $x['user_id'];
            if ($uid !== $except && (new Authorizer($this->app->db(), $this->app->clock, $uid))->scope('payroll.approve')->coversLegalEntity($le)) {
                $out[] = $uid;
            }
        }
        return $out;
    }

    public function decideOpening(Authorizer $az, string $entryPid, bool $approve, string $note = ''): void
    {
        $db = $this->app->db();
        $y = $db->one("SELECT * FROM pay_ytd_entries WHERE public_id = ? AND source_type = 'opening'", [$entryPid]) ?? throw HttpException::notFound();
        $le = (int) $y['legal_entity_id'];
        if (!$az->scope('payroll.view')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        if (!$az->scope('payroll.approve')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        if ($y['status'] !== 'pending') {
            throw new BusinessException('payroll.error.state', [], 409);
        }
        if ((int) $y['created_by'] === $az->userId) {
            throw new BusinessException('payroll.error.same_person', [], 403);
        }
        if (!$approve && mb_strlen(trim($note)) < 3) {
            throw new ValidationException(['decision_note' => 'iam.error.reason_required']);
        }
        $db->run(
            'UPDATE pay_ytd_entries SET status = ?, decided_by = ?, decided_at = ?, note = CONCAT(note, ?), updated_at = ?, row_version = row_version + 1 WHERE id = ?',
            [$approve ? 'approved' : 'rejected', $az->userId, $this->now(), $approve ? '' : ' — ' . mb_substr(trim($note), 0, 200), $this->now(), $y['id']]
        );
        $this->app->audit()->record('payroll.opening_' . ($approve ? 'approved' : 'rejected'), 'success', 'pay_ytd_entry', $entryPid, $le);
    }

    // ---- payslips --------------------------------------------------------------------------------------------------------

    /**
     * Publishes the payslips of an approved run (people who already have one are skipped).
     *
     * @return array{published: int, notified: int}
     */
    public function publish(Authorizer $az, string $runPid): array
    {
        $svc = PayrollService::of($this->app);
        $run = $svc->run($az, $runPid);
        $le = (int) $run['legal_entity_id'];
        if (!$az->scope('payroll.prepare')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        if ($run['status'] !== 'approved') {
            throw new BusinessException('payroll.error.not_approved', [], 409);
        }
        $db = $this->app->db();
        $this->ytdFromRun($run);
        $leName = Translations::pick((new Translations($db))->many('legal_entity', [$le])[$le] ?? [], 'th');
        $published = 0;
        $notified = 0;
        foreach ($svc->results((int) $run['id']) as $re) {
            if ($db->scalar('SELECT id FROM pay_payslips WHERE run_id = ? AND employee_id = ?', [$run['id'], $re['employee_id']]) !== null) {
                continue;
            }
            $lines = $db->all('SELECT l.*, c.name_th, c.sort_order FROM pay_result_lines l JOIN pay_components c ON c.id = l.component_id WHERE l.run_employee_id = ? ORDER BY c.sort_order, l.id', [$re['id']]);
            $pid = Ulid::generate($this->app->clock);
            $profile = $re['snap']['profile'] !== null ? $db->one('SELECT bank_code, account_last4 FROM pay_profiles WHERE public_id = ?', [$re['snap']['profile']]) : null;
            $ytd = $this->ytd((int) $re['employee_id'], $le, (int) substr((string) $run['pay_date'], 0, 4), (string) $run['pay_date']);
            $bytes = $this->pdf($run, $re, $lines, $ytd, $leName, $profile, $pid);
            $userId = $db->scalar("SELECT id FROM users WHERE employee_id = ? AND status <> 'deactivated' ORDER BY id LIMIT 1", [$re['employee_id']]);
            $stored = $this->app->documents()->storeGenerated($az->userId, 'payroll.payslip', $bytes, 'payslip-' . $run['le_code'] . '-R' . $run['run_no'] . '-' . $re['snap']['code'] . '.pdf', [
                'title' => mb_substr('สลิปเงินเดือน ' . $re['snap']['code'] . ' · ' . $run['label'], 0, 200), 'legal_entity_id' => $le, 'related_type' => 'pay_payslip', 'related_id' => $pid,
                'subject_user_id' => $userId !== null ? (int) $userId : null,
            ], 'system', true);
            $now = $this->now();
            $db->run(
                'INSERT INTO pay_payslips (public_id, created_at, created_by, updated_at, updated_by, run_id, employee_id, legal_entity_id, document_pid, sha256, status, published_at, published_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'published\', ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $run['id'], $re['employee_id'], $le, $stored['document'], hash('sha256', $bytes), $now, $az->userId]
            );
            $published++;
            if ($userId !== null) {
                // no amounts in the notification
                $this->app->notifier()->notify((int) $userId, 'payroll.payslip', 'payroll.notify.payslip', ['label' => (string) $run['label']], '/me/payslips/' . $pid, 'payslip-' . $pid);
                $notified++;
            }
        }
        $db->run('INSERT INTO pay_run_events (run_id, action, actor_id, note, occurred_at) VALUES (?, ?, ?, ?, ?)', [$run['id'], 'payslips', $az->userId, (string) $published, $this->now()]);
        $this->app->audit()->record('payroll.payslips_published', 'success', 'pay_run', $runPid, $le, null, ['published' => $published, 'notified' => $notified]);
        return ['published' => $published, 'notified' => $notified];
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $re
     * @param list<array<string, mixed>> $lines
     * @param array{gross: int, taxable: int, wht: int, sso: int} $ytd
     * @param array<string, mixed>|null $profile
     */
    private function pdf(array $run, array $re, array $lines, array $ytd, string $leName, ?array $profile, string $pid): string
    {
        $m = static fn (int $u): string => Money::format($u);
        $th = static fn (string $ymd): string => (int) substr($ymd, 8, 2) . ' ' . ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][(int) substr($ymd, 5, 2)] . ' ' . ((int) substr($ymd, 0, 4) + 543);
        $doc = new SimpleDocument('Blue OS · สลิปเงินเดือน · เลขอ้างอิง ' . $pid . ' · ข้อมูลลับเฉพาะบุคคล');
        $doc->title('สลิปเงินเดือน (Payslip)', $leName . ' (' . $run['le_code'] . ') · งวด ' . $th((string) $run['period_start']) . ' – ' . $th((string) $run['period_end']) . ' · วันจ่าย ' . $th((string) $run['pay_date']));
        $doc->pairs([
            ['รหัสพนักงาน', (string) $re['snap']['code']], ['ชื่อ', (string) $re['snap']['name']],
            ['รอบเงินเดือน', '#' . $run['run_no'] . ' ' . $run['label']], ['โอนเข้าบัญชี', $profile !== null && $profile['account_last4'] !== null ? $profile['bank_code'] . ' ••••' . $profile['account_last4'] : '—'],
        ]);
        $part = static function (string $kind) use ($lines, $m): array {
            $rows = [];
            $sum = 0;
            foreach ($lines as $l) {
                if ($l['kind'] !== $kind) {
                    continue;
                }
                $u = Money::fromDb($l['amount']);
                $sum += $u;
                $q = $l['quantity'] !== null ? rtrim(rtrim(Money::format(Money::fromDb($l['quantity']), 2), '0'), '.') : '';
                $rows[] = [(string) $l['name_th'], $q, $m($u)];
            }
            return [$rows, $sum];
        };
        [$earn, $gross] = $part('earning');
        [$ded, $deds] = $part('deduction');
        [$emp, $empSum] = $part('employer');
        $doc->heading('เงินได้')->table(['รายการ', 'จำนวน', 'จำนวนเงิน (บาท)'], $earn, [0.6, 0.15, 0.25], ['left', 'right', 'right'], ['รวมเงินได้', '', $m($gross)]);
        $doc->heading('เงินหัก')->table(['รายการ', 'จำนวน', 'จำนวนเงิน (บาท)'], $ded === [] ? [['—', '', '0.00']] : $ded, [0.6, 0.15, 0.25], ['left', 'right', 'right'], ['รวมเงินหัก', '', $m($deds)]);
        $doc->total('เงินได้สุทธิ (โอนเข้าบัญชี)', $m($gross - $deds) . ' บาท');
        if ($emp !== []) {
            $doc->space(4)->text('นายจ้างจ่ายเพิ่ม (ไม่หักจากเงินเดือน): ' . implode(' · ', array_map(static fn (array $r): string => $r[0] . ' ' . $r[2], $emp)), 8.5, '0.35 0.37 0.45');
        }
        $doc->space(6)->heading('ยอดสะสมตั้งแต่ต้นปีภาษี ' . ((int) substr((string) $run['pay_date'], 0, 4) + 543) . ' ถึงงวดนี้');
        $doc->pairs([['เงินได้รวม', $m($ytd['gross'])], ['เงินได้ที่ต้องเสียภาษี', $m($ytd['taxable'])], ['ภาษีหัก ณ ที่จ่าย', $m($ytd['wht'])], ['ประกันสังคม', $m($ytd['sso'])]]);
        $doc->space(6)->text('เอกสารนี้ออกจากระบบจากผลรอบเงินเดือนที่อนุมัติแล้ว ไม่ต้องลงนาม · หากรายการไม่ถูกต้องให้แจ้งฝ่ายเงินเดือนภายในรอบจ่ายถัดไป', 8.5, '0.35 0.37 0.45');
        return $doc->output();
    }

    /** @return array<string, mixed>|null the employee linked to a sign-in account */
    private function employeeOf(int $userId): ?array
    {
        $emp = $this->app->db()->scalar('SELECT employee_id FROM users WHERE id = ?', [$userId]);
        return $emp !== null ? $this->app->db()->one('SELECT id, public_id, employee_code, name_th FROM employees WHERE id = ?', [$emp]) : null;
    }

    /** @return list<array<string, mixed>> my published payslips (no amounts) */
    public function mine(int $userId): array
    {
        $e = $this->employeeOf($userId);
        if ($e === null) {
            return [];
        }
        return $this->app->db()->all(
            "SELECT s.public_id, s.published_at, s.first_viewed_at, r.label, r.period_start, r.period_end, r.pay_date, l.code AS le_code FROM pay_payslips s JOIN pay_runs r ON r.id = s.run_id
               JOIN legal_entities l ON l.id = s.legal_entity_id WHERE s.employee_id = ? AND s.status = 'published' ORDER BY r.pay_date DESC, s.id DESC LIMIT 60",
            [$e['id']]
        );
    }

    /**
     * One of my payslips with its figures (the controller asks for the password first). Logged, without amounts.
     *
     * @return array<string, mixed>
     */
    public function mineOne(int $userId, string $pid, bool $log = true): array
    {
        $e = $this->employeeOf($userId);
        $db = $this->app->db();
        $s = $db->one("SELECT s.*, r.label, r.run_no, r.period_start, r.period_end, r.pay_date, l.code AS le_code FROM pay_payslips s JOIN pay_runs r ON r.id = s.run_id JOIN legal_entities l ON l.id = s.legal_entity_id
                        WHERE s.public_id = ? AND s.status = 'published'", [$pid]);
        if ($s === null || $e === null || (int) $s['employee_id'] !== (int) $e['id']) {
            throw HttpException::notFound(); // someone else's payslip looks like one that does not exist
        }
        $re = $db->one('SELECT * FROM pay_run_employees WHERE run_id = ? AND employee_id = ?', [$s['run_id'], $e['id']]) ?? throw HttpException::notFound();
        $s['lines'] = $db->all('SELECT l.kind, l.quantity, l.amount, c.name_th FROM pay_result_lines l JOIN pay_components c ON c.id = l.component_id WHERE l.run_employee_id = ? ORDER BY c.sort_order, l.id', [$re['id']]);
        $s['gross'] = $re['gross'];
        $s['deductions'] = $re['deductions'];
        $s['net'] = $re['net'];
        $s['ytd'] = $this->ytd((int) $e['id'], (int) $s['legal_entity_id'], (int) substr((string) $s['pay_date'], 0, 4), (string) $s['pay_date']);
        if ($log) {
            $db->run('UPDATE pay_payslips SET first_viewed_at = COALESCE(first_viewed_at, ?), view_count = view_count + 1 WHERE id = ?', [$this->now(), $s['id']]);
            $this->app->audit()->record('payroll.payslip_viewed', 'success', 'pay_payslip', $pid, (int) $s['legal_entity_id']);
        }
        return $s;
    }

    /**
     * The PDF of a payslip — for its owner, or for payroll people of the company.
     *
     * @return array{bytes: string, mime: string, filename: string}
     */
    public function file(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $s = $db->one("SELECT * FROM pay_payslips WHERE public_id = ? AND status = 'published'", [$pid]) ?? throw HttpException::notFound();
        $e = $this->employeeOf($az->userId);
        $own = $e !== null && (int) $e['id'] === (int) $s['employee_id'];
        if (!$own && !$az->scope('payroll.view')->coversLegalEntity((int) $s['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $f = $this->app->documents()->moduleRead((string) $s['document_pid'], 'payroll', $az->userId, 'download');
        if (!hash_equals((string) $s['sha256'], hash('sha256', $f['bytes']))) {
            throw new BusinessException('payroll.error.payslip_damaged', [], 409);
        }
        $this->app->audit()->record('payroll.payslip_downloaded', 'success', 'pay_payslip', $pid, (int) $s['legal_entity_id'], null, ['own' => $own]);
        return ['bytes' => $f['bytes'], 'mime' => 'application/pdf', 'filename' => $f['filename']];
    }

    /** @return array{published: int, viewed: int, map: array<int, string>} payslip status of a run (for the payroll page): employee id => payslip id */
    public function runStatus(int $runId): array
    {
        $map = [];
        $viewed = 0;
        foreach ($this->app->db()->all("SELECT employee_id, public_id, first_viewed_at FROM pay_payslips WHERE run_id = ? AND status = 'published'", [$runId]) as $x) {
            $map[(int) $x['employee_id']] = (string) $x['public_id'];
            $viewed += $x['first_viewed_at'] !== null ? 1 : 0;
        }
        return ['published' => count($map), 'viewed' => $viewed, 'map' => $map];
    }
}
