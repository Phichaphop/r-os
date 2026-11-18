<?php

declare(strict_types=1);

namespace Blue\Modules\Employees;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;

/**
 * M09 personal data (DEC-82): my record, job history, contacts, emergency contacts, education, earlier jobs and my
 * identifiers (type + last digits only, as shown to me). Nothing is erased here: employment records are kept for the
 * employer's legal duties — corrections go through the profile change request.
 */
final class EmployeePersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        if ($employeeId === null) {
            return [];
        }
        $db = $app->db();
        $e = (array) $db->one('SELECT employee_code, name_th, name_en, preferred_name, status, hire_date, termination_date FROM employees WHERE id = ?', [$employeeId]);
        $jobs = $db->all(
            'SELECT h.effective_from, h.effective_to, l.code AS company, s.code AS site, d.code AS unit, h.position_title, h.employee_group, h.employment_type
               FROM employment_history h JOIN legal_entities l ON l.id = h.legal_entity_id LEFT JOIN sites s ON s.id = h.site_id LEFT JOIN departments d ON d.id = h.department_id
              WHERE h.employee_id = ? ORDER BY h.effective_from',
            [$employeeId]
        );
        return [
            ['key' => 'record', 'label' => 'employees.privacy.record', 'rows' => [$e]],
            ['key' => 'jobs', 'label' => 'employees.privacy.jobs', 'rows' => $jobs],
            ['key' => 'contacts', 'label' => 'employees.privacy.contacts', 'rows' => $db->all('SELECT contact_type, value FROM employee_contacts WHERE employee_id = ? ORDER BY contact_type', [$employeeId])],
            ['key' => 'emergency', 'label' => 'employees.privacy.emergency', 'rows' => $db->all('SELECT name, relation, phone FROM employee_emergency_contacts WHERE employee_id = ? ORDER BY sort_order', [$employeeId])],
            ['key' => 'education', 'label' => 'employees.privacy.education', 'rows' => $db->all('SELECT level, institution, field, graduated_year FROM employee_education WHERE employee_id = ? ORDER BY id', [$employeeId])],
            ['key' => 'prior_jobs', 'label' => 'employees.privacy.prior_jobs', 'rows' => $db->all('SELECT company, position, from_month, to_month FROM employee_prior_jobs WHERE employee_id = ? ORDER BY id', [$employeeId])],
            ['key' => 'identifiers', 'label' => 'employees.privacy.identifiers', 'rows' => array_map(
                static fn (array $i): array => ['type' => $i['id_type'], 'last_digits' => $i['last4'], 'status' => $i['status']],
                $db->all('SELECT id_type, last4, status FROM employee_identifiers WHERE employee_id = ? ORDER BY id', [$employeeId])
            )],
        ];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        return ['done' => [], 'kept' => $employeeId === null ? [] : ['employees.privacy.record' => 'employees.privacy.kept_record']];
    }
}
