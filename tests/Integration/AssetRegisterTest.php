<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Modules\Assets\AssetService;
use Blue\Modules\Assets\AssetsPersonalData;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9C-1 (DEC-94): M42 asset register. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). Demo (examples): types
 * "คอมพิวเตอร์โน้ตบุ๊ก" (it, NB), "เครื่องมือช่าง" (tool, TL), "เฟอร์นิเจอร์สำนักงาน" (fixed, FN); NB-0001 (BR1, 28,500,
 * held by employee.demo1, not acknowledged), NB-0002 (BR1, 28,500), TL-0001 (HQ, 3,200), FN-0001 (HQ, 15,000),
 * FN-0002 (BR1, no cost). ga.demo1 = WPA (asset admin), finance.checker = FNC (disposal approver), auditor.demo = viewer.
 */
final class AssetRegisterTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(assets|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function svc(): AssetService
    {
        return AssetService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function pid(string $code): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM as_assets WHERE code = ? AND legal_entity_id = ?', [$code, $this->le()]);
    }

    /** @return array<string, mixed> */
    private function a(string $code): array
    {
        return $this->t->app->db()->one('SELECT * FROM as_assets WHERE code = ? AND legal_entity_id = ?', [$code, $this->le()]) ?? [];
    }

    private function ref(string $table, string $col, string $code): string
    {
        return (string) $this->t->app->db()->scalar("SELECT public_id FROM {$table} WHERE {$col} = ? AND legal_entity_id = ?", [$code, $this->le()]);
    }

    private function decide(string $dpid, string $user, string $decision, string $comment = ''): void
    {
        $wf = (string) $this->t->app->db()->scalar('SELECT workflow_id FROM as_disposals WHERE public_id = ?', [$dpid]);
        $this->t->app->workflow()->decide($wf, (int) $this->t->user($user)['id'], $decision, $comment);
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

    public function testRegisterCustodyStatusDisposalAndLeavers(): void
    {
        $s = $this->svc();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $emp = $this->az('employee.demo1');
        $nb = $this->ref('as_types', 'code_prefix', 'NB');
        $hq = $this->ref('sites', 'code', 'HQ');
        $br1 = $this->ref('sites', 'code', 'BR1');
        // register: code is the permanent identifier; blank = next number of the type prefix; serial unique
        self::assertCount(5, $s->listing($ga, $this->le()));
        self::assertSame('http403', $this->refused(fn () => $s->listing($emp, $this->le())));
        $e = $this->errors(fn () => $s->create($ga, $this->le(), ['type' => $nb, 'code' => 'NB-0001', 'name' => 'x', 'serial_no' => 'DEMO-SN-1001', 'acquired_on' => '2026-12-01', 'cost' => '1.234', 'site' => '']));
        self::assertSame(['code', 'name', 'serial_no', 'acquired_on', 'cost', 'site'], array_keys($e));
        $p3 = $s->create($ga, $this->le(), ['type' => $nb, 'name' => 'โน้ตบุ๊กทดสอบ', 'serial_no' => 'SN-T3', 'acquired_on' => '2026-11-01', 'cost' => '31,900.50', 'site' => $br1, 'location' => 'ฝ่ายขาย']);
        self::assertSame(['NB-0003', '31900.50', 'available'], [$s->row($p3)['code'], $s->row($p3)['cost'], $s->row($p3)['status']]);
        self::assertSame('http403', $this->refused(fn () => $s->create($emp, $this->le(), ['type' => $nb, 'name' => 'x', 'site' => $br1])));
        // G9C1-01: moving site needs a reason; the history keeps before/after site codes
        $in = ['type' => $nb, 'code' => 'NB-0003', 'name' => 'โน้ตบุ๊กทดสอบ', 'serial_no' => 'SN-T3', 'acquired_on' => '2026-11-01', 'cost' => '31900.50', 'site' => $hq, 'location' => 'ห้องไอที'];
        self::assertArrayHasKey('reason', $this->errors(fn () => $s->update($ga, $p3, $in)));
        $s->update($ga, $p3, $in + ['reason' => 'ย้ายไปใช้ที่สำนักงานใหญ่']);
        $mv = $db->one("SELECT * FROM as_moves WHERE asset_id = ? AND kind = 'transferred'", [$s->row($p3)['id']]) ?? [];
        self::assertSame(['site' => 'BR1', 'location' => 'ฝ่ายขาย'], json_decode((string) $mv['before_json'], true));
        self::assertSame(['site' => 'HQ', 'location' => 'ห้องไอที'], json_decode((string) $mv['after_json'], true));
        // G9C1-02: custody — one custodian at a time, by user name or employee code; acknowledge in the app
        $p2 = $this->pid('NB-0002');
        self::assertArrayHasKey('who', $this->errors(fn () => $s->assign($ga, $p2, 'nobody.here', 'good', '')));
        $s->assign($ga, $p2, 'D1-0004', 'fair', 'สำหรับงานขายภาคตะวันออก');
        self::assertSame(['assigned', (int) $emp->userId, 'fair'], [$this->a('NB-0002')['status'], (int) $this->a('NB-0002')['custodian_id'], $this->a('NB-0002')['asset_condition']]);
        self::assertSame('assets.error.not_available', $this->refused(fn () => $s->assign($ga, $p2, 'hr.demo1', 'good', '')));
        self::assertSame('http404', $this->refused(fn () => $s->acknowledge($this->az('hr.demo1'), $p2)));
        $s->acknowledge($emp, $p2);
        self::assertNotNull($db->scalar('SELECT acknowledged_at FROM as_custody WHERE asset_id = ? AND returned_at IS NULL', [$this->a('NB-0002')['id']]));
        self::assertGreaterThanOrEqual(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'assets.assigned'", [$emp->userId]));
        // the custodian sees their asset (not the register's purchase data); someone else gets 404
        $view = $s->asset($emp, $p2);
        self::assertTrue($view['mine']);
        self::assertSame([], $view['moves']);
        self::assertSame('http404', $this->refused(fn () => $s->asset($this->az('hr.demo1'), $p2)));
        self::assertTrue($s->asset($this->az('auditor.demo'), $p2)['can_view']);
        self::assertSame(2, count($s->mine($emp)));
        // return "broken" → maintenance; maintenance → available needs a reason
        $s->returnAsset($ga, $p2, 'broken', 'จอแตก');
        self::assertSame(['maintenance', null, 'broken'], [$this->a('NB-0002')['status'], $this->a('NB-0002')['custodian_id'], $this->a('NB-0002')['asset_condition']]);
        self::assertArrayHasKey('status_reason', $this->errors(fn () => $s->setStatus($ga, $p2, 'available', '')));
        self::assertSame('assets.error.status', $this->refused(fn () => $s->setStatus($ga, $p2, 'disposed', 'x')));
        $s->setStatus($ga, $p2, 'available', 'เปลี่ยนจอแล้ว');
        // assigned → lost: the custody ends, the history keeps who had it
        $p1 = $this->pid('NB-0001');
        $s->setStatus($ga, $p1, 'lost', 'พนักงานแจ้งหายระหว่างเดินทาง');
        self::assertSame(['lost', null], [$this->a('NB-0001')['status'], $this->a('NB-0001')['custodian_id']]);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM as_custody WHERE asset_id = ? AND returned_at IS NOT NULL', [$this->a('NB-0001')['id']]));
        // G9C1-03: disposal — only when nobody holds it; approver = assets.dispose (finance.checker), never the requester
        $s->assign($ga, $p2, 'employee.demo1', 'good', '');
        self::assertSame('assets.error.dispose_status', $this->refused(fn () => $s->requestDisposal($ga, $p2, 'sell', 'เก่าแล้วขายทิ้ง', '')));
        self::assertSame(['method', 'disposal_reason', 'proceeds'], array_keys($this->errors(fn () => $s->requestDisposal($ga, $p1, 'burn', 'x', 'abc'))));
        $d1 = $s->requestDisposal($ga, $p1, 'write_off', 'สูญหาย ติดตามไม่พบ 30 วัน', '');
        self::assertSame('disposal_pending', $this->a('NB-0001')['status']);
        self::assertSame('assets.error.status', $this->refused(fn () => $s->setStatus($ga, $p1, 'available', 'xxx')));
        $this->decide($d1, 'finance.checker', 'approve');
        self::assertSame(['disposed', '2026-11-09'], [$this->a('NB-0001')['status'], $this->a('NB-0001')['disposed_on']]);
        self::assertSame('assets.error.disposed', $this->refused(fn () => $s->update($ga, $p1, ['type' => $nb, 'code' => 'NB-0001', 'name' => 'x', 'site' => $br1])));
        $pt = $this->pid('TL-0001');
        $d2 = $s->requestDisposal($ga, $pt, 'sell', 'ซื้อรุ่นใหม่แทน', '500');
        $this->decide($d2, 'finance.checker', 'reject', 'ยังใช้งานได้');
        self::assertSame(['available', 'rejected'], [$this->a('TL-0001')['status'], (string) $db->scalar('SELECT status FROM as_disposals WHERE public_id = ?', [$d2])]);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM as_moves WHERE asset_id = ? AND kind = 'disposal_rejected'", [$this->a('TL-0001')['id']]));
        // QR: register viewers and the custodian open it; a new QR kills the old tag
        $tok = (string) $this->a('NB-0002')['qr_token'];
        self::assertSame($p2, $s->byQr($ga, $tok));
        self::assertSame($p2, $s->byQr($emp, $tok));
        self::assertSame('http404', $this->refused(fn () => $s->byQr($this->az('hr.demo1'), $tok)));
        $s->newQr($ga, $p2);
        self::assertSame('http404', $this->refused(fn () => $s->byQr($ga, $tok)));
        // G9C1-04: leavers — employee.demo1 leaves still holding NB-0002 → admins told once
        $db->run("UPDATE employees SET status = 'former', termination_date = '2026-11-08' WHERE id = (SELECT employee_id FROM users WHERE username = 'employee.demo1')");
        self::assertSame(1, $s->tick());
        self::assertSame(0, $s->tick());
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'assets.leaver'", [$ga->userId]));
        $rep = $s->report($ga, $this->le());
        self::assertSame(['NB-0002'], array_column($rep['leavers'], 'code'));
        // G9C1-05: report — not disposed: BR1 NB-0002 28,500 + FN-0002 (no cost) · HQ NB-0003 31,900.50 + TL-0001 3,200 + FN-0001 15,000
        $sites = array_column($rep['by_site'], null, 'label');
        self::assertSame([2, '28500.00', 1], [(int) $sites['BR1']['n'], (string) $sites['BR1']['cost'], (int) $sites['BR1']['no_cost']]);
        self::assertSame([3, '50100.50'], [(int) $sites['HQ']['n'], (string) $sites['HQ']['cost']]);
        self::assertStringStartsWith('PK', $s->export($ga, $this->le())['bytes']);
    }

    public function testTypesFilesPrivacyAndScreens(): void
    {
        $s = $this->svc();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $emp = $this->az('employee.demo1');
        // types
        self::assertSame(['type_name', 'asset_class', 'code_prefix'], array_keys($this->errors(fn () => $s->saveType($ga, $this->le(), ['name' => 'x', 'asset_class' => 'stock', 'code_prefix' => '1A']))));
        $ty = $s->saveType($ga, $this->le(), ['name' => 'เครื่องจักรผลิต', 'asset_class' => 'machine', 'code_prefix' => 'mc']);
        self::assertSame('MC', (string) $db->scalar('SELECT code_prefix FROM as_types WHERE public_id = ?', [$ty]));
        self::assertSame('http403', $this->refused(fn () => $s->saveType($emp, $this->le(), ['name' => 'yy', 'asset_class' => 'it', 'code_prefix' => 'YY'])));
        $mc = $s->create($ga, $this->le(), ['type' => $ty, 'name' => 'เครื่องตัด', 'site' => $this->ref('sites', 'code', 'HQ')]);
        self::assertSame('MC-0001', $s->row($mc)['code']);
        // files through the document service (encrypted); viewers read them back, the custodian does not get the list
        $png = UploadedFile::fromBytes('invoice.png', (string) base64_decode(self::PNG), $this->t->tmp);
        self::assertArrayHasKey('file_kind', $this->errors(fn () => $s->addFile($ga, $mc, 'selfie', $png)));
        $s->addFile($ga, $mc, 'purchase', $png);
        $fid = (int) $db->scalar('SELECT id FROM as_files WHERE asset_id = ?', [$s->row($mc)['id']]);
        self::assertSame(base64_decode(self::PNG), $s->file($this->az('auditor.demo'), $mc, $fid)['bytes']);
        // personal data: custody history exported; erase keeps the records (company control of property)
        $exp = AssetsPersonalData::export($this->t->app, $emp->userId, null);
        self::assertSame(['NB-0001'], array_column($exp[0]['rows'], 'code'));
        $er = AssetsPersonalData::erase($this->t->app, $emp->userId, null, $ga->userId, false);
        self::assertSame([], $er['done']);
        self::assertSame(['assets.privacy.custody', 'assets.privacy.still_held'], array_keys($er['kept']));
        // screens
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $mine = $b->get('/assets/mine');
        self::assertSame(200, $mine->status);
        self::assertStringContainsString('NB-0001', $mine->body);
        $page = $b->get('/assets/a/' . $this->pid('NB-0001'));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('/ack"', $page->body, 'acknowledge button');
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $mine->body . $page->body)));
        self::assertSame(303, $b->post('/assets/a/' . $this->pid('NB-0001') . '/ack', [])->status);
        self::assertSame(403, $b->get('/assets')->status);
        self::assertSame(404, $b->get('/assets/a/' . $this->pid('TL-0001'))->status);
        self::assertSame(200, $b->get('/helpdesk/new', ['asset' => 'NB-0001 โน้ตบุ๊ก'])->status);
        self::assertStringContainsString('value="NB-0001 โน้ตบุ๊ก"', $b->get('/helpdesk/new', ['asset' => 'NB-0001 โน้ตบุ๊ก'])->body);
        $g = $this->t->browser();
        $g->login('ga.demo1');
        foreach (['/assets', '/assets/new', '/assets/types', '/assets/report', '/assets/labels', '/assets/a/' . $mc, '/assets/a/' . $this->pid('NB-0001')] as $path) {
            $res = $g->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertStringContainsString('<svg', $g->get('/assets/labels')->body);
        self::assertSame(422, $g->post('/assets/new', ['name' => ''])->status);
        $res = $g->post('/assets/new', ['le' => (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$this->le()]), 'type' => $ty, 'name' => 'เครื่องเจาะ', 'site' => $this->ref('sites', 'code', 'HQ')]);
        self::assertSame(303, $res->status);
        self::assertSame(303, $g->get('/assets/q/' . $this->a('MC-0002')['qr_token'])->status);
        self::assertSame(303, $g->post('/assets/a/' . $mc . '/file', ['file_kind' => 'photo'], ['file' => ['photo.png', (string) base64_decode(self::PNG)]])->status);
        self::assertSame(200, $g->get('/assets/export')->status);
    }
}
