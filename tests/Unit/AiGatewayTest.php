<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\AI\AiGateway;
use Blue\Core\AI\AiRequest;
use Blue\Core\AI\AiResponse;
use Blue\Core\AI\ProviderInterface;
use Blue\Core\Config\AppConfig;
use Blue\Core\Log\Logger;
use Blue\Core\Support\FrozenClock;
use Blue\Tests\Support\TempDir;

/** P0-07 AIP-01/02, TC-AI-00 (runtime half). */
final class AiGatewayTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::make();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    private function gateway(array $overrides = []): AiGateway
    {
        $config = AppConfig::load(__DIR__ . '/../..', ['APP_ENV' => 'ci'] + $overrides);
        return new AiGateway($config, new Logger($this->dir, new FrozenClock(), 'debug'));
    }

    public function testRollout1AlwaysAnswersDisabled(): void
    {
        $r = $this->gateway()->complete(new AiRequest('test', 'hello', 'cid'));
        self::assertFalse($r->ok);
        self::assertSame('ai.disabled', $r->messageKey);
        self::assertNull($r->text);
    }

    public function testEvenWhenEnabledOnlyTheDisabledProviderExists(): void
    {
        $g = $this->gateway(['AI_ENABLED' => true]);
        self::assertSame('disabled', $g->providerCode());
        self::assertFalse($g->complete(new AiRequest('test', 'hello', 'cid'))->ok);
    }

    public function testRegisteringARealProviderIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->gateway()->register(new class implements ProviderInterface {
            public function code(): string
            {
                return 'paid-llm';
            }

            public function isFree(): bool
            {
                return false;
            }

            public function complete(AiRequest $request): AiResponse
            {
                return new AiResponse(true, 'x', 'should never run');
            }
        });
    }
}
