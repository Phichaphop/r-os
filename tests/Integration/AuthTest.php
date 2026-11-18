<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Error\ValidationException;
use Blue\Tests\Support\TestApp;

/** TC-AUTH (P0-06 §8, P0-04 §3, DEC-51): sign-in, lockout, sessions, activation, reset, MFA. */
final class AuthTest extends TestCase
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

    public function testLoginSuccessAndGenericFailures(): void
    {
        $auth = $this->t->app->auth();
        $ok = $auth->login('HR.DEMO1', $this->t->demoPassword, '10.0.0.1', 'ua');
        self::assertTrue($ok['ok'], 'username is case-insensitive');
        $bad = $auth->login('hr.demo1', 'wrong-password-123', '10.0.0.1', 'ua');
        $unknown = $auth->login('nobody', 'wrong-password-123', '10.0.0.1', 'ua');
        self::assertSame($bad['error'], $unknown['error'], 'unknown user and wrong password look identical');
        self::assertSame('auth.error.invalid', $bad['error']);
    }

    public function testFiveFailuresLockTheAccountForFifteenMinutes(): void
    {
        $auth = $this->t->app->auth();
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($auth->login('hr.demo1', 'wrong-password-' . $i, '10.0.0.' . $i, 'ua')['ok']);
        }
        self::assertFalse($auth->login('hr.demo1', $this->t->demoPassword, '10.0.0.9', 'ua')['ok'], 'locked even with the right password');
        self::assertNotNull($this->t->user('hr.demo1')['locked_until']);
        $this->t->clock->advance('+16 minutes');
        self::assertTrue($auth->login('hr.demo1', $this->t->demoPassword, '10.0.0.9', 'ua')['ok'], 'lock expires');
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'auth.lockout'"));
    }

    public function testInactiveAccountsCannotSignIn(): void
    {
        $db = $this->t->app->db();
        foreach (['suspended', 'invited', 'deactivated'] as $status) {
            $db->run('UPDATE users SET status = ? WHERE username = ?', [$status, 'hr.demo2']);
            self::assertFalse($this->t->app->auth()->login('hr.demo2', $this->t->demoPassword, '10.0.1.1', 'ua')['ok'], $status);
        }
    }

    public function testIdleAndAbsoluteSessionTimeouts(): void
    {
        $app = $this->t->app;
        $s = $app->auth()->login('hr.demo1', $this->t->demoPassword, '10.0.0.1', 'ua')['session'];
        self::assertNotNull($app->sessions()->current($s['token']));
        $this->t->clock->advance('+29 minutes');
        self::assertNotNull($app->sessions()->current($s['token']), 'activity within 30 minutes keeps it alive');
        $this->t->clock->advance('+31 minutes');
        self::assertNull($app->sessions()->current($s['token']), 'idle > 30 minutes ends the session');
        self::assertSame('idle_timeout', $app->db()->scalar('SELECT revoked_reason FROM user_sessions WHERE id = ?', [$s['id']]));

        $s2 = $app->auth()->login('hr.demo1', $this->t->demoPassword, '10.0.0.1', 'ua')['session'];
        for ($i = 0; $i < 25; $i++) { // stay active every 29 minutes for > 12 hours
            $this->t->clock->advance('+29 minutes');
            $alive = $app->sessions()->current($s2['token']);
        }
        self::assertNull($alive ?? null, 'absolute 12-hour limit even when active');
    }

    public function testActivationLinkIsSingleUseAndExpires(): void
    {
        $app = $this->t->app;
        $admin = new \Blue\Core\Authz\Authorizer($app->db(), $this->t->clock, (int) $this->t->user(TestApp::ADMIN)['id']);
        $res = $app->iam()->invite($admin, ['username' => 'new.person', 'display_name' => 'New Person', 'email' => 'new@test.invalid']);
        self::assertSame('invited', $this->t->user('new.person')['status']);
        try {
            $app->auth()->completeLink($res['token'], 'activation', 'short', 'short');
            self::fail('policy');
        } catch (ValidationException $e) {
            self::assertSame('auth.pw.too_short', $e->errors['password']);
        }
        $app->auth()->completeLink($res['token'], 'activation', 'a long enough passphrase 1', 'a long enough passphrase 1');
        self::assertSame('active', $this->t->user('new.person')['status']);
        $this->expectException(ValidationException::class);
        $app->auth()->completeLink($res['token'], 'activation', 'another passphrase 22', 'another passphrase 22');
    }

    public function testActivationLinkExpiresAfter72Hours(): void
    {
        $app = $this->t->app;
        $admin = new \Blue\Core\Authz\Authorizer($app->db(), $this->t->clock, (int) $this->t->user(TestApp::ADMIN)['id']);
        $res = $app->iam()->invite($admin, ['username' => 'late.person', 'display_name' => 'Late']);
        $this->t->clock->advance('+73 hours');
        self::assertNull($app->auth()->userForLinkToken($res['token'], 'activation'));
    }

    public function testResetOtpLimitsAttemptsAndRevokesSessions(): void
    {
        $app = $this->t->app;
        $s = $app->auth()->login('hr.demo1', $this->t->demoPassword, '10.0.0.1', 'ua')['session'];
        $app->auth()->requestResetOtp('hr.demo1', '10.0.0.1');
        $mail = (string) $app->db()->scalar('SELECT body FROM mail_outbox ORDER BY id DESC LIMIT 1');
        preg_match('/(\d{6})/', $mail, $m);
        self::assertNotEmpty($m, 'OTP delivered to the dev mailbox');
        for ($i = 0; $i < 5; $i++) {
            try {
                $app->auth()->completeResetOtp('hr.demo1', '000000', 'brand new passphrase 9', 'brand new passphrase 9');
            } catch (ValidationException) {
            }
        }
        try {
            $app->auth()->completeResetOtp('hr.demo1', $m[1], 'brand new passphrase 9', 'brand new passphrase 9');
            self::fail('attempt limit must block even the right code');
        } catch (ValidationException $e) {
            self::assertSame('auth.error.otp_invalid', $e->errors['otp']);
        }
        $app->auth()->requestResetOtp('hr.demo1', '10.0.0.1');
        preg_match('/(\d{6})/', (string) $app->db()->scalar('SELECT body FROM mail_outbox ORDER BY id DESC LIMIT 1'), $m2);
        $app->auth()->completeResetOtp('hr.demo1', $m2[1], 'brand new passphrase 9', 'brand new passphrase 9');
        self::assertNull($app->sessions()->current($s['token']), 'password reset signs out every device');
        self::assertTrue($app->auth()->login('hr.demo1', 'brand new passphrase 9', '10.0.0.1', 'ua')['ok']);
    }

    public function testOtpExpires(): void
    {
        $app = $this->t->app;
        $app->auth()->requestResetOtp('hr.demo1', '10.0.0.1');
        preg_match('/(\d{6})/', (string) $app->db()->scalar('SELECT body FROM mail_outbox ORDER BY id DESC LIMIT 1'), $m);
        $this->t->clock->advance('+11 minutes');
        $this->expectException(ValidationException::class);
        $app->auth()->completeResetOtp('hr.demo1', $m[1], 'brand new passphrase 9', 'brand new passphrase 9');
    }

    public function testNoSecretsReachTheAuditTrail(): void
    {
        $this->t->app->auth()->login('hr.demo1', 'my-secret-wrong-pass', '10.0.0.1', 'ua');
        $this->t->app->auth()->requestResetOtp('hr.demo1', '10.0.0.1');
        $dump = json_encode($this->t->app->db()->all('SELECT * FROM audit_events'), JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertStringNotContainsString('my-secret-wrong-pass', (string) $dump);
        self::assertStringNotContainsString($this->t->demoPassword, (string) $dump);
        preg_match('/(\d{6})/', (string) $this->t->app->db()->scalar('SELECT body FROM mail_outbox ORDER BY id DESC LIMIT 1'), $m);
        self::assertStringNotContainsString('"' . $m[1] . '"', (string) $dump);
    }
}
