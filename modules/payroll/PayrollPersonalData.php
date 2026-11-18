<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;
use Blue\Core\Support\Money;

/**
 * Payroll personal data (DEC-84, DEC-86): my pay profiles (account shown as last 4 digits only), my payslips (pay date
 * and figures of approved runs), my year-to-date totals, my tax allowance declarations and tax certificates. Nothing is erased: wage and tax records are kept for the
 * employer's legal duties (retention class wage_time_records / payroll_tax_sso).
 */
final class PayrollPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        if ($employeeId === null) {
            return [];
        }
        $db = $app->db();
        $m = static fn (mixed $v): string => Money::format(Money::fromDb($v), 2, false);
        $profiles = array_map(
            static fn (array $p): array => ['company' => $p['company'], 'effective_from' => $p['effective_from'], 'pay_basis' => $p['pay_basis'], 'amount' => $m($p['base_amount']),
            'bank' => $p['bank_code'], 'account_last_digits' => $p['account_last4'], 'status' => $p['status']],
            $db->all('SELECT p.*, l.code AS company FROM pay_profiles p JOIN legal_entities l ON l.id = p.legal_entity_id WHERE p.employee_id = ? ORDER BY p.effective_from', [$employeeId])
        );
        $slips = array_map(
            static fn (array $s): array => ['company' => $s['company'], 'run' => $s['label'], 'pay_date' => $s['pay_date'], 'gross' => $m($s['gross']), 'deductions' => $m($s['deductions']), 'net' => $m($s['net'])],
            $db->all("SELECT l.code AS company, r.label, r.pay_date, re.gross, re.deductions, re.net FROM pay_payslips s JOIN pay_runs r ON r.id = s.run_id JOIN legal_entities l ON l.id = s.legal_entity_id
                       JOIN pay_run_employees re ON re.run_id = s.run_id AND re.employee_id = s.employee_id WHERE s.employee_id = ? AND s.status = 'published' ORDER BY r.pay_date", [$employeeId])
        );
        $ytd = array_map(
            static fn (array $y): array => ['company' => $y['company'], 'tax_year' => (int) $y['tax_year'], 'source' => $y['source_type'], 'as_of' => $y['as_of'], 'gross' => $m($y['gross']),
            'taxable_income' => $m($y['taxable_income']), 'withholding_tax' => $m($y['wht']), 'social_security' => $m($y['sso_employee'])],
            $db->all("SELECT y.*, l.code AS company FROM pay_ytd_entries y JOIN legal_entities l ON l.id = y.legal_entity_id WHERE y.employee_id = ? AND y.status = 'approved' ORDER BY y.tax_year, y.as_of", [$employeeId])
        );
        // 6C: allowance declarations (what was declared and the decision) and tax certificates (number, totals — the PDF stays in the register)
        $decl = array_map(
            static fn (array $d): array => ['company' => $d['company'], 'tax_year' => (int) $d['tax_year'], 'items' => (string) $d['items_json'], 'status' => $d['status'],
            'sent_at' => $d['created_at'], 'decision_note' => $d['decision_note']],
            $db->tableExists('pay_tax_declarations') ? $db->all('SELECT d.*, l.code AS company FROM pay_tax_declarations d JOIN legal_entities l ON l.id = d.legal_entity_id WHERE d.employee_id = ? ORDER BY d.tax_year, d.id', [$employeeId]) : []
        );
        $certs = array_map(
            static fn (array $c): array => ['company' => $c['company'], 'tax_year' => (int) $c['tax_year'], 'number' => $c['cert_no'], 'income' => $m($c['income']), 'withholding_tax' => $m($c['wht']),
            'social_security' => $m($c['sso_employee']), 'tax_id_last_digits' => $c['payee_last4'], 'status' => $c['status'], 'issued_at' => $c['issued_at']],
            $db->tableExists('pay_tax_certificates') ? $db->all('SELECT c.*, l.code AS company FROM pay_tax_certificates c JOIN legal_entities l ON l.id = c.legal_entity_id WHERE c.employee_id = ? ORDER BY c.tax_year, c.id', [$employeeId]) : []
        );
        return [
            ['key' => 'profiles', 'label' => 'payroll.privacy.profiles', 'rows' => $profiles],
            ['key' => 'payslips', 'label' => 'payroll.privacy.payslips', 'rows' => $slips],
            ['key' => 'ytd', 'label' => 'payroll.privacy.ytd', 'rows' => $ytd],
            ['key' => 'declarations', 'label' => 'payroll.privacy.declarations', 'rows' => $decl],
            ['key' => 'certificates', 'label' => 'payroll.privacy.certificates', 'rows' => $certs],
        ];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        if ($employeeId === null) {
            return ['done' => [], 'kept' => []];
        }
        return ['done' => [], 'kept' => ['payroll.privacy.payslips' => 'payroll.privacy.kept', 'payroll.privacy.profiles' => 'payroll.privacy.kept',
            'payroll.privacy.declarations' => 'payroll.privacy.kept', 'payroll.privacy.certificates' => 'payroll.privacy.kept']];
    }
}
