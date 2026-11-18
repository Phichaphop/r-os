<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use Blue\Core\App;

/**
 * The personnel file vocabulary (04 §6.19.1) and the company's file rules (Config Registry "employees.file_rules").
 *
 * Categories A–J and the document types are a fixed vocabulary — WHICH documents a company requires, how early to warn
 * before something expires and which exit-checklist items to use are company decisions: nothing is required until HR
 * proposes the rules and another HR person approves them (DEC-67). The exit checklist below is only a starting
 * suggestion shown in the form; it is not in force until approved as part of the rules.
 */
final class FileRules
{
    /** category => permission that may see and manage it */
    public const CATEGORIES = [
        'A' => 'employees.file', 'B' => 'employees.file', 'C' => 'employees.file', 'D' => 'employees.file',
        'E' => 'employees.file_pay', 'F' => 'employees.file', 'G' => 'employees.file',
        'H' => 'employees.relations', 'I' => 'employees.medical', 'J' => 'employees.file',
    ];

    /** document type => category (lang key employees.doc.<type>) */
    public const DOC_TYPES = [
        'id_card' => 'A', 'house_registration' => 'A', 'name_change' => 'A', 'photo' => 'A', 'passport' => 'A',
        'application' => 'B', 'privacy_notice' => 'B', 'offer_letter' => 'B', 'contract' => 'B', 'contract_amendment' => 'B',
        'probation_result' => 'B', 'nda' => 'B', 'guarantee' => 'B',
        'education_certificate' => 'C', 'transcript' => 'C', 'professional_license' => 'C', 'work_permit' => 'C', 'visa' => 'C',
        'driving_license' => 'C', 'criminal_check' => 'C',
        'transfer_order' => 'D', 'promotion_letter' => 'D', 'appointment_order' => 'D',
        'bank_book' => 'E', 'tax_form' => 'E', 'sso_form' => 'E', 'salary_letter' => 'E',
        'time_evidence' => 'F',
        'appraisal' => 'G', 'training_certificate' => 'G', 'jd_acknowledgment' => 'G', 'policy_acknowledgment' => 'G',
        'warning_letter' => 'H', 'investigation' => 'H', 'disciplinary_decision' => 'H',
        'health_check' => 'I', 'fitness_for_work' => 'I', 'accident_report' => 'I',
        'resignation_letter' => 'J', 'termination_letter' => 'J', 'clearance_form' => 'J', 'employment_certificate' => 'J', 'other' => '*',
    ];

    /** Types that usually carry an expiry date — the form asks for it. */
    public const EXPIRING = ['id_card', 'passport', 'work_permit', 'visa', 'professional_license', 'driving_license', 'contract', 'health_check', 'fitness_for_work'];

    /** Types the employee may be allowed to see in self-service (HR still ticks "visible to the employee" per file). */
    public const SELF_VISIBLE = ['A', 'B', 'C', 'D', 'G', 'J'];

    public const IDENTIFIER_TYPES = ['TH_CITIZEN_ID', 'TH_PASSPORT', 'FOREIGN_PASSPORT', 'TH_WORK_PERMIT', 'TH_PINK_CARD', 'TH_TAX_ID', 'OTHER'];

    public const SEPARATION_TYPES = ['resigned', 'terminated', 'contract_end', 'retired', 'deceased', 'probation_failed', 'other'];

    public const OFFBOARD_OWNERS = ['hr', 'manager', 'it', 'payroll', 'finance', 'admin'];

    /** Starting suggestion for the exit checklist (company decides — 6.19.5 ข้อ 2); labels: employees.offb_suggest.<code> */
    public const SUGGESTED_OFFBOARDING = [
        ['code' => 'handover', 'owner' => 'manager', 'days' => 0], ['code' => 'assets', 'owner' => 'admin', 'days' => 0],
        ['code' => 'accounts', 'owner' => 'it', 'days' => 0], ['code' => 'final_pay', 'owner' => 'payroll', 'days' => 0],
        ['code' => 'sso', 'owner' => 'payroll', 'days' => 0], ['code' => 'certificate', 'owner' => 'hr', 'days' => 0],
    ];

    /**
     * Validates a proposal (JSON value of employees.file_rules).
     *
     * @param array<string, mixed> $in required[type][] (type = employment type or "*"), expiry_notice_days, offboarding[] {code,label,owner,days}
     * @return array{0: array<string, mixed>|null, 1: array<string, string>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $required = [];
        foreach ((array) ($in['required'] ?? []) as $type => $docs) {
            $type = (string) $type;
            if ($type !== '*' && !in_array($type, EmployeeService::TYPES, true)) {
                $errors['required'] = 'employees.rules_error.type';
                continue;
            }
            $list = [];
            foreach ((array) $docs as $d) {
                $d = (string) $d;
                if (!isset(self::DOC_TYPES[$d]) || $d === 'other') {
                    $errors['required'] = 'employees.rules_error.doc';
                    continue;
                }
                $list[$d] = true;
            }
            if ($list !== []) {
                $required[$type] = array_keys($list);
            }
        }
        $days = (string) ($in['expiry_notice_days'] ?? '');
        if (preg_match('/^\d{1,3}$/', $days) !== 1 || (int) $days < 1 || (int) $days > 365) {
            $errors['expiry_notice_days'] = 'employees.rules_error.days';
        }
        $off = [];
        $codes = [];
        foreach ((array) ($in['offboarding'] ?? []) as $row) {
            $row = (array) $row;
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue; // empty row of the form
            }
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            $owner = (string) ($row['owner'] ?? '');
            $d = (string) ($row['days'] ?? '0');
            if (
                preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code) !== 1 || isset($codes[$code]) || mb_strlen($label) > 160
                || !in_array($owner, self::OFFBOARD_OWNERS, true) || preg_match('/^-?\d{1,3}$/', $d) !== 1 || abs((int) $d) > 90
            ) {
                $errors['offboarding'] = 'employees.rules_error.offboarding';
                continue;
            }
            $codes[$code] = true;
            $off[] = ['code' => $code, 'label' => $label, 'owner' => $owner, 'days' => (int) $d];
        }
        if (count($off) > 30) {
            $errors['offboarding'] = 'employees.rules_error.offboarding';
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        ksort($required);
        return [['required' => $required, 'expiry_notice_days' => (int) $days, 'offboarding' => $off], []];
    }

    /**
     * Human-readable rows for the approval page (Config Registry "describe").
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function describe(App $app, mixed $value): array
    {
        $tr = $app->translator();
        $t = static fn (string $k, array $p = []): string => $tr->get($k, $p);
        $value = is_array($value) ? $value : [];
        $rows = [];
        foreach ((array) ($value['required'] ?? []) as $type => $docs) {
            $rows[] = [
                $t('employees.rules_required_for', ['type' => $type === '*' ? $t('employees.rules_all_types') : $t('employees.type.' . $type)]),
                implode(', ', array_map(static fn ($d): string => $t('employees.doc.' . $d), (array) $docs)),
            ];
        }
        if ($rows === []) {
            $rows[] = [$t('employees.rules_required'), $t('employees.rules_none')];
        }
        $rows[] = [$t('employees.rules_expiry_days'), (string) (int) ($value['expiry_notice_days'] ?? 0)];
        foreach ((array) ($value['offboarding'] ?? []) as $o) {
            $rows[] = [$t('employees.rules_offboarding_item'), (string) $o['label'] . ' · ' . $t('employees.owner.' . $o['owner'])];
        }
        return $rows;
    }

    /** Thai citizen / tax ID check digit (13 digits): the last digit = (11 − Σ dᵢ·(13 − i) mod 11) mod 10. */
    public static function thaiIdValid(string $digits): bool
    {
        if (preg_match('/^\d{13}$/', $digits) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits[$i] * (13 - $i);
        }
        return (11 - $sum % 11) % 10 === (int) $digits[12];
    }
}
