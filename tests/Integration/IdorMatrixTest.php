<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Tests\Support\TestApp;

/**
 * IDOR / cross-company matrix through the real HTTP pipeline (phase-2 gate, P0-06 §9; P0-04 T-02).
 * Every actor × object × channel (list, detail, edit form, update) must give exactly the expected result.
 * Out-of-scope objects answer 404 — identical to a non-existent ID — and the attempt is audited.
 */
final class IdorMatrixTest extends TestCase
{
    private static ?TestApp $t = null;

    public static function tearDownAfterClass(): void
    {
        self::$t?->cleanup();
        self::$t = null;
    }

    private function t(): TestApp
    {
        return self::$t ??= TestApp::create($this);
    }

    /** @return array<string, array{0:string, 1:string, 2:string, 3:int, 4:int}> actor, kind, object, expected GET, expected POST */
    public static function matrix(): array
    {
        // object keys: LE1 / LE2 companies, S2 = DEMO2 head office, D1 = DEMO1 HR department, SB1 = DEMO1 branch 1
        return [
            'HR of DEMO1 cannot open DEMO2 company' => ['hr.demo1', 'legal-entity', 'LE2', 404, 404],
            'HR of DEMO1 cannot open DEMO2 site' => ['hr.demo1', 'site', 'S2', 404, 404],
            'HR of DEMO1 cannot edit own company (view-only role)' => ['hr.demo1', 'legal-entity', 'LE1', 404, 404],
            'HR of DEMO2 cannot open DEMO1 department' => ['hr.demo2', 'department', 'D1', 404, 404],
            'Cashier (site scope) cannot open company' => ['cashier.br1', 'legal-entity', 'LE1', 404, 404],
            'Employee cannot open other company' => ['employee.demo1', 'site', 'S2', 404, 404],
            'Auditor (read-only) cannot edit' => ['auditor.demo', 'site', 'S2', 404, 404],
            'System admin can edit any company' => [TestApp::ADMIN, 'site', 'S2', 200, 303],
        ];
    }

    private function objectId(string $key): string
    {
        $t = $this->t();
        return match ($key) {
            'LE1' => $t->id('legal_entities', 'DEMO1'),
            'LE2' => $t->id('legal_entities', 'DEMO2'),
            'S2' => $t->id('sites', 'HQ', 'DEMO2'),
            'SB1' => $t->id('sites', 'BR1', 'DEMO1'),
            'D1' => $t->id('departments', 'HR', 'DEMO1'),
            default => '',
        };
    }

    /** @dataProvider matrix */
    public function testDetailAndUpdateChannels(string $actor, string $kind, string $object, int $get, int $post): void
    {
        $b = $this->t()->browser();
        $b->login($actor);
        $id = $this->objectId($object);
        self::assertSame($get, $b->get("/admin/org/{$kind}/{$id}")->status, "GET {$kind} {$object} as {$actor}");
        $res = $b->post("/admin/org/{$kind}/{$id}", ['row_version' => 1, 'name_th' => 'ถูกแก้โดย ' . $actor, 'code' => 'X', 'site_type' => 'hq']);
        self::assertSame($post, $res->status, "POST {$kind} {$object} as {$actor}");
        if ($post === 404) {
            self::assertStringNotContainsString('ถูกแก้โดย', (string) $this->t()->app->db()->scalar("SELECT GROUP_CONCAT(value) FROM translations WHERE value LIKE 'ถูกแก้โดย%'"));
        }
    }

    public function testListChannelShowsOnlyOwnCompany(): void
    {
        $b = $this->t()->browser();
        $b->login('hr.demo1');
        $html = $b->get('/admin/org')->body;
        self::assertStringContainsString('DEMO1', $html);
        self::assertStringNotContainsString('DEMO2', $html);
        self::assertStringNotContainsString('ฝ่ายปฏิบัติการ', $html, 'DEMO2 department name must not leak');
    }

    public function testSiteScopedUserSeesOnlyTheirSite(): void
    {
        $b = $this->t()->browser();
        $b->login('cashier.br1');
        self::assertSame(403, $b->get('/admin/org')->status, 'CSH has no core.org.view at all');
    }

    public function testCrossCompanyIdsAreNotEnumerableAndAttemptsAreAudited(): void
    {
        $b = $this->t()->browser();
        $b->login('hr.demo1');
        $existingOther = $b->get('/admin/org/site/' . $this->objectId('S2'));
        $missing = $b->get('/admin/org/site/01ZZZZZZZZZZZZZZZZZZZZZZZZ');
        self::assertSame($existingOther->status, $missing->status);
        self::assertGreaterThan(0, (int) $this->t()->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'authz.out_of_scope' AND outcome = 'denied'"));
    }

    public function testAdministrationPagesRequireThePermission(): void
    {
        $b = $this->t()->browser();
        $b->login('hr.demo1');
        foreach (['/admin/users', '/admin/roles', '/admin/audit', '/admin/audit/export', '/system/status', '/system/ui-showcase', '/admin/org/legal-entity/new'] as $path) {
            self::assertSame(403, $b->get($path)->status, $path);
        }
        self::assertSame(403, $b->post('/admin/users/new', ['username' => 'evil', 'display_name' => 'Evil'])->status);
    }

    public function testGuestsAreSentToLogin(): void
    {
        $b = $this->t()->browser();
        self::assertSame(200, $b->get('/')->status, '0.8.1 (DEC-68): home is a public launcher');
        foreach (['/admin/users', '/admin/org', '/me', '/system/ui-showcase', '/calc', '/pdf'] as $path) {
            $res = $b->get($path);
            self::assertSame(302, $res->status, $path);
            self::assertStringStartsWith('/login', (string) $res->headers['Location']);
        }
        self::assertSame(401, $b->kernel->handle(new \Blue\Core\Http\Request('GET', '/api/anything', [], [], ['accept' => 'application/json']))->status);
    }

    public function testMfaGateBlocksEverythingUntilVerified(): void
    {
        $t = $this->t();
        $b = $t->browser();
        $res = $b->post('/login', ['identifier' => 'payroll.checker', 'password' => $t->demoPassword, 'next' => '/']);
        self::assertSame(303, $res->status);
        foreach (['/', '/me', '/admin/org'] as $path) {
            self::assertStringEndsWith('/mfa/setup', (string) $b->get($path)->headers['Location'], $path);
        }
    }

    public function testReauthIsRequiredForAuditExport(): void
    {
        $b = $this->t()->browser();
        $b->login('auditor.demo');
        $res = $b->get('/admin/audit/export');
        self::assertSame(302, $res->status);
        self::assertStringStartsWith('/reauth?next=', (string) $res->headers['Location']);
    }
}
