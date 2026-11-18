<?php

declare(strict_types=1);

namespace Blue\Core\Install;

use Blue\Core\Audit\AuditContext;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Auth\PasswordPolicy;
use Blue\Core\Database\Connection;
use Blue\Core\Error\ValidationException;
use Blue\Core\Org\Translations;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;
use Blue\Core\Support\Ulid;

/**
 * First data of a new installation (spec 03 §6.0 steps 5–6):
 * - the organization ("กลุ่มบริษัท / Group", DEC-19)
 * - the first System Admin (active, organization scope) — the only grant that does not need a second admin
 * - optional DEMO data (DEC-34): one demo company (Q-20, 0.40.0 — the full structure and people come from CompanyDemo),
 *   sites, departments and demo users; the automated tests add a second demo company for cross-company (IDOR) checks
 */
final class Bootstrap
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array{org_name_th:string, org_name_en:string, admin_username:string, admin_name:string, admin_email:string, admin_password:string, admin_password_confirm:string} $in
     * @return array<string, string> validation errors (field => key); empty = valid
     */
    public static function validate(array $in, int $minPassword): array
    {
        $errors = [];
        if (trim($in['org_name_th']) === '' && trim($in['org_name_en']) === '') {
            $errors['org_name_th'] = 'form.required';
        }
        $username = mb_strtolower(trim($in['admin_username']));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $username)) {
            $errors['admin_username'] = 'iam.error.username';
        }
        if (trim($in['admin_name']) === '') {
            $errors['admin_name'] = 'form.required';
        }
        if ($in['admin_email'] !== '' && filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['admin_email'] = 'iam.error.email';
        }
        $problems = (new PasswordPolicy($minPassword))->check($in['admin_password'], $username, $in['admin_email']);
        if ($problems !== []) {
            $errors['admin_password'] = $problems[0];
        } elseif (!hash_equals($in['admin_password'], $in['admin_password_confirm'])) {
            $errors['admin_password_confirm'] = 'auth.pw.mismatch';
        }
        return $errors;
    }

    /**
     * @param array{org_name_th:string, org_name_en:string, admin_username:string, admin_name:string, admin_email:string, admin_password:string, admin_password_confirm:string} $in
     */
    public function run(array $in, bool $demo, bool $secondCompany = false): ?string
    {
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM users') > 0) {
            throw new ValidationException(['admin_username' => 'install.error.already_bootstrapped']);
        }
        $ctx = new AuditContext();
        $ctx->actorType = 'system';
        $ctx->correlationId = Ulid::generate($this->clock);
        $audit = new AuditLogger($this->db, $this->clock, $ctx);
        $tr = new Translations($this->db);

        return $this->db->transactional(function () use ($in, $demo, $secondCompany, $audit, $tr): ?string {
            $orgId = $this->insert('organizations', ['code' => $demo ? 'DEMO' : 'GROUP', 'is_demo' => (int) $demo]);
            $tr->put('organization', $orgId, 'name', ['th' => $in['org_name_th'], 'en' => $in['org_name_en']]);

            $adminId = $this->insert('users', [
                'username' => mb_strtolower(trim($in['admin_username'])),
                'display_name' => trim($in['admin_name']),
                'email' => $in['admin_email'] === '' ? null : mb_strtolower($in['admin_email']),
                'password_hash' => password_hash($in['admin_password'], PASSWORD_DEFAULT),
                'status' => 'active',
                'password_changed_at' => $this->now(),
            ]);
            $this->assign($adminId, 'SYS', [['organization', $orgId]], 'installer: first system administrator', null);
            $audit->record('iam.bootstrap', 'success', 'user', (string) $this->db->scalar('SELECT public_id FROM users WHERE id = ?', [$adminId]), null, 'first system administrator created by installer', [], null);

            $demoPassword = null;
            if ($demo) {
                $demoPassword = $this->seedDemo($orgId, $adminId, $tr, $secondCompany);
                // which demo this is: 'company' = the one full demo company of a real install (CompanyDemo adds its
                // structure and people); 'test' = the two-company fixture of the automated tests
                $this->db->run(
                    'INSERT INTO system_info (info_key, info_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE info_value = VALUES(info_value)',
                    ['demo_profile', $secondCompany ? 'test' : 'company', $this->now()]
                );
                $audit->record('install.demo_data', 'success', null, null, null, 'DEC-34 demo data');
            }
            return $demoPassword;
        });
    }

    /** DEC-34 demo data. Returns the shared password of the demo users (shown once on the done page). */
    private function seedDemo(int $orgId, int $adminId, Translations $tr, bool $secondCompany): string
    {
        $le1 = $this->insert('legal_entities', ['organization_id' => $orgId, 'code' => 'DEMO1', 'registration_no' => 'DEMO-0105500000001', 'is_demo' => 1]);
        $tr->put('legal_entity', $le1, 'name', ['th' => 'บริษัทตัวอย่าง จำกัด (DEMO)', 'en' => 'Demo Company Ltd. (DEMO)']);
        $le2 = null;
        if ($secondCompany) { // tests only (cross-company isolation); a real install has one demo company (Q-20)
            $le2 = $this->insert('legal_entities', ['organization_id' => $orgId, 'code' => 'DEMO2', 'registration_no' => 'DEMO-0105500000002', 'is_demo' => 1]);
            $tr->put('legal_entity', $le2, 'name', ['th' => 'บริษัทตัวอย่างสอง จำกัด (DEMO)', 'en' => 'Demo Two Co., Ltd. (DEMO)']);
        }

        $hq1 = $this->insert('sites', ['legal_entity_id' => $le1, 'code' => 'HQ', 'site_type' => 'hq', 'branch_no' => '00000', 'is_demo' => 1]);
        $tr->put('site', $hq1, 'name', ['th' => 'สำนักงานใหญ่', 'en' => 'Head office']);
        $br1 = $this->insert('sites', ['legal_entity_id' => $le1, 'code' => 'BR1', 'site_type' => 'branch', 'branch_no' => '00001', 'is_demo' => 1]);
        $tr->put('site', $br1, 'name', ['th' => 'สาขาตัวอย่าง 1', 'en' => 'Demo branch 1']);
        $units = [[$le1, $hq1, 'HR', 'ฝ่ายบุคคล', 'Human Resources'], [$le1, $hq1, 'ACC', 'ฝ่ายบัญชี', 'Accounting'],
            [$le1, $br1, 'SALES', 'ฝ่ายขาย', 'Sales'], [$le1, $hq1, 'IT', 'ฝ่ายเทคโนโลยีสารสนเทศ', 'IT']];
        if ($le2 !== null) {
            $hq2 = $this->insert('sites', ['legal_entity_id' => $le2, 'code' => 'HQ', 'site_type' => 'hq', 'branch_no' => '00000', 'is_demo' => 1]);
            $tr->put('site', $hq2, 'name', ['th' => 'สำนักงานใหญ่', 'en' => 'Head office']);
            $units[] = [$le2, $hq2, 'OPS', 'ฝ่ายปฏิบัติการ', 'Operations'];
        }
        foreach ($units as [$le, $site, $code, $th, $en]) {
            $d = $this->insert('departments', ['legal_entity_id' => $le, 'site_id' => $site, 'code' => $code, 'is_demo' => 1]);
            $tr->put('department', $d, 'name', ['th' => $th, 'en' => $en]);
        }

        $password = 'Demo-' . substr(bin2hex(random_bytes(8)), 0, 12);
        $users = [
            ['hr.demo1', 'HR Admin บริษัทตัวอย่าง (DEMO)', 'HRA', [['legal_entity', $le1]]],
            ['payroll.maker', 'Payroll Officer (DEMO)', 'PYO', [['legal_entity', $le1]]],
            ['payroll.checker', 'Payroll Approver (DEMO)', 'PYA', [['legal_entity', $le1]]],
            ['cashier.br1', 'แคชเชียร์ สาขา 1 (DEMO)', 'CSH', [['site', $br1]]],
            ['employee.demo1', 'พนักงานตัวอย่าง (DEMO)', 'EMP', [['legal_entity', $le1]]],
            ['auditor.demo', 'ผู้ตรวจสอบ (DEMO)', 'AUD', [['organization', $orgId]]],
            ['sysadmin2.demo', 'ผู้ดูแลระบบคนที่สอง (DEMO)', 'SYS', [['organization', $orgId]]],
        ];
        if ($le2 !== null) {
            $users[] = ['hr.demo2', 'HR Admin บริษัทตัวอย่างสอง (DEMO)', 'HRA', [['legal_entity', $le2]]];
        }
        foreach ($users as [$username, $name, $role, $scopes]) {
            $uid = $this->insert('users', [
                'username' => $username, 'display_name' => $name, 'email' => $username . '@demo.invalid',
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active',
                'password_changed_at' => $this->now(), 'is_demo' => 1,
            ]);
            $this->assign($uid, $role, $scopes, 'DEC-34 demo user', $adminId);
        }
        return $password;
    }

    /** @param list<array{0:string, 1:int}> $scopes */
    private function assign(int $userId, string $role, array $scopes, string $reason, ?int $by): void
    {
        $roleId = (int) $this->db->scalar('SELECT id FROM roles WHERE code = ?', [$role]);
        $aid = $this->insert('user_role_assignments', [
            'user_id' => $userId, 'role_id' => $roleId, 'status' => 'active', 'valid_from' => $this->clock->now()->format('Y-m-d'),
            'requested_by' => $by, 'approved_by' => $by, 'approved_at' => $by !== null ? $this->now() : null, 'reason' => $reason,
        ]);
        foreach ($scopes as [$level, $id]) {
            $this->db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [$aid, $level, $id]);
        }
    }

    /** @param array<string, mixed> $data */
    private function insert(string $table, array $data): int
    {
        $now = $this->now();
        $data += ['public_id' => Ulid::generate($this->clock), 'created_at' => $now, 'updated_at' => $now, 'row_version' => 1];
        $cols = array_keys($data);
        $this->db->run(
            sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $table, implode('`,`', $cols), implode(',', array_fill(0, count($cols), '?'))),
            array_values($data)
        );
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
