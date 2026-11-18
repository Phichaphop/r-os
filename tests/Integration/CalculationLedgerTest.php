<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use DateTimeImmutable;
use PDOException;
use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Tests\Support\TestApp;

/**
 * TC-CALC (6.26.3 items 2 and 5): an old run shows the configuration version and inputs it really used even after
 * the configuration changed; replay reproduces it without side effects; recalculation adds a run instead of
 * rewriting; the same calculation twice (idempotency key) creates one run.
 */
final class CalculationLedgerTest extends TestCase
{
    private const KEY = 'demo.meal_allowance_per_day';

    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function approve(string $value, string $from): string
    {
        $reg = $this->t->app->registry();
        $admin = new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user(TestApp::ADMIN)['id']);
        $org = (int) $this->t->app->db()->scalar('SELECT id FROM organizations LIMIT 1');
        $pid = $reg->propose($admin, self::KEY, 'organization', $org, $value, new DateTimeImmutable($from), null, 'test');
        $this->t->app->workflow()->decide($reg->submit($admin, $pid), (int) $this->t->user('sysadmin2.demo')['id'], 'approve');
        return $pid;
    }

    /** A tiny, deterministic sample formula: days × allowance (strings in, string out — no floats). */
    public static function formula(array $inputs, array $config): array
    {
        $cents = (int) round((float) $config[self::KEY] * 100) * (int) $inputs['days'];
        return ['amount' => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100)];
    }

    private function calculate(string $source, ?string $supersedes = null, ?string $idem = null): string
    {
        $cfg = $this->t->app->registry()->resolve(self::KEY);
        $inputs = ['days' => 3, 'employee' => 'EMP-0001'];
        return $this->t->app->ledger()->record(
            'demo.allowance',
            'demo-1.0',
            'DEMO_ALLOWANCE_V1',
            $this->t->clock->now(),
            [$cfg],
            $inputs,
            ['rate' => $cfg->value],
            self::formula($inputs, [self::KEY => $cfg->value]),
            ['source_type' => 'demo_record', 'source_id' => $source, 'source_revision' => 1, 'supersedes' => $supersedes, 'idempotency_key' => $idem]
        );
    }

    public function testOldRunsShowTheVersionTheyReallyUsed(): void
    {
        $v1 = $this->approve('100', '2026-11-01T00:00:00Z');
        $run = $this->calculate('R1');
        $this->t->clock->advance('+1 day');
        $this->approve('130', '2026-11-10T00:00:00Z');

        $open = $this->t->app->ledger()->open($run);
        self::assertTrue($open['intact']);
        self::assertSame($v1, $open['config_versions'][0]['version_id']);
        self::assertSame('100.00', $open['config_versions'][0]['resolved_value']);
        self::assertSame(['amount' => '300.00'], $open['outputs']);
        self::assertSame(3, $open['inputs']['days']);
        // replay uses the snapshot, not today's value (130) — and writes nothing
        $before = (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM calculation_runs');
        $replay = $this->t->app->ledger()->replay($run, [self::class, 'formula']);
        self::assertTrue($replay['matches']);
        self::assertSame($before, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM calculation_runs'));
        // snapshots are encrypted at rest
        $raw = (string) $this->t->app->db()->scalar('SELECT payload_enc FROM calculation_snapshots WHERE kind = \'inputs\' LIMIT 1');
        self::assertStringNotContainsString('EMP-0001', $raw);
    }

    public function testRecalculationAddsARunAndTheSameCalculationIsRecordedOnce(): void
    {
        $this->approve('100', '2026-11-01T00:00:00Z');
        $a = $this->calculate('R2', null, 'demo:R2:rev1');
        $b = $this->calculate('R2', null, 'demo:R2:rev1');
        self::assertSame($a, $b, 'double submit / retry → one run');
        $this->t->app->ledger()->transition($a, 'approved');
        $this->t->app->ledger()->transition($a, 'posted');
        $this->t->clock->advance('+1 day');
        $this->approve('110', '2026-11-10T00:00:00Z');
        $c = $this->calculate('R2', $a, 'demo:R2:rev2');
        $history = $this->t->app->ledger()->history('demo_record', 'R2');
        self::assertCount(2, $history);
        self::assertSame([$c, $a], array_column($history, 'public_id'));
        self::assertSame($a, $history[0]['supersedes']);
        self::assertSame('posted', $history[1]['status'], 'a posted run is corrected in its domain, never rewritten');
        self::assertSame(['amount' => '330.00'], $this->t->app->ledger()->open($c)['outputs']);
    }

    public function testSnapshotsCannotBeChangedAfterwards(): void
    {
        if ($this->t->app->db()->scalar("SELECT info_value FROM system_info WHERE info_key = 'history_guard'") !== 'triggers') {
            self::markTestSkipped('database refuses triggers here (code_only guard)');
        }
        $this->approve('100', '2026-11-01T00:00:00Z');
        $this->calculate('R3');
        $this->expectException(PDOException::class);
        $this->t->app->db()->run("UPDATE calculation_snapshots SET payload_sha256 = 'x'");
    }
}
