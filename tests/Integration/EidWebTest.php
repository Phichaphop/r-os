<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Money;
use Blue\Modules\Eid\CardService;
use Blue\Modules\Eid\WalletService;
use Blue\Tests\Support\TestApp;

/** Phase 5C (DEC-71): M13 e-ID card (status, rotating QR, verify, reissue) and the closed wallet (rules, maker–checker top-up, spend, refund, reconcile). */
final class EidWebTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function emp(string $code): array
    {
        return $this->t->app->db()->one('SELECT id, public_id FROM employees WHERE employee_code = ?', [$code]) ?? [];
    }

    private function token(string $url): string
    {
        return (string) substr($url, (int) strpos($url, '/eid/v/') + 7);
    }

    public function testCardIssueQrVerifyStatusAndReissue(): void
    {
        $svc = CardService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $e = $this->emp('D1-0004');
        $pid = $svc->issue($hr, (string) $e['public_id']);
        try {
            $svc->issue($hr, (string) $e['public_id']);
            self::fail('one current card');
        } catch (BusinessException $x) {
            self::assertSame('eid.error.has_card', $x->messageKey);
        }
        try {
            $svc->issue($this->az('hr.demo2'), (string) $e['public_id']);
            self::fail('other company');
        } catch (HttpException $x) {
            self::assertSame(404, $x->httpStatus);
        }
        $card = $svc->current((int) $e['id']);
        self::assertNotNull($card);
        self::assertSame('D1-0004-01', $card['card_no']);
        self::assertSame(22, strlen((string) $card['qr_id']));
        $face = $svc->face($card);
        self::assertSame(['D1-0004', 'active'], [$face['code'], $face['status']]);
        $qr = $svc->qr($card, 'https://blue.example');
        self::assertMatchesRegularExpression('#^https://blue\.example/eid/v/[A-Za-z0-9_-]{22}\.\d+\.[A-Za-z0-9_-]{16}$#', $qr['url']);
        self::assertStringNotContainsString('D1-0004', $qr['url'], 'no personal data in the QR');
        $tok = $this->token($qr['url']);

        $res = $svc->verify($hr, $tok);
        self::assertSame('valid', $res['result']);
        self::assertSame('D1-0004', $res['face']['code'] ?? null);
        self::assertSame('unknown', $svc->verify($hr, substr($tok, 0, -2) . 'xx')['result'], 'tampered');
        self::assertSame('not_allowed', $svc->verify($this->az('hr.demo2'), $tok)['result'], 'outside the verifier scope');
        try {
            $svc->verify($this->az('employee.demo1'), $tok);
            self::fail('needs eid.verify');
        } catch (HttpException $x) {
            self::assertSame(403, $x->httpStatus);
        }
        $this->t->clock->advance('+3 minutes');
        self::assertSame('expired_qr', $svc->verify($hr, $tok)['result'], 'a screenshot stops working');
        $tok = $this->token($svc->qr($card, 'https://blue.example')['url']);

        $svc->setStatus($hr, $pid, 'suspend', 'ตรวจสอบข้อมูล');
        self::assertSame('not_valid', $svc->verify($hr, $tok)['result']);
        try {
            $svc->setStatus($hr, $pid, 'suspend', 'อีกครั้ง');
            self::fail('already suspended');
        } catch (BusinessException $x) {
            self::assertSame('eid.error.state', $x->messageKey);
        }
        $svc->setStatus($hr, $pid, 'resume', 'ตรวจแล้ว');
        $svc->reportLost($this->az('employee.demo1'), 'โทรศัพท์หาย');
        self::assertSame('lost', $this->t->app->db()->scalar('SELECT status FROM eid_cards WHERE public_id = ?', [$pid]));
        $new = $svc->reissue($hr, $pid, 'ออกใหม่แทนบัตรหาย');
        self::assertSame('replaced', $this->t->app->db()->scalar('SELECT status FROM eid_cards WHERE public_id = ?', [$pid]));
        $card2 = $svc->current((int) $e['id']);
        self::assertSame([$new, 'D1-0004-02'], [$card2['public_id'] ?? null, $card2['card_no'] ?? null]);
        self::assertNotSame($card['qr_id'], $card2['qr_id']);
        self::assertSame('not_valid', $svc->verify($hr, $tok)['result'], 'the old card no longer works');
        self::assertSame('valid', $svc->verify($hr, $this->token($svc->qr($card2, 'https://blue.example')['url']))['result']);
        self::assertGreaterThanOrEqual(6, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM eid_verifications'));

        // leaving the company → not valid at once, revoked by the daily job
        $this->t->app->db()->run("UPDATE employees SET status = 'former' WHERE id = ?", [$e['id']]);
        self::assertSame('former', $svc->effective($card2));
        self::assertSame(1, $svc->revokeFormer());
        self::assertSame('revoked', $this->t->app->db()->scalar('SELECT status FROM eid_cards WHERE public_id = ?', [$new]));
    }

    public function testWalletRulesMakerCheckerTopUpSpendRefundAndReconcile(): void
    {
        $w = WalletService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $db = $this->t->app->db();
        $a = $this->emp('D1-0004');
        $b = $this->emp('D1-0003');
        try {
            $w->createTopup($hr, $this->le(), [['employee' => (string) $a['public_id'], 'amount' => '100']], 'สวัสดิการอาหาร');
            self::fail('no rules');
        } catch (BusinessException $x) {
            self::assertSame('eid.error.no_rules', $x->messageKey);
        }
        try {
            $w->proposeRules($hr, $this->le(), ['currency' => 'TH', 'max_topup' => '2000', 'max_balance' => '1000', 'low_balance' => '-5', 'purpose' => 'x'], '2026-11-01', 'ref', 'x');
            self::fail('invalid rules');
        } catch (ValidationException $x) {
            foreach (['currency', 'max_topup', 'low_balance', 'purpose', 'source_reference', 'reason'] as $f) {
                self::assertArrayHasKey($f, $x->errors, $f);
            }
        }
        $cv = $w->proposeRules(
            $hr,
            $this->le(),
            ['currency' => 'THB', 'max_topup' => '500', 'max_balance' => '1000', 'low_balance' => '100', 'purpose' => 'สวัสดิการค่าอาหาร (ทดสอบ)'],
            '2026-11-01',
            'ประกาศสวัสดิการ (ทดสอบ)',
            'เริ่มใช้'
        );
        $inst = (string) $db->scalar('SELECT i.public_id FROM workflow_instances i JOIN config_versions v ON v.workflow_instance_id = i.id WHERE v.public_id = ?', [$cv]);
        $this->t->app->workflow()->decide($inst, (int) $this->t->user('finance.checker')['id'], 'approve');
        self::assertSame('500.00', $w->rules($this->le())['max_topup'] ?? null);

        try {
            $w->createTopup($hr, $this->le(), [['employee' => (string) $a['public_id'], 'amount' => '600']], 'สวัสดิการอาหาร');
            self::fail('over the per-person limit');
        } catch (ValidationException $x) {
            self::assertSame('eid.error.line_amount', $x->errors['lines.0'] ?? null);
        }
        $approve = function (string $pid) use ($db): void {
            $inst = (string) $db->scalar('SELECT i.public_id FROM workflow_instances i JOIN eid_topups t ON t.workflow_instance_id = i.id WHERE t.public_id = ?', [$pid]);
            $who = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? AND k.status = 'open' LIMIT 1", [$inst]);
            self::assertSame((int) $this->t->user('finance.checker')['id'], $who, 'the finance checker decides, not the maker');
            $this->t->app->workflow()->decide($inst, $who, 'approve');
        };
        $t1 = $w->createTopup($hr, $this->le(), [['employee' => (string) $a['public_id'], 'amount' => '400'], ['employee' => (string) $b['public_id'], 'amount' => '400']], 'สวัสดิการอาหาร พ.ย.');
        self::assertSame('pending', $db->scalar('SELECT status FROM eid_topups WHERE public_id = ?', [$t1]));
        self::assertNull($w->wallet((int) $a['id']), 'no money before approval');
        $approve($t1);
        self::assertSame('approved', $db->scalar('SELECT status FROM eid_topups WHERE public_id = ?', [$t1]));
        self::assertSame(400 * Money::SCALE, Money::fromDb($w->wallet((int) $a['id'])['balance'] ?? '0'));
        $post = $db->one("SELECT * FROM fin_posting_requests WHERE source_module = 'eid.wallet' AND source_document_id = ?", [$t1]);
        self::assertNotNull($post, 'posting request to Finance Core');
        self::assertSame(800 * Money::SCALE, Money::fromDb($post['total_amount']));
        $t2 = $w->createTopup($hr, $this->le(), [['employee' => (string) $a['public_id'], 'amount' => '500']], 'สวัสดิการเพิ่มเติม');
        $approve($t2);
        try {
            $w->createTopup($hr, $this->le(), [['employee' => (string) $a['public_id'], 'amount' => '200']], 'เกินยอดสูงสุด');
            self::fail('over the balance cap');
        } catch (ValidationException $x) {
            self::assertSame('eid.error.over_balance', $x->errors['lines.0'] ?? null);
        }

        // spending needs an active card; same key = one debit; never below zero
        try {
            $w->spend((int) $a['id'], 50 * Money::SCALE, 'pos:test:0001', 'pos_sale', 'S-1', null);
            self::fail('no card yet');
        } catch (BusinessException $x) {
            self::assertSame('eid.error.card_blocked', $x->messageKey);
        }
        CardService::of($this->t->app)->issue($hr, (string) $a['public_id']);
        $s1 = $w->spend((int) $a['id'], 850 * Money::SCALE, 'pos:test:0001', 'pos_sale', 'S-1', null);
        self::assertSame($s1, $w->spend((int) $a['id'], 850 * Money::SCALE, 'pos:test:0001', 'pos_sale', 'S-1', null), 'replay returns the first entry');
        self::assertSame(50 * Money::SCALE, Money::fromDb($w->wallet((int) $a['id'])['balance'] ?? '0'));
        self::assertNotNull($db->scalar("SELECT id FROM notifications WHERE event_type = 'eid.low_balance'"), 'below 100 → alert');
        try {
            $w->spend((int) $a['id'], 60 * Money::SCALE, 'pos:test:0002', 'pos_sale', 'S-2', null);
            self::fail('insufficient');
        } catch (BusinessException $x) {
            self::assertSame('eid.error.insufficient', $x->messageKey);
        }
        $w->refund($s1, 100 * Money::SCALE, 'pos:refund:0001', null);
        try {
            $w->refund($s1, 800 * Money::SCALE, 'pos:refund:0002', null);
            self::fail('more than spent');
        } catch (ValidationException $x) {
            self::assertArrayHasKey('amount', $x->errors);
        }
        self::assertSame(150 * Money::SCALE, Money::fromDb($w->wallet((int) $a['id'])['balance'] ?? '0'));
        $card = CardService::of($this->t->app)->current((int) $a['id']);
        CardService::of($this->t->app)->setStatus($hr, (string) $card['public_id'], 'lost', 'แจ้งหาย');
        try {
            $w->spend((int) $a['id'], 10 * Money::SCALE, 'pos:test:0003', 'pos_sale', 'S-3', null);
            self::fail('lost card');
        } catch (BusinessException $x) {
            self::assertSame('eid.error.card_blocked', $x->messageKey);
        }

        $r = $w->reconcile($this->le());
        self::assertSame([2, []], [$r['wallets'], $r['mismatches']]);
        self::assertSame($r['ledger_total'], $r['balance_total']);
        $db->run('UPDATE eid_wallets SET balance = balance + 1 WHERE employee_id = ?', [$a['id']]);
        self::assertSame(1, $w->reconcileCheck(), 'a changed balance is caught');
    }

    public function testScreensRender(): void
    {
        $svc = CardService::of($this->t->app);
        $e = $this->emp('D1-0004');
        $svc->issue($this->az('hr.demo1'), (string) $e['public_id']);
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $home = $b->get('/eid');
        self::assertSame(200, $home->status);
        self::assertStringContainsString('แสดงบัตรพนักงาน', $home->body);
        self::assertStringNotContainsString('eid-card__qr', $home->body, 'the card shows only after pressing the button');
        $card = $b->get('/eid/card');
        self::assertSame(200, $card->status);
        self::assertStringContainsString('eid-card', $card->body);
        self::assertStringContainsString('<svg', $card->body);
        $qr = $b->get('/eid/card/qr');
        self::assertStringContainsString('"ok":true', $qr->body);
        self::assertSame(403, $b->get('/eid/admin')->status);
        self::assertSame(403, $b->get('/eid/verify')->status);

        $hr = $this->t->browser();
        $hr->login('hr.demo1');
        $tok = $this->token($svc->qr($svc->current((int) $e['id']) ?? [], 'http://localhost')['url']);
        foreach (['/eid/admin', '/eid/admin/e/' . $e['public_id'], '/eid/wallet/admin', '/eid/verify', '/eid/v/' . $tok] as $path) {
            $p = $hr->get($path);
            self::assertSame(200, $p->status, $path);
            self::assertDoesNotMatchRegularExpression('/\beid\.[a-z_]+\.[a-z_.]+\b(?![^<]*>)/', strip_tags($p->body), 'no raw keys on ' . $path);
        }
        self::assertStringContainsString('บัตรถูกต้อง', $hr->get('/eid/v/' . $tok)->body);
        $res = $hr->post('/eid/verify', ['code' => 'http://localhost/eid/v/' . $tok]);
        self::assertSame(303, $res->status);

        // photo upload through the form (multipart) → stored encrypted, shown to the owner, not to someone outside scope
        $cardPid = (string) $this->t->app->db()->scalar('SELECT public_id FROM eid_cards WHERE employee_id = ?', [$e['id']]);
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
        $up = $hr->post('/eid/admin/c/' . $cardPid, ['action' => 'photo'], ['photo' => ['face.png', $png]]);
        self::assertSame(303, $up->status, 'photo saved');
        self::assertNotNull($this->t->app->db()->scalar('SELECT photo_document_pid FROM eid_cards WHERE public_id = ?', [$cardPid]));
        $mine = $b->get('/eid/photo/' . $cardPid);
        self::assertSame(200, $mine->status);
        self::assertSame($png, $mine->body);
        $other = $this->t->browser();
        $other->login('hr.demo2');
        self::assertSame(404, $other->get('/eid/photo/' . $cardPid)->status);
    }
}
