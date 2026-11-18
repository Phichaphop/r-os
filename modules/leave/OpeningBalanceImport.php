<?php

declare(strict_types=1);

namespace Blue\Modules\Leave;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Import\ImportDefinition;
use Blue\Modules\Employees\EmployeeService;

/**
 * Opening leave balances when a company starts using Blue OS mid-year (DEC-27, P0-01 GAP-09): one row per employee,
 * type and leave year → ledger entry "opening_balance" (provenance: import batch). Once per employee/type/year —
 * a correction afterwards is an HR adjustment with a reason.
 */
final class OpeningBalanceImport implements ImportDefinition
{
    private function __construct(private readonly App $app)
    {
    }

    public static function create(App $app): self
    {
        return new self($app);
    }

    public function code(): string
    {
        return 'leave.opening';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function titleKey(): string
    {
        return 'leave.import.title';
    }

    public function columns(): array
    {
        return [
            ['key' => 'employee_code', 'label' => 'employees.field.code', 'required' => true, 'example' => 'D1-0004'],
            ['key' => 'type_code', 'label' => 'leave.field.type_code', 'required' => true, 'example' => 'ANNUAL', 'help' => 'leave.import.help_type'],
            ['key' => 'year', 'label' => 'leave.field.year', 'required' => true, 'example' => '2026', 'help' => 'leave.import.help_year'],
            ['key' => 'units', 'label' => 'leave.field.opening_units', 'required' => true, 'example' => '4.5', 'help' => 'leave.import.help_units'],
        ];
    }

    public function prepare(Authorizer $az, array $params): array
    {
        $le = (int) ($params['le'] ?? 0);
        if ($le <= 0 || !$az->scope('leave.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        return ['le' => (string) $le, 'legal_entity_id' => $le];
    }

    public function validateRow(array $row, array $params, array &$seen): array
    {
        $db = $this->app->db();
        $errors = [];
        $le = (int) $params['legal_entity_id'];
        $code = strtoupper(trim($row['employee_code'] ?? ''));
        $emp = $db->one('SELECT * FROM employees WHERE employee_code = ?', [$code]);
        $job = $emp !== null ? EmployeeService::of($this->app)->employment((int) $emp['id'], EmployeeService::of($this->app)->today()) : null;
        if ($emp === null || $job === null || (int) $job['legal_entity_id'] !== $le) {
            $errors['employee_code'] = 'leave.import.no_employee';
        }
        $tcode = strtoupper(trim($row['type_code'] ?? ''));
        $type = $db->one('SELECT * FROM lv_types WHERE legal_entity_id = ? AND code = ?', [$le, $tcode]);
        if ($type === null) {
            $errors['type_code'] = 'leave.import.no_type';
        }
        $year = trim($row['year'] ?? '');
        $y = ctype_digit($year) ? (int) $year : 0;
        if ($y > 2400) {
            $y -= 543; // Buddhist Era
        }
        if ($y < 2000 || $y > 2100) {
            $errors['year'] = 'leave.error.year';
        }
        $u = trim($row['units'] ?? '');
        $units = is_numeric($u) ? (float) $u : null;
        if ($units === null || abs($units) > 366 || fmod(abs($units) * 2, 1.0) !== 0.0) {
            $errors['units'] = 'leave.error.adjust_units';
        }
        $key = $code . '|' . $tcode . '|' . $y;
        if (isset($seen[$key])) {
            $errors['employee_code'] = 'import.error.duplicate_in_file';
        }
        $seen[$key] = 1;
        if ($errors === [] && $emp !== null && $type !== null && LeaveService::of($this->app)->hasOpening((int) $emp['id'], (int) $type['id'], $y)) {
            $errors['year'] = 'leave.import.exists';
        }
        return ['data' => ['employee_id' => (int) ($emp['id'] ?? 0), 'type_id' => (int) ($type['id'] ?? 0), 'year' => $y, 'units' => (float) $units], 'errors' => $errors, 'action' => 'create'];
    }

    public function apply(Authorizer $az, array $params, array $rows, int $batchId): int
    {
        $db = $this->app->db();
        $svc = LeaveService::of($this->app);
        $n = 0;
        foreach ($rows as $r) {
            $type = $db->one('SELECT * FROM lv_types WHERE id = ?', [$r['type_id']]);
            if ($type !== null && $svc->postOpening(['id' => (int) $r['employee_id']], $type, (int) $r['year'], (float) $r['units'], $az->userId, $batchId)) {
                $n++;
            }
        }
        return $n;
    }

    public function sensitiveColumns(): array
    {
        return [];
    }

    public function backLink(array $params): string
    {
        return '/leave/admin?le=' . (int) $params['legal_entity_id'];
    }
}
