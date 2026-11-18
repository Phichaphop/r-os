<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Http\Kernel;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Integrations\IntegrationAuth;
use Blue\Tests\Support\TestApp;

/** 6.30.15 integration registry: credentials shown once, rotation/revocation, api-key and HMAC checks, replay, rate limit, call log. */
final class IntegrationsTest extends TestCase
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

    private function az(string $u): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($u)['id']);
    }

    /** @param array<string, string> $headers */
    private function call(array $headers): Response
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        return (new Kernel($this->t->app))->handle(new Request('POST', '/m2m/scheduler/run', [], [], $h + ['user-agent' => 'cron'], [], '10.0.0.9'));
    }

    /** @return array<string, string> */
    private function save(string $pid, array $changes): array
    {
        $i = $this->t->app->integrations()->get($this->az(TestApp::ADMIN), $pid);
        $in = [];
        foreach (
            ['name', 'direction', 'system_type', 'auth_method', 'scopes', 'data_classification', 'schema_version', 'endpoint', 'rate_limit_per_min', 'timeout_seconds', 'retry_policy',
            'idempotency', 'monitoring', 'incident_contact', 'cost_note', 'privacy_review', 'privacy_ref', 'status', 'notes'] as $f
        ) {
            $in[$f] = (string) ($i[$f] ?? '');
        }
        $in = $changes + $in;
        $this->t->app->integrations()->save($this->az(TestApp::ADMIN), $pid, $in);
        return $in;
    }

    public function testSeededRegistryAndValidation(): void
    {
        $reg = $this->t->app->integrations();
        $codes = array_column($reg->list($this->az(TestApp::ADMIN)), 'code');
        foreach (['blue.scheduler', 'hip.time_collector', 'bank.payment_file', 'mail.provider'] as $c) {
            self::assertContains($c, $codes);
        }
        $hip = (string) $reg->byCode('hip.time_collector')['public_id'];
        try {
            $this->save($hip, ['endpoint' => 'https://collector.local/v1/punches?token=abc']);
            self::fail('secret in the endpoint accepted');
        } catch (ValidationException $e) {
            self::assertSame('integration.error.secret_in_url', $e->errors['endpoint']);
        }
        try {
            $this->save($hip, ['status' => 'active']);
            self::fail('Restricted data went live without privacy review');
        } catch (ValidationException $e) {
            self::assertSame('integration.error.privacy_first', $e->errors['status']);
        }
        try {
            $reg->list($this->az('hr.demo1'));
            self::fail('HR saw the registry');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        self::assertNotEmpty($reg->list($this->az('auditor.demo')), 'auditor reads');
    }

    public function testApiKeyCallsAreCheckedLoggedRotatedAndRevoked(): void
    {
        $reg = $this->t->app->integrations();
        $sch = (string) $reg->byCode('blue.scheduler')['public_id'];
        $key = $reg->issueCredential($this->az(TestApp::ADMIN), $sch, 30);
        self::assertStringNotContainsString($key['secret'], (string) $this->t->app->db()->scalar('SELECT CONCAT(HEX(secret_enc), last4, key_id) FROM integration_credentials'), 'secret not stored in clear');
        $hdr = ['X-Blue-Key' => $key['key_id'] . '.' . $key['secret']];
        self::assertSame(403, $this->call($hdr)->status, 'planned integration does not accept calls');
        $this->save($sch, ['status' => 'active']);
        self::assertSame(401, $this->call([])->status);
        self::assertSame(401, $this->call(['X-Blue-Key' => $key['key_id'] . '.wrong-secret-wrong-secret'])->status);
        $ok = $this->call($hdr);
        self::assertSame(200, $ok->status, $ok->body);
        self::assertArrayHasKey('ran', json_decode($ok->body, true));
        self::assertSame(200, $this->call(['Authorization' => 'Bearer ' . $key['key_id'] . '.' . $key['secret']])->status);
        $logs = $this->t->app->db()->all('SELECT status, error_code FROM integration_logs ORDER BY id');
        self::assertSame(['rejected', 'rejected', 'rejected', 'success', 'success'], array_column($logs, 'status'));
        self::assertSame('integration_not_active', $logs[0]['error_code']);
        self::assertStringNotContainsString($key['secret'], (string) json_encode($this->t->app->db()->all('SELECT * FROM integration_logs')), 'secret never logged');
        // rotation: a second key works alongside, then the old one is revoked
        $new = $reg->issueCredential($this->az(TestApp::ADMIN), $sch, null);
        $reg->revokeCredential($this->az(TestApp::ADMIN), $key['public_id'], 'rotated');
        self::assertSame(401, $this->call($hdr)->status, 'revoked key refused');
        self::assertSame(200, $this->call(['X-Blue-Key' => $new['key_id'] . '.' . $new['secret']])->status);
        // expiry
        $this->t->clock->advance('+31 days');
        $exp = $reg->issueCredential($this->az(TestApp::ADMIN), $sch, 1);
        $this->t->clock->advance('+2 days');
        self::assertSame(401, $this->call(['X-Blue-Key' => $exp['key_id'] . '.' . $exp['secret']])->status, 'expired key refused');
        // the log is append-only
        if ($this->t->app->db()->scalar("SELECT info_value FROM system_info WHERE info_key = 'finance_guard'") === 'triggers') {
            try {
                $this->t->app->db()->exec('DELETE FROM integration_logs');
                self::fail('log deleted');
            } catch (\PDOException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHmacRejectsReplayStaleTimeAndRateLimit(): void
    {
        $reg = $this->t->app->integrations();
        $sch = (string) $reg->byCode('blue.scheduler')['public_id'];
        $this->save($sch, ['status' => 'active', 'auth_method' => 'hmac', 'rate_limit_per_min' => '4']);
        $key = $reg->issueCredential($this->az(TestApp::ADMIN), $sch, 30);
        $now = $this->t->clock->now()->getTimestamp();
        $signed = IntegrationAuth::signHeaders($key['key_id'], $key['secret'], 'POST', '/m2m/scheduler/run', $now);
        self::assertSame(200, $this->call($signed)->status);
        $replay = $this->call($signed);
        self::assertSame(401, $replay->status);
        self::assertSame('replay', json_decode($replay->body, true)['error']);
        $stale = IntegrationAuth::signHeaders($key['key_id'], $key['secret'], 'POST', '/m2m/scheduler/run', $now - 900);
        self::assertSame('stale_timestamp', json_decode($this->call($stale)->body, true)['error']);
        $wrongPath = IntegrationAuth::signHeaders($key['key_id'], $key['secret'], 'POST', '/m2m/other', $now);
        self::assertSame('bad_signature', json_decode($this->call($wrongPath)->body, true)['error']);
        $limited = $this->call(IntegrationAuth::signHeaders($key['key_id'], $key['secret'], 'POST', '/m2m/scheduler/run', $now));
        self::assertSame(429, $limited->status, 'fifth call in a minute with a limit of 4');
        $this->t->clock->advance('+2 minutes');
        self::assertSame(401, (new Kernel($this->t->app))->handle(new Request('POST', '/m2m/scheduler/run', [], ['_csrf' => 'x'], [], [], '10.0.0.9'))->status, 'no session/CSRF path into the endpoint');
    }
}
