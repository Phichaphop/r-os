<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Tests\Support\TestApp;

/** DEC-52: two-step verification is an organization switch, off by default, changed only by SYS with re-auth + audit. */
final class SecurityPolicyTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'ci', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    public function testFreshInstallHasMfaOffAndAdminsGoStraightIn(): void
    {
        self::assertFalse($this->t->app->security()->mfaEnforced());
        $b = $this->t->browser();
        $res = $b->login(TestApp::ADMIN);
        self::assertSame(303, $res->status);
        self::assertStringEndsWith('/', (string) $res->headers['Location']);
        self::assertSame(200, $b->get('/admin/users')->status, 'no MFA gate while the switch is off');
        self::assertSame(302, $b->get('/mfa/setup')->status, 'MFA pages are closed while off');
        $me = $b->get('/me');
        self::assertStringContainsString($this->t->app->translator()->get('profile.mfa.system_off'), $me->body);
        // re-authentication falls back to the password
        $reauth = $b->get('/reauth', ['next' => '/admin/security']);
        self::assertStringContainsString('name="password"', $reauth->body);
    }

    public function testOnlyTheSystemAdminCanSeeOrChangeTheSwitch(): void
    {
        $hr = $this->t->browser();
        $hr->login('hr.demo1');
        self::assertSame(403, $hr->get('/admin/security')->status);
        self::assertSame(403, $hr->post('/admin/security', ['mfa_enforced' => '1', 'reason' => 'x'])->status);
        self::assertFalse($this->t->app->security()->mfaEnforced());
    }

    public function testTurningOnNeedsReauthAndReasonIsAuditedAndForcesEnrolment(): void
    {
        $b = $this->t->browser();
        $b->login(TestApp::ADMIN);
        self::assertSame(200, $b->get('/admin/security')->status);

        $res = $b->post('/admin/security', ['mfa_enforced' => '1', 'reason' => 'ก่อนใช้ข้อมูลจริง']);
        self::assertStringContainsString('/reauth', (string) ($res->headers['Location'] ?? ''), 're-authentication first');
        self::assertFalse($this->t->app->security()->mfaEnforced());

        $b->get('/reauth');
        $b->post('/reauth', ['password' => TestApp::ADMIN_PASSWORD, 'next' => '/admin/security']);
        self::assertSame(422, $b->post('/admin/security', ['mfa_enforced' => '1', 'reason' => ''])->status, 'reason required');
        self::assertSame(303, $b->post('/admin/security', ['mfa_enforced' => '1', 'reason' => 'ก่อนใช้ข้อมูลจริง'])->status);
        self::assertTrue($this->t->app->security()->mfaEnforced());

        $row = $this->t->app->db()->one("SELECT reason, diff_json FROM audit_events WHERE action = 'security.mfa_policy_changed'");
        self::assertSame('ก่อนใช้ข้อมูลจริง', $row['reason'] ?? null);
        self::assertSame(['before' => false, 'after' => true], json_decode((string) $row['diff_json'], true));

        // the admin (SYS) must now enrol before doing anything else
        $next = $b->get('/admin/users');
        self::assertStringEndsWith('/mfa/setup', (string) ($next->headers['Location'] ?? ''));
        // an employee (no high-risk role, single company) is still not forced
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        self::assertSame(200, $emp->get('/me')->status);
    }

    public function testTurningOffKeepsEnrolledDevices(): void
    {
        $this->t->app->security()->setMfaEnforced(true, 'test');
        $b = $this->t->browser();
        $b->login(TestApp::ADMIN); // enrols
        self::assertSame(1, (int) $this->t->user(TestApp::ADMIN)['mfa_enabled']);
        $this->t->app->security()->setMfaEnforced(false, 'test');
        self::assertSame(1, (int) $this->t->user(TestApp::ADMIN)['mfa_enabled'], 'factor kept');
        $fresh = $this->t->browser();
        $res = $fresh->login(TestApp::ADMIN);
        self::assertStringEndsWith('/', (string) $res->headers['Location']);
        self::assertSame(200, $fresh->get('/admin/users')->status);
    }
}
