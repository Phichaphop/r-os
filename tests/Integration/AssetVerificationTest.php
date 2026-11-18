<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Assets\AssetService;
use Blue\Modules\Assets\CampaignService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9C-2 (DEC-95): M42 physical verification campaigns. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). Demo assets
 * (registered 9 Nov): NB-0001 (BR1 "ฝ่ายขาย ชั้น 1", employee.demo1, 28,500), NB-0002 (BR1 "ตู้เก็บของ ฝ่ายขาย",
 * 28,500), TL-0001 (HQ, 3,200), FN-0001 (HQ "ห้องประชุมใหญ่", 15,000), FN-0002 (BR1 "ฝ่ายขาย", no cost). ga.demo1 is the
 * asset admin and counter; hr.demo1 gets the WPA role here as a second counter / reviewer; finance.checker approves
 * exceptions.
 */
final class AssetVerificationTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(assets|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
        // a second asset admin (WPA) for independent counts and reviews
        $db = $this->t->app->db();
        $ga = $db->one("SELECT a.* FROM user_role_assignments a JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = a.user_id WHERE u.username = 'ga.demo1' AND r.code = 'WPA'") ?? [];
        $hr = (int) $this->t->user('hr.demo1')['id'];
        $now = $this->t->clock->now()->format('Y-m-d H:i:s.u');
        $db->run(
            "INSERT INTO user_role_assignments (public_id, created_at, updated_at, row_version, user_id, role_id, status, valid_from, requested_by, approved_by, approved_at, reason) VALUES (?, ?, ?, 1, ?, ?, 'active', ?, ?, ?, ?, 'test')",
            [Ulid::generate($this->t->clock), $now, $now, $hr, $ga['role_id'], $ga['valid_from'], $ga['requested_by'], $ga['approved_by'], $now]
        );
        $aid = (int) $db->pdo()->lastInsertId();
        foreach ($db->all('SELECT scope_level, scope_id FROM access_scopes WHERE assignment_id = ?', [$ga['id']]) as $sc) {
            $db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [$aid, $sc['scope_level'], $sc['scope_id']]);
        }
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function svc(): CampaignService
    {
        return CampaignService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    /** @return array<string, mixed> */
    private function a(string $code): array
    {
        return $this->t->app->db()->one('SELECT * FROM as_assets WHERE code = ? AND legal_entity_id = ?', [$code, $this->le()]) ?? [];
    }

    private function site(string $code): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM sites WHERE code = ? AND legal_entity_id = ?', [$code, $this->le()]);
    }

    /** @return array<string, mixed> */
    private function item(string $camp, string $code): array
    {
        return $this->t->app->db()->one(
            'SELECT i.* FROM as_campaign_items i JOIN as_campaigns c ON c.id = i.campaign_id JOIN as_assets a ON a.id = i.asset_id WHERE c.public_id = ? AND a.code = ?',
            [$camp, $code]
        ) ?? [];
    }

    private function caseOf(string $camp, string $code): string
    {
        return (string) $this->t->app->db()->scalar(
            'SELECT x.public_id FROM as_cases x JOIN as_campaigns c ON c.id = x.campaign_id LEFT JOIN as_assets a ON a.id = x.asset_id JOIN as_campaign_items i ON i.id = x.item_id
              WHERE c.public_id = ? AND (a.code = ? OR i.unexpected_label = ?)',
            [$camp, $code, $code]
        );
    }

    /** @param array<string, mixed> $obs */
    private function scan(string $user, string $camp, string $code, array $obs): array
    {
        return $this->svc()->record($this->az($user), $camp, ['asset' => (string) $this->a($code)['public_id']] + $obs);
    }

    /**
     * @param callable(): mixed $fn
     * @return array<string, string>
     */
    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('expected a refusal');
    }

    /** @param callable(): mixed $fn */
    private function refused(callable $fn): string
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return 'http' . $e->httpStatus;
        } catch (BusinessException $e) {
            return $e->messageKey;
        }
        self::fail('expected a refusal');
    }

    public function testCampaignScopeCountCasesAndClose(): void
    {
        $s = $this->svc();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $hr = $this->az('hr.demo1');
        $assets = AssetService::of($this->t->app);
        $br1 = $this->site('BR1');
        $hq = $this->site('HQ');
        // TL-0001 lost before the count; a laptop registered after the cutoff
        $assets->setStatus($ga, (string) $this->a('TL-0001')['public_id'], 'lost', 'หายตั้งแต่เดือนก่อน');
        $this->t->clock->advance('+1 day');
        $nb = (string) $db->scalar("SELECT public_id FROM as_types WHERE code_prefix = 'NB' AND legal_entity_id = ?", [$this->le()]);
        $assets->create($ga, $this->le(), ['type' => $nb, 'name' => 'โน้ตบุ๊กใหม่', 'site' => $hq]);
        // set up: validation, then cutoff 9 Nov, second count from 20,000
        self::assertSame(['campaign_name', 'cutoff_date', 'double_cost'], array_keys($this->errors(fn () => $s->create($ga, $this->le(), ['name' => 'x', 'cutoff_date' => '2026-12-01', 'double_cost' => 'abc']))));
        self::assertSame('http403', $this->refused(fn () => $s->create($this->az('employee.demo1'), $this->le(), ['name' => 'ทดสอบ', 'cutoff_date' => '2026-11-09'])));
        $c = $s->create($ga, $this->le(), ['name' => 'ตรวจนับประจำปี 2569', 'cutoff_date' => '2026-11-09', 'double_cost' => '20,000']);
        // G9C2-01: frozen scope = registered by the cutoff, not lost/disposed → NB-0001, NB-0002, FN-0001, FN-0002
        self::assertSame(4, $s->start($ga, $c));
        self::assertSame('assets.error.campaign_state', $this->refused(fn () => $s->start($ga, $c)));
        self::assertSame([1, 1, 0, 0], [(int) $this->item($c, 'NB-0001')['double_needed'], (int) $this->item($c, 'NB-0002')['double_needed'], (int) $this->item($c, 'FN-0001')['double_needed'], (int) $this->item($c, 'FN-0002')['double_needed']]);
        // changing the register after the start does not change what the campaign expects
        $assets->update($ga, (string) $this->a('FN-0001')['public_id'], ['type' => (string) $db->scalar("SELECT public_id FROM as_types WHERE code_prefix = 'FN'"), 'code' => 'FN-0001', 'name' => 'โต๊ะประชุม 8 ที่นั่ง (DEMO)', 'acquired_on' => '2023-01-15', 'cost' => '15000.00', 'site' => $hq, 'location' => 'ห้องประชุมเล็ก']);
        self::assertSame('ห้องประชุมใหญ่', json_decode((string) $this->item($c, 'FN-0001')['expected_json'], true)['location']);
        // lookup by scanned link, code or serial
        self::assertSame('NB-0001', $s->lookup($ga, $c, 'https://x.example/assets/q/' . $this->a('NB-0001')['qr_token'])['asset']['code']);
        self::assertSame('employee.demo1', $s->lookup($ga, $c, 'nb-0001')['custodian_username']);
        self::assertSame('NB-0002', $s->lookup($ga, $c, 'DEMO-SN-1002')['asset']['code']);
        // G9C2-02: results
        self::assertSame('matched', $this->scan('ga.demo1', $c, 'NB-0001', ['site' => $br1, 'location' => 'ฝ่ายขาย ชั้น 1', 'who' => 'employee.demo1', 'condition' => 'good'])['result']);
        self::assertSame('other_location', $this->scan('ga.demo1', $c, 'NB-0002', ['site' => $hq, 'location' => 'ห้องไอที', 'condition' => 'good'])['result']);
        $again = $this->scan('ga.demo1', $c, 'NB-0002', ['site' => $hq, 'location' => 'ห้องไอที', 'condition' => 'good']);
        self::assertTrue($again['duplicate']);
        self::assertSame(2, (int) $this->item($c, 'NB-0002')['scans']);
        self::assertSame('condition_mismatch', $this->scan('ga.demo1', $c, 'FN-0001', ['site' => $hq, 'location' => 'ห้องประชุมใหญ่', 'condition' => 'poor'])['result']);
        self::assertSame('unexpected', $this->scan('ga.demo1', $c, 'NB-0003', ['site' => $hq, 'condition' => 'good'])['result']);
        self::assertSame('unexpected', $s->record($ga, $c, ['label' => 'เก้าอี้ไม่มีป้าย', 'site' => $hq, 'condition' => 'fair'])['result']);
        self::assertSame(['label', 'obs_site', 'obs_condition'], array_keys($this->errors(fn () => $s->record($ga, $c, ['site' => 'x', 'condition' => 'shiny']))));
        self::assertSame('http403', $this->refused(fn () => $s->record($this->az('employee.demo1'), $c, ['label' => 'abc', 'site' => $hq, 'condition' => 'good'])));
        // G9C2-03: items costing 20,000+ need a second, different counter before the count can end
        self::assertSame('assets.error.second_count', $this->refused(fn () => $s->finishCounting($ga, $c)));
        $sameAgain = $this->scan('ga.demo1', $c, 'NB-0001', ['site' => $br1, 'location' => 'ฝ่ายขาย ชั้น 1', 'who' => 'employee.demo1', 'condition' => 'good']);
        self::assertFalse($sameAgain['second'], 'the first counter cannot be the second');
        self::assertTrue($this->scan('hr.demo1', $c, 'NB-0001', ['site' => $br1, 'location' => 'ฝ่ายขาย ชั้น 1', 'who' => 'D1-0004', 'condition' => 'good'])['second']);
        self::assertSame('other_location', $this->scan('hr.demo1', $c, 'NB-0002', ['site' => $hq, 'location' => 'ห้องไอที', 'condition' => 'good'])['result'], 'agreeing second count keeps the difference');
        // G9C2-04: end — FN-0002 not seen = not found (not lost); 5 cases; coverage 3/4 = 75%, matched 1/3 = 33.3%
        self::assertSame(5, $s->finishCounting($ga, $c));
        self::assertSame(['not_found', 'available'], [$this->item($c, 'FN-0002')['result'], $this->a('FN-0002')['status']]);
        $view = $s->campaign($ga, $c);
        self::assertSame([4, 3, 75.0, 33.3, 2], [$view['stats']['applicable'], $view['stats']['counted'], $view['stats']['coverage'], $view['stats']['matched_pct'], $view['stats']['by']['unexpected']]);
        self::assertSame('assets.error.campaign_state', $this->refused(fn () => $this->scan('ga.demo1', $c, 'FN-0002', ['site' => $br1, 'condition' => 'good'])));
        // G9C2-05: cases — not by the counter; correct the register (before/after kept), report lost (no write-off)
        $k2 = $this->caseOf($c, 'NB-0002');
        self::assertSame('assets.error.own_count', $this->refused(fn () => $s->resolve($ga, $k2, 'master_corrected', 'ย้ายจริง')));
        self::assertSame('assets.error.own_count', $this->refused(fn () => $s->resolve($hr, $k2, 'master_corrected', 'ย้ายจริง')), 'the second counter counted it too');
        $k5 = $this->caseOf($c, 'FN-0002');
        self::assertArrayHasKey('resolution', $this->errors(fn () => $s->resolve($hr, $k5, 'master_corrected', 'xxxxx')));
        self::assertArrayHasKey('resolution_note', $this->errors(fn () => $s->resolve($hr, $k5, 'reported_lost', '')));
        $s->resolve($hr, $k5, 'reported_lost', 'ค้นทั้งสาขาแล้วไม่พบ');
        self::assertSame('lost', $this->a('FN-0002')['status']);
        $s->resolve($hr, $this->caseOf($c, 'FN-0001'), 'master_corrected', 'โต๊ะมีรอยขีดข่วนจริง');
        self::assertSame('poor', $this->a('FN-0001')['asset_condition']);
        self::assertSame(['asset_condition' => 'good'], json_decode((string) $db->scalar("SELECT before_json FROM as_cases WHERE public_id = ?", [$this->caseOf($c, 'FN-0001')]), true));
        // G9C2-06: open cases block closing; a disposal approver (not the starter) closes with an exception
        self::assertSame('assets.error.open_cases', $this->refused(fn () => $s->close($ga, $c, '')));
        self::assertArrayHasKey('exception_note', $this->errors(fn () => $s->close($this->az('finance.checker'), $c, 'สั้นไป')));
        $s->close($this->az('finance.checker'), $c, 'NB-0002 รอสอบผู้ใช้ · ของไม่มีป้ายรอฝ่ายจัดซื้อยืนยัน');
        $row = $s->row($c);
        self::assertSame('closed', $row['status']);
        self::assertSame((string) $row['report_sha256'], hash('sha256', $s->export($ga, $c)['bytes']), 'the closed pack is reproducible');
        self::assertSame(3, (int) $db->scalar("SELECT COUNT(*) FROM as_cases x JOIN as_campaigns c ON c.id = x.campaign_id WHERE c.public_id = ? AND x.status = 'open'", [$c]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM as_moves WHERE asset_id = ? AND kind = 'verification'", [$this->a('FN-0001')['id']]));
    }

    public function testNotApplicableTagCustodyAndScreens(): void
    {
        $s = $this->svc();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $hr = $this->az('hr.demo1');
        $c = $s->create($ga, $this->le(), ['name' => 'ตรวจนับสาขา BR1', 'cutoff_date' => '2026-11-09', 'site' => $this->site('BR1')]);
        self::assertSame(3, $s->start($ga, $c));
        // not applicable needs a reason; a tag damaged and a different custodian
        $nbItem = (int) $this->item($c, 'FN-0002')['id'];
        self::assertArrayHasKey('na_reason', $this->errors(fn () => $s->notApplicable($ga, $c, $nbItem, '')));
        $s->notApplicable($ga, $c, $nbItem, 'ส่งซ่อมนอกสถานที่ก่อนวันตัดยอด');
        self::assertSame('tag_damaged', $this->scan('ga.demo1', $c, 'NB-0002', ['site' => $this->site('BR1'), 'location' => 'ตู้เก็บของ ฝ่ายขาย', 'condition' => 'good', 'tag_damaged' => '1'])['result']);
        self::assertSame('custodian_mismatch', $this->scan('ga.demo1', $c, 'NB-0001', ['site' => $this->site('BR1'), 'who' => 'hr.demo1', 'condition' => 'good'])['result']);
        self::assertSame(2, $s->finishCounting($ga, $c));
        $view = $s->campaign($ga, $c);
        self::assertSame([3, 2, 100.0], [$view['stats']['scope'], $view['stats']['applicable'], $view['stats']['coverage']]);
        $oldTok = (string) $this->a('NB-0002')['qr_token'];
        $s->resolve($hr, $this->caseOf($c, 'NB-0002'), 'master_corrected', 'พิมพ์ป้ายใหม่แล้ว');
        self::assertNotSame($oldTok, (string) $this->a('NB-0002')['qr_token']);
        self::assertArrayHasKey('resolution', $this->errors(fn () => $s->resolve($hr, $this->caseOf($c, 'NB-0001'), 'master_corrected', 'xxxxxxx')));
        $s->resolve($hr, $this->caseOf($c, 'NB-0001'), 'custody_handled', 'บันทึกรับคืนและมอบใหม่แล้ว');
        $s->close($ga, $c, '');
        self::assertSame(['closed', null], [$s->row($c)['status'], $s->row($c)['exception_note']]);
        // screens
        $g = $this->t->browser();
        $g->login('ga.demo1');
        $c2 = $s->create($ga, $this->le(), ['name' => 'ตรวจนับสำนักงานใหญ่', 'cutoff_date' => '2026-11-09', 'site' => $this->site('HQ')]);
        $s->start($ga, $c2);
        foreach (['/assets/campaigns', '/assets/campaigns/' . $c, '/assets/campaigns/' . $c2, '/assets/campaigns/' . $c2 . '/count'] as $path) {
            $res = $g->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        $desk = $g->get('/assets/campaigns/' . $c2 . '/count', ['code' => 'TL-0001']);
        self::assertStringContainsString('name="asset" value="' . $this->a('TL-0001')['public_id'] . '"', $desk->body);
        self::assertSame(303, $g->post('/assets/campaigns/' . $c2 . '/count', ['asset' => (string) $this->a('TL-0001')['public_id'], 'site' => $this->site('HQ'), 'location' => 'ห้องช่าง', 'condition' => 'good'])->status);
        self::assertSame('matched', (string) $this->item($c2, 'TL-0001')['result']);
        self::assertSame(422, $g->post('/assets/campaigns/' . $c2 . '/count', ['label' => '', 'site' => '', 'condition' => 'good'])->status);
        self::assertSame(200, $g->get('/assets/campaigns/' . $c . '/export')->status);
        self::assertSame(303, $g->post('/assets/campaigns', ['name' => 'รอบใหม่', 'cutoff_date' => '2026-11-09'])->status);
        $e = $this->t->browser();
        $e->login('employee.demo1');
        self::assertSame(403, $e->get('/assets/campaigns')->status);
        self::assertSame(404, $e->get('/assets/campaigns/' . $c)->status);
    }
}
