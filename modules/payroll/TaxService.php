<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use DateTimeImmutable;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Pdf\SimpleDocument;
use Blue\Core\Registry\ResolvedConfig;
use Blue\Core\Support\Money;
use Blue\Core\Support\Ulid;

/**
 * Phase 6C (DEC-85): employees' tax allowance declarations and withholding tax certificates.
 *
 *  declarations  the person declares the allowances the company's tax rules list (amount or count) for a tax year;
 *                someone with payroll.prepare checks it against the evidence and approves (not the person themselves);
 *                the newest approved one counts, earlier ones are kept as "superseded". Calculated runs go back to
 *                draft so the tax is worked out again.
 *  certificates  someone with payroll.approve issues certificates for a tax year from the approved year-to-date
 *                entries (income, tax withheld, social security); the payer's details come from payroll.wht_payer;
 *                the payee's tax id from their current Thai citizen / tax id identifier (people without one are listed,
 *                not issued). Each is a frozen, encrypted PDF with a number WHT<year>-<n>. A certificate is never
 *                edited: when the totals change it shows as out of date and is reissued (the old one voided, with a
 *                reason, the new one points to it).
 */
final class TaxService
{
    public const MAX_COUNT = 20;

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

    private function rule(string $key, int $le, string $date): ?ResolvedConfig
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' 12:00:00', PayrollService::of($this->app)->tz()) ?: $this->app->clock->now();
        return $this->app->registry()->resolveOrNull($key, $le, null, $at);
    }

    /** @return list<array<string, mixed>> allowance types of the company's tax rules for a tax year (rules in force at year end, else today) */
    public function allowanceTypes(int $le, int $year): array
    {
        $today = PayrollService::of($this->app)->today();
        $r = $this->rule('payroll.wht', $le, min($year . '-12-31', max($year . '-01-01', $today)));
        return is_array($r?->value) ? array_values((array) ($r->value['allowances'] ?? [])) : [];
    }

    // ---- declarations ---------------------------------------------------------------------------------------------------

    /** @return array{0: array<string, mixed>, 1: int}|null the person's employee row and current company */
    private function me(int $userId): ?array
    {
        $db = $this->app->db();
        $emp = $db->scalar('SELECT employee_id FROM users WHERE id = ?', [$userId]);
        if ($emp === null) {
            return null;
        }
        $e = $db->one('SELECT id, public_id, employee_code, name_th FROM employees WHERE id = ?', [$emp]);
        $le = $db->scalar('SELECT legal_entity_id FROM employment_history WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1', [$emp]);
        return $e !== null && $le !== null ? [$e, (int) $le] : null;
    }

    /** @return array<string, mixed> what my declaration page needs */
    public function myPage(int $userId, int $year): array
    {
        $me = $this->me($userId);
        if ($me === null) {
            return ['employee' => null, 'types' => [], 'current' => null, 'pending' => null, 'history' => [], 'certificates' => []];
        }
        [$e, $le] = $me;
        $db = $this->app->db();
        $history = $db->all('SELECT * FROM pay_tax_declarations WHERE employee_id = ? AND legal_entity_id = ? AND tax_year = ? ORDER BY id DESC', [$e['id'], $le, $year]);
        $find = static fn (string $st): ?array => array_values(array_filter($history, static fn (array $d): bool => $d['status'] === $st))[0] ?? null;
        return ['employee' => $e, 'types' => $this->allowanceTypes($le, $year), 'current' => $find('approved'), 'pending' => $find('pending'), 'history' => $history,
            'certificates' => $db->all("SELECT public_id, tax_year, cert_no, issued_at, first_viewed_at FROM pay_tax_certificates WHERE employee_id = ? AND status = 'issued' ORDER BY tax_year DESC, id DESC", [$e['id']])];
    }

    /**
     * The person declares their allowances for a tax year (a new declaration replaces one still waiting).
     *
     * @param array<string, mixed> $values code => amount | count
     */
    public function submit(int $userId, int $year, array $values): string
    {
        $me = $this->me($userId) ?? throw new BusinessException('payroll.error.no_employee', [], 409);
        [$e, $le] = $me;
        $today = PayrollService::of($this->app)->today();
        if ($year < (int) substr($today, 0, 4) - 1 || $year > (int) substr($today, 0, 4) + 1) {
            throw new ValidationException(['tax_year' => 'payroll.error.year']);
        }
        $types = $this->allowanceTypes($le, $year);
        if ($types === []) {
            throw new BusinessException('payroll.error.no_allowance_types', [], 409);
        }
        $items = [];
        $errors = [];
        foreach ($types as $t) {
            $raw = trim((string) ($values[$t['code']] ?? ''));
            if ($raw === '' || $raw === '0') {
                continue;
            }
            if ($t['unit'] === 'count') {
                if (!ctype_digit($raw) || (int) $raw > self::MAX_COUNT) {
                    $errors['v_' . $t['code']] = 'payroll.error.count';
                    continue;
                }
                $items[] = ['code' => $t['code'], 'value' => (string) (int) $raw];
            } else {
                $u = Money::parse($raw, 2);
                if ($u === null || $u < 0) {
                    $errors['v_' . $t['code']] = 'payroll.error.amount0';
                    continue;
                }
                $items[] = ['code' => $t['code'], 'value' => Money::format($u, 2, false)];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $db = $this->app->db();
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->transactional(function () use ($db, $e, $le, $year, $items, $userId, $pid, $now): void {
            $db->run("UPDATE pay_tax_declarations SET status = 'withdrawn', updated_at = ? WHERE employee_id = ? AND legal_entity_id = ? AND tax_year = ? AND status = 'pending'", [$now, $e['id'], $le, $year]);
            $db->run("INSERT INTO pay_tax_declarations (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, tax_year, items_json, status, submitted_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)", [$pid, $now, $userId, $now, $userId, $e['id'], $le, $year, json_encode($items, JSON_UNESCAPED_UNICODE), $userId]);
        });
        $this->app->audit()->record('payroll.declaration_submitted', 'success', 'pay_tax_declaration', $pid, $le, null, ['tax_year' => $year, 'items' => count($items)]);
        foreach ($this->holders('payroll.prepare', $le, $userId) as $uid) {
            $this->app->notifier()->notify($uid, 'payroll.declaration', 'payroll.notify.declaration', ['code' => (string) $e['employee_code']], '/payroll/tax?year=' . $year, 'pay-decl-' . $pid . '-' . $uid);
        }
        return $pid;
    }

    /** @return list<int> active people with a permission covering the company, except one */
    private function holders(string $perm, int $le, int $except): array
    {
        $out = [];
        foreach (
            $this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN role_permissions rp ON rp.role_id = a.role_id JOIN users u ON u.id = a.user_id
                                         WHERE rp.permission_code = ? AND a.status = 'active' AND u.status = 'active'", [$perm]) as $x
        ) {
            $uid = (int) $x['user_id'];
            if ($uid !== $except && (new Authorizer($this->app->db(), $this->app->clock, $uid))->scope($perm)->coversLegalEntity($le)) {
                $out[] = $uid;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> declarations of a company and year (pending first) */
    public function declarations(int $le, int $year): array
    {
        return $this->app->db()->all(
            "SELECT d.*, e.employee_code, e.name_th, e.public_id AS employee_pid, u.display_name AS decided_name FROM pay_tax_declarations d JOIN employees e ON e.id = d.employee_id
               LEFT JOIN users u ON u.id = d.decided_by WHERE d.legal_entity_id = ? AND d.tax_year = ? AND d.status IN ('pending','approved','rejected')
              ORDER BY FIELD(d.status, 'pending', 'approved', 'rejected'), e.employee_code, d.id DESC",
            [$le, $year]
        );
    }

    public function decide(Authorizer $az, string $pid, bool $approve, string $note): void
    {
        $db = $this->app->db();
        $d = $db->one('SELECT * FROM pay_tax_declarations WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $le = (int) $d['legal_entity_id'];
        if (!$az->scope('payroll.view')->coversLegalEntity($le)) {
            throw HttpException::notFound();
        }
        if (!$az->scope('payroll.prepare')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        if ($d['status'] !== 'pending') {
            throw new BusinessException('payroll.error.state', [], 409);
        }
        if ((int) $d['submitted_by'] === $az->userId) {
            throw new BusinessException('payroll.error.same_person', [], 403);
        }
        if (!$approve && mb_strlen(trim($note)) < 3) {
            throw new ValidationException(['decision_note' => 'iam.error.reason_required']);
        }
        $now = $this->now();
        $db->transactional(function () use ($db, $d, $approve, $note, $az, $now): void {
            if ($approve) {
                $db->run("UPDATE pay_tax_declarations SET status = 'superseded', updated_at = ? WHERE employee_id = ? AND legal_entity_id = ? AND tax_year = ? AND status = 'approved'", [$now, $d['employee_id'], $d['legal_entity_id'], $d['tax_year']]);
            }
            $db->run(
                'UPDATE pay_tax_declarations SET status = ?, decided_by = ?, decided_at = ?, decision_note = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?',
                [$approve ? 'approved' : 'rejected', $az->userId, $now, trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, $now, $d['id']]
            );
        });
        $this->app->audit()->record('payroll.declaration_' . ($approve ? 'approved' : 'rejected'), 'success', 'pay_tax_declaration', $pid, $le);
        $this->app->notifier()->notify((int) $d['submitted_by'], 'payroll.declaration_decided', $approve ? 'payroll.notify.declaration_ok' : 'payroll.notify.declaration_no', ['year' => (string) $d['tax_year']], '/me/tax', 'pay-decl-done-' . $pid);
        if ($approve) {
            PayrollService::of($this->app)->staleRuns($le, 'declaration');
        }
    }

    // ---- certificates ----------------------------------------------------------------------------------------------------

    /**
     * The employer's social security account or the filing due days (6E) in force on a day (default today).
     *
     * @return array<string, mixed>|null
     */
    public function filingSetting(string $which, int $le, ?string $date = null): ?array
    {
        if (!in_array($which, ['sso_employer', 'filing_due', 'rd_file'], true)) {
            return null;
        }
        $r = $this->rule('payroll.' . $which, $le, $date ?? PayrollService::of($this->app)->today());
        return is_array($r?->value) ? $r->value : null;
    }

    /** @return array<string, mixed>|null payer details in force */
    public function payer(int $le): ?array
    {
        $r = $this->rule('payroll.wht_payer', $le, PayrollService::of($this->app)->today());
        return is_array($r?->value) ? $r->value : null;
    }

    /**
     * People with approved year-to-date entries in a company and year: totals, their current certificate, whether it
     * is out of date and whether a tax id is on file.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(int $le, int $year): array
    {
        $db = $this->app->db();
        $rows = $db->all(
            "SELECT e.id, e.public_id, e.employee_code, e.name_th, SUM(y.taxable_income) AS income, SUM(y.wht) AS wht, SUM(y.sso_employee) AS sso, COUNT(*) AS entries,
                    GROUP_CONCAT(y.public_id ORDER BY y.public_id SEPARATOR ',') AS refs
               FROM pay_ytd_entries y JOIN employees e ON e.id = y.employee_id
              WHERE y.legal_entity_id = ? AND y.tax_year = ? AND y.status = 'approved' GROUP BY e.id, e.public_id, e.employee_code, e.name_th ORDER BY e.employee_code",
            [$le, $year]
        );
        foreach ($rows as &$r) {
            $r['hash'] = hash('sha256', (string) $r['refs'] . '|' . $r['income'] . '|' . $r['wht'] . '|' . $r['sso']);
            $r['cert'] = $db->one("SELECT * FROM pay_tax_certificates WHERE employee_id = ? AND legal_entity_id = ? AND tax_year = ? AND status = 'issued' ORDER BY id DESC LIMIT 1", [$r['id'], $le, $year]);
            $r['outdated'] = $r['cert'] !== null && $r['cert']['ytd_hash'] !== $r['hash'];
            $r['has_id'] = $this->taxId((int) $r['id']) !== null;
        }
        return $rows;
    }

    /**
     * The full current Thai citizen id or tax id of a person, for an official filing file built in memory (0.46.0 ·
     * DEC-115) — the caller never stores it.
     *
     * @return array{value: string, last4: string}|null
     */
    public function idForFiling(int $employeeId): ?array
    {
        return $this->taxId($employeeId);
    }

    /** @return array{value: string, last4: string}|null the person's current Thai citizen id or tax id (decrypted only here) */
    private function taxId(int $employeeId): ?array
    {
        $row = $this->app->db()->one("SELECT value_enc, last4 FROM employee_identifiers WHERE employee_id = ? AND id_type IN ('TH_CITIZEN_ID','TH_TAX_ID') AND status = 'current' ORDER BY FIELD(id_type, 'TH_CITIZEN_ID', 'TH_TAX_ID') LIMIT 1", [$employeeId]);
        return $row !== null ? ['value' => $this->app->crypto()->decrypt((string) $row['value_enc']), 'last4' => (string) $row['last4']] : null;
    }

    /**
     * Issues certificates for everyone of the year who has none yet (out-of-date ones are reissued separately).
     *
     * @return array{issued: int, no_id: int, outdated: int}
     */
    public function issue(Authorizer $az, int $le, int $year): array
    {
        if (!$az->scope('payroll.approve')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $payer = $this->payer($le) ?? throw new BusinessException('payroll.error.no_payer', [], 409);
        $out = ['issued' => 0, 'no_id' => 0, 'outdated' => 0];
        foreach ($this->candidates($le, $year) as $c) {
            if ($c['cert'] !== null) {
                $out['outdated'] += $c['outdated'] ? 1 : 0;
                continue;
            }
            if (!$c['has_id']) {
                $out['no_id']++;
                continue;
            }
            $this->make($az, $le, $year, $c, $payer, null, null);
            $out['issued']++;
        }
        $this->app->audit()->record('payroll.certificates_issued', 'success', 'legal_entity', (string) $le, $le, null, ['tax_year' => $year] + $out);
        return $out;
    }

    /** Voids a certificate and issues a new one from the current totals (reason required). */
    public function reissue(Authorizer $az, string $certPid, string $reason): string
    {
        $db = $this->app->db();
        $old = $db->one("SELECT * FROM pay_tax_certificates WHERE public_id = ? AND status = 'issued'", [$certPid]) ?? throw HttpException::notFound();
        $le = (int) $old['legal_entity_id'];
        if (!$az->scope('payroll.approve')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['reason' => 'payroll.error.reissue_reason']);
        }
        $payer = $this->payer($le) ?? throw new BusinessException('payroll.error.no_payer', [], 409);
        $c = array_values(array_filter($this->candidates($le, (int) $old['tax_year']), static fn (array $x): bool => (int) $x['id'] === (int) $old['employee_id']))[0] ?? throw new BusinessException('payroll.error.no_totals', [], 409);
        if (!$c['has_id']) {
            throw new BusinessException('payroll.error.no_tax_id', [], 409);
        }
        $db->run("UPDATE pay_tax_certificates SET status = 'voided', voided_by = ?, voided_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$az->userId, $this->now(), $this->now(), $old['id']]);
        $pid = $this->make($az, $le, (int) $old['tax_year'], $c, $payer, (int) $old['id'], trim($reason));
        $this->app->audit()->record('payroll.certificate_reissued', 'success', 'pay_tax_certificate', $pid, $le, trim($reason), ['replaces' => $certPid]);
        return $pid;
    }

    /**
     * @param array<string, mixed> $c candidate row
     * @param array<string, mixed> $payer
     */
    private function make(Authorizer $az, int $le, int $year, array $c, array $payer, ?int $supersedes, ?string $reason): string
    {
        $db = $this->app->db();
        $tax = $this->taxId((int) $c['id']) ?? throw new BusinessException('payroll.error.no_tax_id', [], 409);
        $leCode = (string) $db->scalar('SELECT code FROM legal_entities WHERE id = ?', [$le]);
        $seq = (int) $db->scalar('SELECT COUNT(*) FROM pay_tax_certificates WHERE legal_entity_id = ? AND tax_year = ?', [$le, $year]) + 1;
        $no = 'WHT' . $year . '-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
        $pid = Ulid::generate($this->app->clock);
        $contacts = array_column($db->all("SELECT contact_type, value FROM employee_contacts WHERE employee_id = ? AND contact_type IN ('address_registered','address_current')", [$c['id']]), 'value', 'contact_type');
        $address = (string) ($contacts['address_registered'] ?? $contacts['address_current'] ?? '');
        $issuer = (string) $db->scalar('SELECT display_name FROM users WHERE id = ?', [$az->userId]);
        $bytes = $this->pdf(
            $no,
            $pid,
            $year,
            $payer,
            ['name' => (string) $c['name_th'], 'tax_id' => $tax['value'], 'address' => $address],
            Money::fromDb($c['income']),
            Money::fromDb($c['wht']),
            Money::fromDb($c['sso']),
            $issuer,
            $reason
        );
        $userId = $db->scalar("SELECT id FROM users WHERE employee_id = ? AND status <> 'deactivated' ORDER BY id LIMIT 1", [$c['id']]);
        $stored = $this->app->documents()->storeGenerated($az->userId, 'payroll.tax_certificate', $bytes, $no . '-' . $leCode . '-' . $c['employee_code'] . '.pdf', [
            'title' => mb_substr('หนังสือรับรองการหักภาษี ณ ที่จ่าย ' . $no . ' · ' . $c['employee_code'], 0, 200), 'legal_entity_id' => $le, 'related_type' => 'pay_tax_certificate',
            'related_id' => $pid, 'document_number' => $no, 'subject_user_id' => $userId !== null ? (int) $userId : null,
        ], 'system', true);
        $now = $this->now();
        $db->run(
            'INSERT INTO pay_tax_certificates (public_id, created_at, created_by, updated_at, updated_by, employee_id, legal_entity_id, tax_year, cert_no, income, wht, sso_employee, ytd_hash, payee_last4,
                    document_pid, sha256, status, supersedes_id, reason, issued_by, issued_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'issued\', ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $c['id'], $le, $year, $no, $c['income'], $c['wht'], $c['sso'], $c['hash'], $tax['last4'], $stored['document'], hash('sha256', $bytes), $supersedes, $reason, $az->userId, $now]
        );
        if ($userId !== null) {
            $this->app->notifier()->notify((int) $userId, 'payroll.certificate', 'payroll.notify.certificate', ['year' => (string) $year], '/me/tax', 'pay-cert-' . $pid);
        }
        return $pid;
    }

    /**
     * The certificate as the system lays it out — the fields of section 50 bis of the Revenue Code. Tax must check the
     * layout against the Revenue Department's current form before it is handed out (DEC-85).
     *
     * @param array<string, mixed> $payer
     * @param array{name: string, tax_id: string, address: string} $payee
     */
    private function pdf(string $no, string $pid, int $year, array $payer, array $payee, int $income, int $wht, int $sso, string $issuer, ?string $reason): string
    {
        $m = static fn (int $u): string => Money::format($u);
        $id = static fn (string $v): string => strlen($v) === 13 ? substr($v, 0, 1) . '-' . substr($v, 1, 4) . '-' . substr($v, 5, 5) . '-' . substr($v, 10, 2) . '-' . substr($v, 12, 1) : $v;
        $doc = new SimpleDocument('Blue OS · หนังสือรับรองการหักภาษี ณ ที่จ่าย · ' . $no . ' · เลขอ้างอิง ' . $pid);
        $doc->title('หนังสือรับรองการหักภาษี ณ ที่จ่าย', 'ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร · เลขที่ ' . $no . ($reason !== null ? ' · ฉบับแทนฉบับเดิม' : ''));
        $doc->heading('ผู้มีหน้าที่หักภาษี ณ ที่จ่าย')->pairs([['ชื่อ', (string) $payer['name']], ['เลขประจำตัวผู้เสียภาษี', $id((string) $payer['tax_id']) . (($payer['branch'] ?? '') !== '' ? ' · สาขา ' . $payer['branch'] : '')]])
            ->text('ที่อยู่: ' . $payer['address']);
        $doc->space(6)->heading('ผู้ถูกหักภาษี ณ ที่จ่าย')->pairs([['ชื่อ', $payee['name']], ['เลขประจำตัวผู้เสียภาษี', $id($payee['tax_id'])]])
            ->text('ที่อยู่: ' . ($payee['address'] !== '' ? $payee['address'] : '—'));
        $doc->space(6)->heading('ประเภทเงินได้ที่จ่าย')->table(
            ['ประเภทเงินได้', 'ปีภาษีที่จ่าย', 'จำนวนเงินที่จ่าย', 'ภาษีที่หักและนำส่งไว้'],
            [['เงินเดือน ค่าจ้าง ฯลฯ ตามมาตรา 40 (1)', (string) ($year + 543), $m($income), $m($wht)]],
            [0.4, 0.16, 0.22, 0.22],
            ['left', 'right', 'right', 'right'],
            ['รวมเงินที่จ่ายและภาษีที่หักนำส่ง', '', $m($income), $m($wht)]
        );
        $doc->text('รวมเงินภาษีที่หักนำส่ง (ตัวอักษร): ' . self::bahtText($wht));
        $doc->space(4)->text('เงินสมทบกองทุนประกันสังคม: ' . $m($sso) . ' บาท · ผู้จ่ายเงิน: หัก ณ ที่จ่าย');
        $doc->space(6)->text('ขอรับรองว่าข้อความและตัวเลขดังกล่าวข้างต้นถูกต้องตรงกับความจริงทุกประการ');
        $doc->text('ผู้มีหน้าที่หักภาษี ณ ที่จ่าย: ' . $payer['name'] . ' · ผู้ออกหนังสือ: ' . $issuer . ' · วันที่ออก: ' . $this->app->clock->now()->setTimezone(PayrollService::of($this->app)->tz())->format('Y-m-d'));
        if ($reason !== null) {
            $doc->text('ออกแทนฉบับเดิมเพราะ: ' . $reason, 8.5, '0.35 0.37 0.45');
        }
        $doc->space(6)->text('เอกสารนี้ออกจากระบบตามยอดสะสมที่อนุมัติแล้ว · ไม่มีลายมือชื่อที่วาดหรือภาพตราประทับ · รูปแบบนี้ระบบจัดเองจากรายการตามมาตรา 50 ทวิ ฝ่ายภาษีต้องตรวจกับแบบของกรมสรรพากรก่อนใช้', 8, '0.35 0.37 0.45');
        return $doc->output();
    }

    /** "1,234.50" → "หนึ่งพันสองร้อยสามสิบสี่บาทห้าสิบสตางค์" (units of 1/10 000 baht; satang rounded half up) */
    public static function bahtText(int $units): string
    {
        $satangAll = PayCalc::div(abs($units), 100, 1);
        $baht = intdiv($satangAll, 100);
        $satang = $satangAll % 100;
        $words = ($baht > 0 ? self::thaiNumber($baht) . 'บาท' : ($satang > 0 ? '' : 'ศูนย์บาท'));
        return ($units < 0 ? 'ลบ' : '') . $words . ($satang > 0 ? self::thaiNumber($satang) . 'สตางค์' : 'ถ้วน');
    }

    private static function thaiNumber(int $n): string
    {
        if ($n >= 1000000) {
            $rest = $n % 1000000;
            return self::thaiNumber(intdiv($n, 1000000)) . 'ล้าน' . ($rest === 1 ? 'เอ็ด' : ($rest > 0 ? self::thaiNumber($rest) : ''));
        }
        $digits = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
        $places = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];
        $s = (string) $n;
        $len = strlen($s);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $s[$i];
            $pos = $len - $i - 1;
            if ($d === 0) {
                continue;
            }
            if ($pos === 1 && $d === 1) {
                $out .= 'สิบ';
            } elseif ($pos === 1 && $d === 2) {
                $out .= 'ยี่สิบ';
            } elseif ($pos === 0 && $d === 1 && $len > 1) {
                $out .= 'เอ็ด';
            } else {
                $out .= $digits[$d] . $places[$pos];
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> certificates of a company and year (all statuses) */
    public function certificates(int $le, int $year): array
    {
        return $this->app->db()->all(
            'SELECT c.*, e.employee_code, e.name_th FROM pay_tax_certificates c JOIN employees e ON e.id = c.employee_id WHERE c.legal_entity_id = ? AND c.tax_year = ? ORDER BY e.employee_code, c.id DESC',
            [$le, $year]
        );
    }

    /**
     * The PDF of a certificate — for its owner (re-authenticated by the controller) or payroll of the company.
     *
     * @return array{bytes: string, mime: string, filename: string}
     */
    public function file(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $c = $db->one('SELECT * FROM pay_tax_certificates WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $emp = $db->scalar('SELECT employee_id FROM users WHERE id = ?', [$az->userId]);
        $own = $emp !== null && (int) $emp === (int) $c['employee_id'] && $c['status'] === 'issued';
        if (!$own && !$az->scope('payroll.view')->coversLegalEntity((int) $c['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $f = $this->app->documents()->moduleRead((string) $c['document_pid'], 'payroll', $az->userId, 'download');
        if (!hash_equals((string) $c['sha256'], hash('sha256', $f['bytes']))) {
            throw new BusinessException('payroll.error.payslip_damaged', [], 409);
        }
        if ($own) {
            $db->run('UPDATE pay_tax_certificates SET first_viewed_at = COALESCE(first_viewed_at, ?) WHERE id = ?', [$this->now(), $c['id']]);
        }
        $this->app->audit()->record('payroll.certificate_downloaded', 'success', 'pay_tax_certificate', $pid, (int) $c['legal_entity_id'], null, ['own' => $own]);
        return ['bytes' => $f['bytes'], 'mime' => 'application/pdf', 'filename' => $f['filename']];
    }
}
