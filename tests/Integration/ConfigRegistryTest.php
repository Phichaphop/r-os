<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Registry\ConfigMissing;
use Blue\Tests\Support\TestApp;

/** TC-CFG (6.25, 6.26.3 items 1, 3, 4): effective dates, scope inheritance, overlap/gap, maker-checker, immutability. */
final class ConfigRegistryTest extends TestCase
{
    private const KEY = 'demo.meal_allowance_per_day'; // high risk, required, DEMO definition

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

    private function org(): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM organizations LIMIT 1');
    }

    private function le(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    /** Proposes as admin.test, approves as sysadmin2.demo through the central workflow. */
    private function approved(string $level, int $scopeId, string $value, string $from, ?string $to = null): string
    {
        $reg = $this->t->app->registry();
        $pid = $reg->propose($this->az(TestApp::ADMIN), self::KEY, $level, $scopeId, $value, new DateTimeImmutable($from), $to !== null ? new DateTimeImmutable($to) : null, 'test');
        $inst = $reg->submit($this->az(TestApp::ADMIN), $pid);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user('sysadmin2.demo')['id'], 'approve');
        return $pid;
    }

    public function testFutureDatedVersionAppliesFromItsStartAndOverridesInheritByScope(): void
    {
        $reg = $this->t->app->registry();
        $v1 = $this->approved('organization', $this->org(), '100', '2026-11-01T00:00:00Z');
        $v2 = $this->approved('organization', $this->org(), '120.5', '2026-12-01T00:00:00Z');
        $now = $reg->resolve(self::KEY);
        self::assertSame('100.00', $now->value);
        self::assertSame($v1, $now->versionPublicId);
        $later = $reg->resolve(self::KEY, null, null, new DateTimeImmutable('2026-12-05T00:00:00Z'));
        self::assertSame('120.50', $later->value);
        self::assertSame($v2, $later->versionPublicId);
        self::assertSame('scheduled', array_values(array_filter($reg->versions(self::KEY), static fn ($v) => $v['public_id'] === $v2))[0]['display_status']);

        // company override: DEMO1 gets its own value, DEMO2 still inherits from the group
        $this->approved('legal_entity', $this->le('DEMO1'), '150', '2026-11-05T00:00:00Z');
        $r1 = $reg->resolve(self::KEY, $this->le('DEMO1'));
        self::assertSame(['150.00', 'legal_entity'], [$r1->value, $r1->sourceScope]);
        $r2 = $reg->resolve(self::KEY, $this->le('DEMO2'));
        self::assertSame(['100.00', 'organization'], [$r2->value, $r2->sourceScope]);
        self::assertSame(['legal_entity:' . $this->le('DEMO2'), 'organization:' . $this->org()], $r2->path);
        // a site of DEMO1 inherits the company value
        $site = (int) $this->t->app->db()->scalar('SELECT id FROM sites WHERE legal_entity_id = ? AND code = ?', [$this->le('DEMO1'), 'BR1']);
        self::assertSame('legal_entity', $reg->resolve(self::KEY, null, $site)->sourceScope);
    }

    public function testOverlapIsRefusedAndAGapStopsTheCalculation(): void
    {
        $reg = $this->t->app->registry();
        $admin = $this->az(TestApp::ADMIN);
        $this->approved('organization', $this->org(), '100', '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z');

        $overlap = $reg->propose($admin, self::KEY, 'organization', $this->org(), '90', new DateTimeImmutable('2026-11-15T00:00:00Z'), null, 'x');
        try {
            $reg->submit($admin, $overlap);
            self::fail('overlap accepted');
        } catch (BusinessException $e) {
            self::assertSame('config.error.overlap', $e->messageKey);
        }
        $sameStart = $reg->propose($admin, self::KEY, 'organization', $this->org(), '90', new DateTimeImmutable('2026-11-01T00:00:00Z'), null, 'x');
        $this->expectExceptionCode(409);
        try {
            $reg->submit($admin, $sameStart);
        } finally {
            // after the bounded window ends there is no value: a required key must stop the calculation, loudly
            try {
                $reg->resolve(self::KEY, null, null, new DateTimeImmutable('2026-12-02T00:00:00Z'));
                self::fail('gap resolved silently');
            } catch (ConfigMissing $e) {
                self::assertSame(self::KEY, $e->key);
            }
            self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'config.resolve_failed'"));
        }
    }

    public function testProposerCannotApproveTheirOwnHighRiskValue(): void
    {
        $reg = $this->t->app->registry();
        $admin = $this->az(TestApp::ADMIN);
        $pid = $reg->propose($admin, self::KEY, 'organization', $this->org(), '100', new DateTimeImmutable('2026-11-10T00:00:00Z'), null, 'x');
        $inst = $reg->submit($admin, $pid);
        try {
            $this->t->app->workflow()->decide($inst, $admin->userId, 'approve');
            self::fail('self approval accepted');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        try {
            $reg->applyNow($admin, self::KEY, '100', 'shortcut');
            self::fail('high risk applied without checker');
        } catch (BusinessException $e) {
            self::assertSame('config.error.needs_checker', $e->messageKey);
        }
        self::assertSame('in_review', $reg->version($pid)['status']);
        self::assertNull($reg->resolveOrNull(self::KEY, null, null, new DateTimeImmutable('2026-11-20T00:00:00Z')));
    }

    public function testDecidedVersionsAreNeverEditedOnlyFutureOnesCancelled(): void
    {
        $reg = $this->t->app->registry();
        $admin = $this->az(TestApp::ADMIN);
        $active = $this->approved('organization', $this->org(), '100', '2026-11-01T00:00:00Z');
        $future = $this->approved('organization', $this->org(), '110', '2027-01-01T00:00:00Z');
        try {
            $reg->cancel($admin, $active, 'oops');
            self::fail('active version cancelled');
        } catch (BusinessException $e) {
            self::assertSame('config.error.cannot_cancel', $e->messageKey);
        }
        $reg->cancel($admin, $future, 'plan changed');
        $statuses = array_column($reg->versions(self::KEY), 'display_status', 'public_id');
        self::assertSame('active', $statuses[$active]);
        self::assertSame('cancelled', $statuses[$future]);
        self::assertSame('"100.00"', $reg->version($active)['value_json']);
        // a newer start supersedes the older open-ended version
        $this->t->clock->advance('+1 day');
        $newer = $this->approved('organization', $this->org(), '105', '2026-11-09T12:00:00Z');
        $statuses = array_column($reg->versions(self::KEY), 'display_status', 'public_id');
        self::assertSame(['superseded', 'active'], [$statuses[$active], $statuses[$newer]]);
    }

    public function testValuesAreValidatedAgainstTheDefinition(): void
    {
        $reg = $this->t->app->registry();
        $admin = $this->az(TestApp::ADMIN);
        $bad = static function (callable $fn): array {
            try {
                $fn();
            } catch (ValidationException $e) {
                return $e->errors;
            }
            return [];
        };
        $from = new DateTimeImmutable('2026-11-10T00:00:00Z');
        self::assertSame('config.error.precision', $bad(fn () => $reg->propose($admin, self::KEY, 'organization', $this->org(), '1.234', $from, null, 'x'))['value'] ?? null);
        self::assertSame('config.error.decimal', $bad(fn () => $reg->propose($admin, self::KEY, 'organization', $this->org(), '1e3', $from, null, 'x'))['value'] ?? null);
        self::assertSame('config.error.max', $bad(fn () => $reg->propose($admin, self::KEY, 'organization', $this->org(), '100001', $from, null, 'x'))['value'] ?? null);
        self::assertSame('config.error.enum', $bad(fn () => $reg->propose($admin, 'theme.preset', 'organization', $this->org(), 'purple', $from, null, 'x'))['value'] ?? null);
        self::assertSame('config.error.color', $bad(fn () => $reg->propose($admin, 'theme.primary_color', 'organization', $this->org(), 'red', $from, null, 'x'))['value'] ?? null);
        self::assertSame('config.error.scope_not_allowed', $bad(fn () => $reg->propose($admin, 'theme.preset', 'legal_entity', $this->le('DEMO1'), 'teal', $from, null, 'x'))['scope'] ?? null);
        self::assertSame('config.error.dates', $bad(fn () => $reg->propose($admin, self::KEY, 'organization', $this->org(), '1', $from, $from, 'x'))['effective_to'] ?? null);
        // statutory values need a source reference (6.25.1 — never assume a legal rate)
        $this->t->app->db()->run("UPDATE config_definitions SET source_type = 'LAW' WHERE config_key = ?", [self::KEY]);
        self::assertSame('config.error.source_required', $bad(fn () => $reg->propose($admin, self::KEY, 'organization', $this->org(), '1', $from, null, 'x'))['source_reference'] ?? null);
    }

    public function testOnlyPeopleWithTheRightAndScopeMayPropose(): void
    {
        $reg = $this->t->app->registry();
        $this->expectException(HttpException::class);
        $reg->propose($this->az('hr.demo1'), self::KEY, 'legal_entity', $this->le('DEMO1'), '1', new DateTimeImmutable('2026-11-10T00:00:00Z'), null, 'x');
    }

    public function testImpactShowsRetroactiveDatesAndWhatInherits(): void
    {
        $reg = $this->t->app->registry();
        $pid = $reg->propose($this->az(TestApp::ADMIN), self::KEY, 'organization', $this->org(), '100', new DateTimeImmutable('2026-10-01T00:00:00Z'), null, 'x');
        $impact = $reg->impact($reg->version($pid));
        self::assertTrue($impact['retroactive']);
        self::assertContains('config.impact.retroactive', $impact['warnings']);
        self::assertSame(2 + 3, $impact['inheriting']); // 2 demo companies + 3 sites
    }
}
