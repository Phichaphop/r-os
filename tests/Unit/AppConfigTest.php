<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Config\AppConfig;
use RuntimeException;

/** AIP-02 locked keys, environment handling, debug only on dev/CI. */
final class AppConfigTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function tearDown(): void
    {
        putenv('BLUE_AI_MAX_BILLABLE_COST');
        putenv('BLUE_AI_FREE_API_ONLY');
    }

    public function testLockedAiKeysCannotBeOverriddenByEnvironmentOrOverrides(): void
    {
        putenv('BLUE_AI_MAX_BILLABLE_COST=500');
        putenv('BLUE_AI_FREE_API_ONLY=false');
        $c = AppConfig::load(self::ROOT, ['APP_ENV' => 'ci']);
        self::assertSame(0, $c->get('AI_MAX_BILLABLE_COST'));
        self::assertTrue($c->get('AI_FREE_API_ONLY'));
        self::assertNotEmpty($c->warnings(), 'an attempted override must be reported');

        $c2 = AppConfig::load(self::ROOT, ['APP_ENV' => 'ci', 'AI_MAX_BILLABLE_COST' => 99]);
        self::assertSame(0, $c2->get('AI_MAX_BILLABLE_COST'));
    }

    public function testAiIsDisabledByDefault(): void
    {
        self::assertFalse(AppConfig::load(self::ROOT, ['APP_ENV' => 'ci'])->bool('AI_ENABLED'));
    }

    public function testDebugIsNeverOnOutsideDevAndCi(): void
    {
        foreach (['prototype', 'staging', 'production'] as $env) {
            $c = AppConfig::load(self::ROOT, ['APP_ENV' => $env, 'APP_DEBUG' => true]);
            self::assertFalse($c->debug(), "debug must stay off in {$env}");
        }
        self::assertTrue(AppConfig::load(self::ROOT, ['APP_ENV' => 'ci', 'APP_DEBUG' => true])->debug());
    }

    public function testProductionDisablesShowcaseAndDemoData(): void
    {
        $c = AppConfig::load(self::ROOT, ['APP_ENV' => 'production']);
        self::assertFalse($c->bool('UI_SHOWCASE'));
        self::assertFalse($c->bool('DEMO_DATA_ALLOWED'));
        self::assertSame('cron', $c->string('SCHEDULER'));
    }

    public function testUnknownEnvironmentIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        AppConfig::load(self::ROOT, ['APP_ENV' => 'moon']);
    }

    public function testSecretKeyDetection(): void
    {
        self::assertTrue(AppConfig::isSecretKey('DB_PASS'));
        self::assertTrue(AppConfig::isSecretKey('APP_KEY'));
        self::assertFalse(AppConfig::isSecretKey('DB_HOST'));
    }
}
