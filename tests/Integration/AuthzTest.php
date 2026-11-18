<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Tests\Support\TestApp;

/** TC-AUTHZ (P0-04 §4): permission ∩ scope, deny-by-default, validity dates, SoD, two-admin approval, audit immutability. */
final class AuthzTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function az(string $username): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($username)['id']);
    }

    private function leId(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    public function testScopeIsLimitedToAssignedCompany(): void
    {
        $s = $this->az('hr.demo1')->scope('core.org.view');
        self::assertFalse($s->all);
        self::assertSame([$this->leId('DEMO1')], $s->legalEntityIds);
        self::assertFalse($this->az('hr.demo1')->can('core.org.manage'));
        self::assertTrue($this->az(TestApp::ADMIN)->scope('core.org.manage')->all);
    }

    public function testDenyByDefaultWithoutScopeRows(): void
    {
        $db = $this->t->app->db();
        $aid = (int) $db->scalar('SELECT a.id FROM user_role_assignments a JOIN users u ON u.id = a.user_id WHERE u.username = ?', ['hr.demo2']);
        $db->run('DELETE FROM access_scopes WHERE assignment_id = ?', [$aid]);
        self::assertTrue($this->az('hr.demo2')->scope('core.org.view')->isEmpty());
        self::assertSame([], $this->t->app->org()->tree($this->az('hr.demo2'), 'th'));
    }

    public function testExpiredFutureAndPendingAssignmentsGrantNothing(): void
    {
        $db = $this->t->app->db();
        $q = 'UPDATE user_role_assignments a JOIN users u ON u.id = a.user_id SET %s WHERE u.username = ?';
        $db->run(sprintf($q, "a.valid_to = '2026-11-08'"), ['hr.demo1']);
        self::assertFalse($this->az('hr.demo1')->can('core.org.view'), 'ended yesterday');
        $db->run(sprintf($q, "a.valid_to = NULL, a.valid_from = '2026-11-10'"), ['hr.demo1']);
        self::assertFalse($this->az('hr.demo1')->can('core.org.view'), 'starts tomorrow');
        $db->run(sprintf($q, "a.valid_from = '2026-11-01', a.status = 'pending_approval'"), ['hr.demo1']);
        self::assertFalse($this->az('hr.demo1')->can('core.org.view'), 'pending approval');
    }

    public function testMfaIsRequiredForAdminsApproversAndCrossCompany(): void
    {
        self::assertTrue($this->az(TestApp::ADMIN)->requiresMfa());
        self::assertTrue($this->az('payroll.checker')->requiresMfa(), 'PYA = money approver');
        self::assertTrue($this->az('auditor.demo')->requiresMfa(), 'organization scope = cross-company');
        self::assertFalse($this->az('hr.demo1')->requiresMfa());
    }

    public function testCannotGrantToSelf(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('iam.error.self_grant');
        $admin = $this->t->user(TestApp::ADMIN);
        $this->t->app->iam()->grant($this->az(TestApp::ADMIN), $admin['public_id'], 'AUD', [['organization', 1]], '2026-11-09', null, 'test');
    }

    public function testScopeIsRequiredAndValidated(): void
    {
        $u = $this->t->user('employee.demo1');
        try {
            $this->t->app->iam()->grant($this->az(TestApp::ADMIN), $u['public_id'], 'HRT', [], '2026-11-09', null, 'test');
            self::fail('scope required');
        } catch (ValidationException $e) {
            self::assertSame('iam.error.scope_required', $e->errors['scopes']);
        }
        try {
            $this->t->app->iam()->grant($this->az(TestApp::ADMIN), $u['public_id'], 'HRT', [['legal_entity', 999999]], '2026-11-09', null, 'test');
            self::fail('unknown scope ids are dropped → required');
        } catch (ValidationException $e) {
            self::assertSame('iam.error.scope_required', $e->errors['scopes']);
        }
    }

    public function testHighRiskGrantNeedsASecondAdmin(): void
    {
        $iam = $this->t->app->iam();
        $u = $this->t->user('employee.demo1');
        $res = $iam->grant($this->az(TestApp::ADMIN), $u['public_id'], 'PYA', [['legal_entity', $this->leId('DEMO1')]], '2026-11-09', null, 'cover leave');
        self::assertSame('pending_approval', $res['status']);
        self::assertNotContains('PYA', $this->az('employee.demo1')->roleCodes(), 'not effective until approved');
        try {
            $iam->decide($this->az(TestApp::ADMIN), $res['public_id'], true, 'self');
            self::fail('requester cannot approve');
        } catch (BusinessException $e) {
            self::assertSame('iam.error.sod_approve', $e->messageKey);
        }
        $iam->decide($this->az('sysadmin2.demo'), $res['public_id'], true, 'ok');
        self::assertContains('PYA', $this->az('employee.demo1')->roleCodes());
    }

    public function testNonAdminCannotManageUsersOrGrant(): void
    {
        $this->expectException(HttpException::class);
        $this->t->app->iam()->grant($this->az('hr.demo1'), $this->t->user('employee.demo1')['public_id'], 'HRT', [['legal_entity', $this->leId('DEMO1')]], '2026-11-09', null, 'x');
    }

    public function testSodReportFindsConflictingRoles(): void
    {
        $iam = $this->t->app->iam();
        $u = $this->t->user('payroll.maker');
        $res = $iam->grant($this->az(TestApp::ADMIN), $u['public_id'], 'PYA', [['legal_entity', $this->leId('DEMO1')]], '2026-11-09', null, 'test');
        self::assertSame(['PYO + PYA'], $res['sod_conflicts']);
        $iam->decide($this->az('sysadmin2.demo'), $res['public_id'], true, 'accept risk for test');
        $report = $iam->sodReport();
        self::assertContains(['username' => 'payroll.maker', 'roles' => 'PYO + PYA', 'legal_entity_id' => $this->leId('DEMO1')], $report);
    }

    public function testRevokingRightsSignsTheUserOut(): void
    {
        $app = $this->t->app;
        $s = $app->auth()->login('hr.demo1', $this->t->demoPassword, '10.0.0.1', 'ua')['session'];
        $aid = (string) $app->db()->scalar('SELECT a.public_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id WHERE u.username = ?', ['hr.demo1']);
        $app->iam()->revoke($this->az(TestApp::ADMIN), $aid, 'left the team');
        self::assertNull($app->sessions()->current($s['token']));
        self::assertFalse($this->az('hr.demo1')->can('core.org.view'));
    }

    public function testAuditTrailIsAppendOnly(): void
    {
        $db = $this->t->app->db();
        self::assertSame('triggers', $db->scalar("SELECT info_value FROM system_info WHERE info_key = 'audit_guard'"));
        self::assertGreaterThan(0, (int) $db->scalar('SELECT COUNT(*) FROM audit_events'));
        foreach (['UPDATE audit_events SET reason = \'tampered\'', 'DELETE FROM audit_events'] as $sql) {
            try {
                $db->exec($sql);
                self::fail('audit must reject: ' . $sql);
            } catch (\PDOException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $src = (string) file_get_contents(__DIR__ . '/../../core/Audit/AuditLogger.php');
        self::assertDoesNotMatchRegularExpression('/UPDATE\s+audit_events|DELETE\s+FROM\s+audit_events/i', $src);
    }
}
