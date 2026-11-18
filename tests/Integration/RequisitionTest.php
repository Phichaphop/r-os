<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Xlsx;
use Blue\Modules\Requisition\RequisitionPersonalData;
use Blue\Modules\Requisition\RequisitionService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9B-2 (DEC-93): M01 office supplies. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). Demo (examples): items PEN-BL
 * (200 per site, reorder 20), STAPLER (15, reorder 2), TAPE-18 (40), PAPER-A4 (60, reorder 10), FOLDER-A4 (30) at HQ and
 * BR1; SALES quota PAPER-A4 10 a month from 2026-11-01; rules: over quota → special approval, 4 requests a month,
 * Mon–Fri 08:00–17:00, pick up within 7 days. employee.demo1 = SALES at BR1, manager manager.demo1; ga.demo1 = WPA
 * (supply admin, issuer, special approver).
 */
final class RequisitionTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(requisition|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function svc(): RequisitionService
    {
        return RequisitionService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function site(string $code): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM sites WHERE code = ? AND legal_entity_id = ?', [$code, $this->le()]);
    }

    private function item(string $code): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM rq_items WHERE code = ? AND legal_entity_id = ?', [$code, $this->le()]);
    }

    /** @return array{on_hand: int, reserved: int} */
    private function stock(string $code, string $site = 'BR1'): array
    {
        $r = $this->t->app->db()->one(
            'SELECT s.on_hand, s.reserved FROM rq_stock s JOIN rq_items i ON i.id = s.item_id JOIN sites st ON st.id = s.site_id WHERE i.code = ? AND st.code = ? AND i.legal_entity_id = ?',
            [$code, $site, $this->le()]
        );
        return ['on_hand' => (int) ($r['on_hand'] ?? -1), 'reserved' => (int) ($r['reserved'] ?? -1)];
    }

    /** @return array<string, mixed> */
    private function rq(string $pid): array
    {
        return $this->t->app->db()->one('SELECT * FROM rq_requests WHERE public_id = ?', [$pid]) ?? [];
    }

    private function decide(string $pid, string $user, string $decision, string $comment = ''): void
    {
        $this->t->app->workflow()->decide((string) $this->rq($pid)['workflow_id'], (int) $this->t->user($user)['id'], $decision, $comment);
    }

    /**
     * Fills my cart and sends it.
     *
     * @param array<string, int> $lines code => qty
     * @return string request public id
     */
    private function send(string $user, array $lines, string $site = 'BR1'): string
    {
        $az = $this->az($user);
        foreach ($lines as $code => $q) {
            $this->svc()->addToCart($az, $this->le(), $this->item($code), (string) $q, $this->site($site));
        }
        $pid = (string) $this->svc()->cart($az, $this->le())['public_id'];
        $this->svc()->submit($az, $pid);
        return $pid;
    }

    private function emptyCart(string $user): void
    {
        $c = $this->svc()->cart($this->az($user), $this->le());
        if ($c !== null) {
            $this->svc()->updateCart($this->az($user), (string) $c['public_id'], ['qty' => array_fill_keys(array_column($c['lines'], 'item_pid'), '0')]);
        }
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

    public function testRequestReserveApproveIssueQuotaAndLimits(): void
    {
        $s = $this->svc();
        $db = $this->t->app->db();
        $emp = $this->az('employee.demo1');
        $ga = $this->az('ga.demo1');
        // catalogue: my site's stock and my department's quota left
        $items = $s->items($this->le(), (int) $db->scalar('SELECT id FROM sites WHERE public_id = ?', [$this->site('BR1')]), [], $emp->userId);
        self::assertCount(5, $items);
        $job = $s->job($emp->userId);
        $paperId = (int) $db->scalar('SELECT id FROM rq_items WHERE code = ?', ['PAPER-A4']);
        self::assertSame(['quota' => 10, 'used' => 0, 'left' => 10, 'period' => 'month'], $s->quotaLeft($job['department'], [$paperId])[$paperId]);
        // G9B2-01: send → reserved (available = on hand − reserved), number RQ2611-0001, line manager approves
        $p1 = $this->send('employee.demo1', ['PAPER-A4' => 8, 'PEN-BL' => 5]);
        $r1 = $this->rq($p1);
        self::assertSame(['pending', 'RQ2611-0001', 0, 'requisition.request'], [$r1['status'], $r1['ref'], (int) $r1['over_quota'], $r1['route']]);
        self::assertSame(['on_hand' => 60, 'reserved' => 8], $this->stock('PAPER-A4'));
        self::assertSame(['on_hand' => 60, 'reserved' => 0], $this->stock('PAPER-A4', 'HQ'), 'other site untouched');
        self::assertSame('http404', $this->refused(fn () => $s->request($this->az('hr.demo1'), $p1)), 'others cannot open it');
        $this->decide($p1, 'manager.demo1', 'approve');
        $r1 = $this->rq($p1);
        self::assertSame('approved', $r1['status']);
        self::assertMatchesRegularExpression('/^[0-9A-F]{16}$/', (string) $r1['pickup_code']);
        // issue desk: scanned link, the code or the number find it; the requester cannot issue their own
        self::assertSame($p1, $s->find($ga, 'https://x.example/requisition/issue?code=' . strtolower((string) $r1['pickup_code'])));
        self::assertSame($p1, $s->find($ga, 'RQ2611-0001'));
        self::assertNull($s->find($emp, (string) $r1['pickup_code']), 'not an issuer');
        // G9B2-02: lower a quantity → note needed; issue 6 of 8 paper + 5 pens → partial; reservation fully released
        self::assertArrayHasKey('issue_note', $this->errors(fn () => $s->issue($ga, $p1, [$this->item('PAPER-A4') => '6'], '')));
        self::assertArrayHasKey('qty_' . $this->item('PEN-BL'), $this->errors(fn () => $s->issue($ga, $p1, [$this->item('PEN-BL') => '6'], 'x')));
        self::assertSame('partial', $s->issue($ga, $p1, [$this->item('PAPER-A4') => '6'], 'กระดาษเหลือไม่พอแจก'));
        self::assertSame(['on_hand' => 54, 'reserved' => 0], $this->stock('PAPER-A4'));
        self::assertSame(['on_hand' => 195, 'reserved' => 0], $this->stock('PEN-BL'));
        self::assertSame([-6, 54], array_map('intval', array_values($db->one("SELECT l.qty, l.balance_after FROM rq_ledger l JOIN rq_items i ON i.id = l.item_id WHERE l.request_id = ? AND i.code = 'PAPER-A4'", [$r1['id']]) ?? [])));
        self::assertSame('requisition.error.already_issued', $this->refused(fn () => $s->issue($ga, $p1, [], '')), 'never twice');
        self::assertNull($this->rq($p1)['pickup_code'], 'QR used once');
        $s->confirmReceipt($emp, $p1);
        self::assertSame('closed', $this->rq($p1)['status']);
        // G9B2-03: quota counts what was issued (6); 5 more = 11 > 10 → over by 1 → special approval after the manager
        self::assertSame(4, $s->quotaLeft($job['department'], [$paperId])[$paperId]['left']);
        $p2 = $this->send('employee.demo1', ['PAPER-A4' => 5]);
        $r2 = $this->rq($p2);
        self::assertSame([1, 'requisition.special', 1], [(int) $r2['over_quota'], $r2['route'], (int) $db->scalar('SELECT over_qty FROM rq_lines WHERE request_id = ?', [$r2['id']])]);
        $this->decide($p2, 'manager.demo1', 'approve');
        self::assertSame('pending', $this->rq($p2)['status'], 'waits for the special approver');
        $this->decide($p2, 'ga.demo1', 'approve');
        self::assertSame('approved', $this->rq($p2)['status']);
        // the requester withdraws an approved request → released
        $s->cancel($emp, $p2, 'ไม่ใช้แล้ว');
        self::assertSame(['cancelled', 0], [$this->rq($p2)['status'], $this->stock('PAPER-A4')['reserved']]);
        // rule "block": over quota cannot be sent, nothing reserved (rolled back)
        $s->savePolicy($ga, $this->le(), ['over_quota' => 'block', 'max_requests' => '4', 'max_per' => 'month', 'open_days' => ['1', '2', '3', '4', '5'], 'open_from' => '08:00', 'open_to' => '17:00', 'expire_days' => '7']);
        self::assertSame('requisition.error.over_quota', $this->refused(fn () => $this->send('employee.demo1', ['PAPER-A4' => 5, 'PEN-BL' => 1])));
        self::assertSame([0, 0], [$this->stock('PAPER-A4')['reserved'], $this->stock('PEN-BL')['reserved']]);
        $this->emptyCart('employee.demo1');
        // not enough stock
        self::assertSame('requisition.error.short', $this->refused(fn () => $this->send('employee.demo1', ['STAPLER' => 16])));
        $this->emptyCart('employee.demo1');
        // G9B2-04: request limit 4 a month counts sent requests (cancelled ones do not): p1 + 2 more = 3 → one more allowed
        $p3 = $this->send('employee.demo1', ['PEN-BL' => 2]);
        $this->decide($p3, 'manager.demo1', 'reject', 'มีของในแผนกแล้ว');
        self::assertSame(['rejected', 0], [$this->rq($p3)['status'], $this->stock('PEN-BL')['reserved']]);
        $p4 = $this->send('employee.demo1', ['TAPE-18' => 2]);
        $this->decide($p4, 'manager.demo1', 'approve');
        $p5 = $this->send('employee.demo1', ['FOLDER-A4' => 1]);
        self::assertSame(['used' => 4, 'max' => 4, 'per' => 'month'], $s->limitReached($this->le(), $emp->userId));
        $this->t->app->workflow()->cancel((string) $this->rq($p5)['workflow_id'], $emp->userId, 'ทดสอบ'); // withdrawn → no longer counted
        self::assertSame('cancelled', $this->rq($p5)['status']);
        self::assertNull($s->limitReached($this->le(), $emp->userId));
        $this->send('employee.demo1', ['FOLDER-A4' => 1]);
        self::assertSame('requisition.error.limit', $this->refused(fn () => $this->send('employee.demo1', ['PEN-BL' => 1])));
        $this->emptyCart('employee.demo1');
        // returned for changes → draft, released; resubmitting reserves again on the same approval
        $s->savePolicy($ga, $this->le(), ['over_quota' => 'warn', 'open_days' => ['1', '2', '3', '4', '5'], 'open_from' => '08:00', 'open_to' => '17:00', 'expire_days' => '7']);
        $hr = $this->az('hr.demo1');
        $p6 = $this->send('hr.demo1', ['STAPLER' => 3], 'HQ');
        $wf6 = (string) $this->rq($p6)['workflow_id'];
        self::assertSame(3, $this->stock('STAPLER', 'HQ')['reserved']);
        $this->decide($p6, 'hr.head1', 'return', 'ลดเหลือ 2');
        self::assertSame(['draft', 0], [$this->rq($p6)['status'], $this->stock('STAPLER', 'HQ')['reserved']]);
        $s->updateCart($hr, $p6, ['qty' => [$this->item('STAPLER') => '2']]);
        $s->submit($hr, $p6);
        self::assertSame(['pending', $wf6, 2], [$this->rq($p6)['status'], (string) $this->rq($p6)['workflow_id'], $this->stock('STAPLER', 'HQ')['reserved']]);
        $this->decide($p6, 'hr.head1', 'approve');
        // the issuer cannot issue their own request; refusing at the desk releases
        $db->run('UPDATE rq_requests SET requester_id = ? WHERE public_id = ?', [$ga->userId, $p6]);
        self::assertSame('requisition.error.own_issue', $this->refused(fn () => $s->issue($ga, $p6, [], '')), 'maker ≠ checker at the desk');
        $db->run('UPDATE rq_requests SET requester_id = ? WHERE public_id = ?', [$hr->userId, $p6]);
        self::assertArrayHasKey('refuse_reason', $this->errors(fn () => $s->refuse($ga, $p6, '')));
        $s->refuse($ga, $p6, 'สต็อกที่ตู้ไม่ตรง');
        self::assertSame(['rejected', 0], [$this->rq($p6)['status'], $this->stock('STAPLER', 'HQ')['reserved']]);
        // G9B2-05: approved but not picked up within 7 days → expired, released (tick runs again: nothing more)
        self::assertSame('approved', $this->rq($p4)['status']);
        self::assertSame(2, $this->stock('TAPE-18')['reserved']);
        $this->t->clock->advance('+7 days');
        $this->t->clock->advance('+1 hour');
        self::assertGreaterThanOrEqual(1, $s->tick());
        self::assertSame(['expired', 0], [$this->rq($p4)['status'], $this->stock('TAPE-18')['reserved']]);
        self::assertSame(0, $s->tick());
        // G9B2-06: open days/hours — Mon 16 Nov 09:00 + 8 h = 17:00 → closed; Saturday → closed
        $this->t->clock->advance('+8 hours');
        self::assertSame('requisition.closed_hours', $s->closedReason($this->le())['key'] ?? null);
        $this->t->clock->advance('+5 days');
        self::assertSame('requisition.closed_day', $s->closedReason($this->le())['key'] ?? null);
        $s->addToCart($emp, $this->le(), $this->item('PEN-BL'), '1');
        self::assertSame('requisition.closed_day', $this->refused(fn () => $s->submit($emp, (string) $s->cart($emp, $this->le())['public_id'])), 'the cart can still be filled');
        // repeat an earlier request: its items go back to the cart
        self::assertSame(2, $s->repeat($emp, $p1));
        self::assertSame(['PAPER-A4', 'PEN-BL'], array_column($s->cart($emp, $this->le())['lines'], 'code'));
        // favourites
        self::assertTrue($s->toggleFavorite($emp, $this->item('PEN-BL')));
        self::assertCount(1, $s->items($this->le(), null, ['fav' => '1'], $emp->userId));
        self::assertFalse($s->toggleFavorite($emp, $this->item('PEN-BL')));
        // notifications: approved, waiting at the desk, issued, low stock not yet
        self::assertGreaterThanOrEqual(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'requisition.approved'", [$emp->userId]));
        self::assertGreaterThanOrEqual(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'requisition.issue'", [$ga->userId]));
        // report and export
        $rep = $s->report($ga, $this->le(), null, '2026-11');
        $bs = $rep['by_status'];
        ksort($bs);
        self::assertSame(['cancelled' => 2, 'closed' => 1, 'expired' => 1, 'pending' => 1, 'rejected' => 2], $bs);
        $paperQuota = array_values(array_filter($rep['quota'], static fn (array $q): bool => $q['item_code'] === 'PAPER-A4'))[0];
        self::assertSame([6, 4], [$paperQuota['used'], $paperQuota['left']]);
        $x = $s->export($ga, $this->le(), (int) $db->scalar('SELECT id FROM sites WHERE public_id = ?', [$this->site('BR1')]), '2026-11');
        self::assertStringStartsWith('PK', $x['bytes']);
        self::assertSame('http403', $this->refused(fn () => $s->report($emp, $this->le(), null, '2026-11')));
    }

    public function testStockItemsQuotasPrivacyAndScreens(): void
    {
        $s = $this->svc();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $emp = $this->az('employee.demo1');
        // items: unique code, unit, category; employees cannot manage
        $cat = (string) $db->scalar('SELECT public_id FROM rq_categories WHERE legal_entity_id = ? ORDER BY id LIMIT 1', [$this->le()]);
        $e = $this->errors(fn () => $s->saveItem($ga, $this->le(), ['code' => 'pen-bl', 'name' => 'x', 'unit' => '', 'category' => 'nope', 'reorder_level' => '-1']));
        self::assertSame(['code', 'name', 'unit', 'category', 'reorder_level'], array_keys($e));
        $png = UploadedFile::fromBytes('clip.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), $this->t->tmp);
        $clip = $s->saveItem($ga, $this->le(), ['code' => 'clip-01', 'name' => 'คลิปหนีบกระดาษ', 'unit' => 'กล่อง', 'category' => $cat, 'reorder_level' => '3'], $png);
        self::assertSame('CLIP-01', $s->item($clip)['code']);
        self::assertSame('image/png', $s->photo($emp, $clip)['mime']);
        self::assertSame('http403', $this->refused(fn () => $s->saveItem($emp, $this->le(), ['code' => 'X1', 'name' => 'xx', 'unit' => 'u', 'category' => $cat])));
        // stock: receive adds, a stock take sets the counted quantity (needs a note), the ledger keeps both
        self::assertArrayHasKey('qty', $this->errors(fn () => $s->adjust($ga, $this->le(), $this->site('BR1'), 'CLIP-01', 'receive', '0', '')));
        self::assertSame(10, $s->adjust($ga, $this->le(), $this->site('BR1'), 'CLIP-01', 'receive', '10', 'ซื้อเข้า'));
        self::assertArrayHasKey('note', $this->errors(fn () => $s->adjust($ga, $this->le(), $this->site('BR1'), 'CLIP-01', 'count', '7', '')));
        self::assertSame(-3, $s->adjust($ga, $this->le(), $this->site('BR1'), 'CLIP-01', 'count', '7', 'ตรวจนับ พ.ย.'));
        self::assertSame(['on_hand' => 7, 'reserved' => 0], $this->stock('CLIP-01'));
        self::assertSame([['receive', 10, 10], ['count', -3, 7]], array_map(static fn (array $r): array => [$r['kind'], (int) $r['qty'], (int) $r['balance_after']], $db->all("SELECT l.kind, l.qty, l.balance_after FROM rq_ledger l JOIN rq_items i ON i.id = l.item_id WHERE i.code = 'CLIP-01' ORDER BY l.id")));
        // low stock: available 7 → reserve 5 → 2 ≤ 3 → admins told once
        $this->send('employee.demo1', ['CLIP-01' => 5]);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'requisition.low'", [$ga->userId]));
        // Excel import: one wrong row stops everything; a good file receives every row
        $bad = Xlsx::write(['s' => ['headers' => ['code', 'qty', 'note'], 'rows' => [['PEN-BL', '10', ''], ['NOPE', '1', ''], ['TAPE-18', 'x', '']]]]);
        $res = $s->import($ga, $this->le(), $this->site('BR1'), 'receive', UploadedFile::fromBytes('in.xlsx', $bad, $this->t->tmp));
        self::assertSame(0, $res['done']);
        self::assertCount(2, $res['errors']);
        self::assertSame(200, $this->stock('PEN-BL')['on_hand']);
        $good = Xlsx::write(['s' => ['headers' => ['code', 'qty', 'note'], 'rows' => [['PEN-BL', '10', 'PO-1'], ['tape-18', '5', '']]]]);
        self::assertSame(2, $s->import($ga, $this->le(), $this->site('BR1'), 'receive', UploadedFile::fromBytes('in.xlsx', $good, $this->t->tmp))['done']);
        self::assertSame([210, 45], [$this->stock('PEN-BL')['on_hand'], $this->stock('TAPE-18')['on_hand']]);
        // quotas: overlap refused; ending one keeps it for history
        $sales = (string) $db->scalar("SELECT public_id FROM departments WHERE code = 'SALES' AND legal_entity_id = ?", [$this->le()]);
        self::assertArrayHasKey('valid_from', $this->errors(fn () => $s->saveQuota($ga, $this->le(), ['department' => $sales, 'item' => $this->item('PAPER-A4'), 'qty' => '5', 'period' => 'week', 'valid_from' => '2026-11-20'])));
        $q = $s->saveQuota($ga, $this->le(), ['department' => $sales, 'item' => $this->item('PEN-BL'), 'qty' => '10', 'period' => 'week', 'valid_from' => '2026-11-09']);
        $this->t->clock->advance('+1 day');
        $s->endQuota($ga, $q);
        self::assertSame('2026-11-09', (string) $db->scalar('SELECT valid_to FROM rq_quotas WHERE public_id = ?', [$q]));
        // policy validation
        self::assertSame(['max_per', 'open_from', 'expire_days'], array_keys($this->errors(fn () => $s->savePolicy($ga, $this->le(), ['max_requests' => '3', 'open_from' => '17:00', 'open_to' => '08:00', 'expire_days' => '0']))));
        // personal data: export, erase cancels what still holds stock and keeps issued quantities
        $exp = RequisitionPersonalData::export($this->t->app, $emp->userId, null);
        self::assertCount(1, $exp[0]['rows']);
        $er = RequisitionPersonalData::erase($this->t->app, $emp->userId, null, $ga->userId, false);
        self::assertSame(1, $er['done']['requisition.privacy.cancelled'] ?? 0);
        self::assertSame(['on_hand' => 7, 'reserved' => 0], $this->stock('CLIP-01'));
        // screens: employee pages, issuer desk, admin; employees get 403 on admin
        $b = $this->t->browser();
        $b->login('employee.demo1');
        foreach (['/requisition', '/requisition/cart', '/requisition/mine'] as $path) {
            $res = $b->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertSame(403, $b->get('/requisition/admin')->status);
        self::assertSame(403, $b->get('/requisition/issue')->status);
        $res = $b->post('/requisition/add', ['le' => (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$this->le()]), 'item' => $this->item('PEN-BL'), 'qty' => '2', 'site' => $this->site('BR1')]);
        self::assertSame(303, $res->status);
        self::assertSame(303, $b->post('/requisition/cart/submit', ['qty' => [$this->item('PEN-BL') => '2']])->status);
        $pid = (string) $db->scalar("SELECT public_id FROM rq_requests WHERE requester_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$emp->userId]);
        // 0.38.0 (10.1): a submitted request carries a QR (desk link with its number) and a Code 128 barcode of the number
        $pend = $b->get('/requisition/r/' . $pid);
        self::assertSame(200, $pend->status);
        $ref = (string) $this->rq($pid)['ref'];
        self::assertStringContainsString('aria-label="บาร์โค้ดคำขอ ' . $ref . '"', $pend->body);
        self::assertMatchesRegularExpression('#<div class="rq-barcode"><svg[^>]*>.*<text[^>]*>' . preg_quote($ref, '#') . '</text></svg></div>#s', $pend->body);
        self::assertStringContainsString('data-print', $pend->body, 'print the request slip');
        self::assertStringContainsString('รหัสคำขอ (สแกนที่จุดจ่าย)', $pend->body);
        $ga = $this->az('ga.demo1');
        self::assertSame($pid, $this->svc()->find($ga, $ref), 'barcode scanner types the number');
        self::assertSame($pid, $this->svc()->find($ga, 'https://x.example/requisition/issue?code=' . rawurlencode($ref)), 'QR of a pending request');
        $deskPending = $this->t->browser();
        $deskPending->login('ga.demo1');
        $deskPage = $deskPending->get('/requisition/issue', ['code' => $ref])->body;
        self::assertStringContainsString('id="ri-h"', $deskPage, 'desk opens the request straight from the scanned number');
        self::assertStringNotContainsString('name="qty[', $deskPage, 'not issuable until approved');
        $this->decide($pid, 'manager.demo1', 'approve');
        $page = $b->get('/requisition/r/' . $pid)->body;
        self::assertStringContainsString('<svg', $page, 'pick-up QR');
        self::assertStringContainsString('rq-barcode', $page, 'the barcode stays once approved');
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $page)));
        $g = $this->t->browser();
        $g->login('ga.demo1');
        $desk = $g->get('/requisition/issue', ['code' => (string) $this->rq($pid)['pickup_code']]);
        self::assertSame(200, $desk->status);
        self::assertStringContainsString('name="qty[' . $this->item('PEN-BL') . ']"', $desk->body);
        self::assertSame(303, $g->post('/requisition/r/' . $pid . '/issue', ['qty' => [$this->item('PEN-BL') => '2']])->status);
        self::assertSame('fulfilled', $this->rq($pid)['status']);
        foreach (['/requisition/admin', '/requisition/stock', '/requisition/report', '/requisition/issue'] as $path) {
            $res = $g->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertSame(422, $g->post('/requisition/admin/item', ['code' => '', 'name' => ''])->status);
        self::assertSame(200, $g->get('/requisition/report/export', ['month' => '2026-11'])->status);
    }
}
