<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use Blue\Modules\Privacy\PrivacyCenterService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;
use PHPUnit\Framework\TestCase;

/**
 * 0.42.0 (R6-1): privacy centre — notices per audience (AI draft with [[…]] gaps → submit → another officer publishes after
 * a legal check → public page → employees acknowledge), record of processing activities (drafts from modules, activation
 * by another officer with a legal basis and retention), breach log (steps, decision by an approver, no time limit unless
 * the company sets one).
 */
final class PrivacyCenterWebTest extends TestCase
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

    private function as(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private function le(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    public function testNoticesFromAiDraftToPublishedAcknowledgedAndPublic(): void
    {
        $db = $this->t->app->db();
        $le = $this->le('DEMO1');
        $emp = $this->as('employee.demo1');
        self::assertSame(403, $emp->get('/privacy/center')->status, 'employees do not manage privacy');
        self::assertSame(200, $emp->get('/privacy/notices')->status);

        $p1 = $this->as('dpo.demo1');
        $p1->get('/privacy/center?le=' . $le);
        $res = $p1->post('/privacy/center/notices', ['le' => (string) $le, 'audience' => 'employee', 'template' => '1']);
        self::assertSame(303, $res->status);
        $n = $db->one("SELECT * FROM pv_notices WHERE legal_entity_id = ? AND audience = 'employee'", [$le]);
        self::assertNotNull($n);
        self::assertSame(1, (int) $n['ai_draft'], 'an AI draft is flagged');
        self::assertSame(1, (int) $n['requires_ack']);
        self::assertStringContainsString('[[', (string) $n['body'], 'legal choices are left as gaps');
        $pid = (string) $n['public_id'];
        $page = $p1->get('/privacy/center/n/' . $pid)->body;
        self::assertStringContainsString('ร่างโดย AI — ต้องตรวจก่อนใช้', $page);
        self::assertSame(303, $p1->post('/privacy/center/notices', ['le' => (string) $le, 'audience' => 'employee', 'template' => '1'])->status);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM pv_notices WHERE legal_entity_id = ? AND audience = 'employee'", [$le]), 'one draft per audience');

        $p1->get('/privacy/center/n/' . $pid);
        self::assertSame(422, $p1->post('/privacy/center/n/' . $pid . '/submit')->status, 'gaps block submitting');
        $body = (string) preg_replace('/\[\[[^\]]*\]\]/u', 'ข้อความที่บริษัทกรอกแล้ว (ทดสอบ)', (string) $n['body']);
        $p1->get('/privacy/center/n/' . $pid);
        self::assertSame(303, $p1->post('/privacy/center/n/' . $pid . '/save', ['title' => 'ประกาศความเป็นส่วนตัวสำหรับพนักงาน', 'body' => $body, 'requires_ack' => '1'])->status);
        $p1->get('/privacy/center/n/' . $pid);
        self::assertSame(303, $p1->post('/privacy/center/n/' . $pid . '/submit')->status);
        self::assertSame('pending', $db->scalar('SELECT status FROM pv_notices WHERE public_id = ?', [$pid]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'privacy.review' AND user_id = ?", [(int) $this->t->user('dpo.demo2')['id']]));

        // the same officer cannot publish; another one must confirm the legal check
        $p1->get('/privacy/center/n/' . $pid);
        $p1->post('/privacy/center/n/' . $pid . '/publish', ['legal_checked' => '1', 'note' => 'ตรวจโดยที่ปรึกษา 2026-11-18', 'effective_from' => $this->today()]);
        self::assertSame('pending', $db->scalar('SELECT status FROM pv_notices WHERE public_id = ?', [$pid]), 'maker ≠ checker');
        $p2 = $this->as('dpo.demo2');
        $p2->get('/privacy/center/n/' . $pid);
        self::assertSame(422, $p2->post('/privacy/center/n/' . $pid . '/publish', ['note' => 'ตรวจแล้ว', 'effective_from' => $this->today()])->status, 'legal check must be confirmed');
        $p2->get('/privacy/center/n/' . $pid);
        self::assertSame(303, $p2->post('/privacy/center/n/' . $pid . '/publish', ['legal_checked' => '1', 'note' => 'ตรวจโดยที่ปรึกษา 2026-11-18', 'effective_from' => $this->today()])->status);
        $pub = $db->one('SELECT * FROM pv_notices WHERE public_id = ?', [$pid]);
        self::assertSame('published', $pub['status']);
        self::assertSame(64, strlen((string) $pub['sha256']));
        self::assertGreaterThan(0, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'privacy.ack'"), 'employees are asked to acknowledge');

        // public page (no sign-in)
        $g = $this->t->browser();
        $public = $g->get('/privacy-notice/DEMO1/employee');
        self::assertSame(200, $public->status);
        self::assertStringContainsString('ข้อความที่บริษัทกรอกแล้ว', $public->body);
        self::assertStringNotContainsString('ร่างโดย AI', $public->body);
        self::assertSame(404, $g->get('/privacy-notice/DEMO1/visitor')->status, 'nothing published for visitors yet');
        self::assertSame(404, $g->get('/privacy-notice/DEMO1/nobody')->status);

        // employee acknowledges; another company's employee cannot open it
        $emp->get('/privacy/notices');
        self::assertStringContainsString('รอรับทราบ', $emp->get('/privacy/notices')->body);
        $emp->get('/privacy/notices/' . $pid);
        self::assertSame(422, $emp->post('/privacy/notices/' . $pid . '/ack')->status);
        $emp->get('/privacy/notices/' . $pid);
        self::assertSame(303, $emp->post('/privacy/notices/' . $pid . '/ack', ['confirm' => '1'])->status);
        $ack = $db->one('SELECT * FROM pv_acks WHERE notice_id = ?', [$pub['id']]);
        self::assertSame($pub['sha256'], $ack['sha256'], 'acknowledged the exact text');
        $other = $this->as('hr.demo2');
        self::assertSame(404, $other->get('/privacy/notices/' . $pid)->status);
        self::assertStringContainsString('รับทราบแล้ว 1 จาก', $p2->get('/privacy/center/n/' . $pid)->body);

        // a new version copies the text; the published one stays until the new one is published
        $p1->get('/privacy/center?le=' . $le);
        $p1->post('/privacy/center/notices', ['le' => (string) $le, 'audience' => 'employee']);
        $v2 = $db->one("SELECT * FROM pv_notices WHERE legal_entity_id = ? AND audience = 'employee' AND version = 2", [$le]);
        self::assertSame('draft', $v2['status']);
        self::assertSame($body, $v2['body']);
        self::assertSame(200, $g->get('/privacy-notice/DEMO1/employee')->status);
        // withdraw needs a reason
        $p2->get('/privacy/center/n/' . $pid);
        self::assertSame(422, $p2->post('/privacy/center/n/' . $pid . '/withdraw', ['reason' => ''])->status);
        $p2->get('/privacy/center/n/' . $pid);
        $p2->post('/privacy/center/n/' . $pid . '/withdraw', ['reason' => 'ทดสอบการถอน']);
        self::assertSame(404, $g->get('/privacy-notice/DEMO1/employee')->status);
        self::assertNull(PrivacyCenterService::publicUrl($this->t->app, $le, 'employee'), 'withdrawn = no public link');
    }

    public function testProcessingActivitiesAndBreachLog(): void
    {
        $db = $this->t->app->db();
        $le = $this->le('DEMO1');
        $p1 = $this->as('dpo.demo1');
        $p1->get('/privacy/center?tab=activities&le=' . $le);
        self::assertSame(303, $p1->post('/privacy/center/activities/seed', ['le' => (string) $le])->status);
        $n = (int) $db->scalar('SELECT COUNT(*) FROM pv_activities WHERE legal_entity_id = ?', [$le]);
        self::assertGreaterThan(5, $n);
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM pv_activities WHERE legal_entity_id = ? AND (legal_basis IS NOT NULL OR retention IS NOT NULL OR ai_draft = 0)', [$le]), 'AI drafts leave basis and retention empty');
        $p1->post('/privacy/center/activities/seed', ['le' => (string) $le]);
        self::assertSame($n, (int) $db->scalar('SELECT COUNT(*) FROM pv_activities WHERE legal_entity_id = ?', [$le]), 'no duplicates');
        $a = $db->one("SELECT * FROM pv_activities WHERE legal_entity_id = ? AND code = 'LEAVE'", [$le]);
        $apid = (string) $a['public_id'];

        $p2 = $this->as('dpo.demo2');
        $p2->get('/privacy/center/a/' . $apid);
        $p2->post('/privacy/center/a/' . $apid . '/activate');
        self::assertSame('draft', $db->scalar('SELECT status FROM pv_activities WHERE id = ?', [$a['id']]), 'no basis / retention yet');
        $form = ['code' => 'LEAVE', 'name' => 'การลา', 'purpose' => (string) $a['purpose'], 'legal_basis' => 'contract', 'subjects' => 'พนักงาน', 'data_categories' => (string) $a['data_categories'],
            'sensitive' => '1', 'retention' => 'ตามกฎระยะเก็บ LEAVE ที่อนุมัติ (ทดสอบ)'];
        $p1->get('/privacy/center/a/' . $apid);
        self::assertSame(422, $p1->post('/privacy/center/a/' . $apid, ['legal_basis' => 'magic'] + $form)->status);
        $p1->get('/privacy/center/a/' . $apid);
        self::assertSame(303, $p1->post('/privacy/center/a/' . $apid, $form)->status);
        $p2->get('/privacy/center/a/' . $apid);
        $p2->post('/privacy/center/a/' . $apid . '/activate');
        self::assertSame('draft', $db->scalar('SELECT status FROM pv_activities WHERE id = ?', [$a['id']]), 'sensitive data needs a reason');
        $p1->get('/privacy/center/a/' . $apid);
        $p1->post('/privacy/center/a/' . $apid, $form + ['sensitive_note' => 'ใบรับรองแพทย์ — เห็นเฉพาะฝ่ายบุคคล (ทดสอบ)']);
        $p1->get('/privacy/center/a/' . $apid);
        $p1->post('/privacy/center/a/' . $apid . '/activate');
        self::assertSame('draft', $db->scalar('SELECT status FROM pv_activities WHERE id = ?', [$a['id']]), 'the last editor cannot activate');
        $p2->get('/privacy/center/a/' . $apid);
        $p2->post('/privacy/center/a/' . $apid . '/activate');
        $row = $db->one('SELECT status, ai_draft FROM pv_activities WHERE id = ?', [$a['id']]);
        self::assertSame(['active', 0], [$row['status'], (int) $row['ai_draft']]);

        // breach log
        $tz = new \DateTimeZone('Asia/Bangkok');
        $past = $this->t->app->clock->now()->setTimezone($tz)->modify('-2 hours')->format('Y-m-d\TH:i');
        $future = $this->t->app->clock->now()->setTimezone($tz)->modify('+2 hours')->format('Y-m-d\TH:i');
        $p1->get('/privacy/center?tab=breaches&le=' . $le);
        self::assertSame(422, $p1->post('/privacy/center/breaches', ['le' => (string) $le, 'discovered_at' => $future, 'summary' => 'ส่งไฟล์สลิปผิดคน (ทดสอบ)', 'data_categories' => 'สลิปเงินเดือน'])->status);
        $p1->get('/privacy/center?tab=breaches&le=' . $le);
        self::assertSame(303, $p1->post('/privacy/center/breaches', ['le' => (string) $le, 'discovered_at' => $past, 'summary' => 'ส่งไฟล์สลิปผิดคน (ทดสอบ)', 'data_categories' => 'สลิปเงินเดือน', 'people_estimate' => '1'])->status);
        $b = $db->one('SELECT * FROM pv_breaches WHERE legal_entity_id = ?', [$le]);
        self::assertMatchesRegularExpression('/^BR\d{4}-0001$/', (string) $b['ref']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'privacy.breach' AND user_id = ?", [(int) $this->t->user('dpo.demo2')['id']]));
        self::assertStringNotContainsString('สลิป', (string) $db->scalar("SELECT GROUP_CONCAT(IFNULL(diff_json, '')) FROM audit_events WHERE action LIKE 'privacy.breach%'"), 'no details in the audit');
        $bp = (string) $b['public_id'];
        $page = $p1->get('/privacy/center/b/' . $bp)->body;
        self::assertStringContainsString('ยังไม่ได้กำหนดเวลา', $page, 'no time limit until the company sets one');
        self::assertSame(0, PrivacyCenterService::of($this->t->app)->tick());
        $step = function (string $s, array $in) use ($p1, $bp): int {
            $p1->get('/privacy/center/b/' . $bp);
            return $p1->post('/privacy/center/b/' . $bp . '/' . $s, $in)->status;
        };
        $step('close', ['lessons' => 'ตรวจผู้รับก่อนส่งทุกครั้ง']);
        self::assertSame('open', $db->scalar('SELECT status FROM pv_breaches WHERE id = ?', [$b['id']]), 'containment / assessment / decision first');
        self::assertSame(303, $step('contain', ['containment' => 'ขอให้ผู้รับลบไฟล์และยืนยันแล้ว']));
        self::assertSame(422, $step('assess', ['risk' => 'low', 'risk_reason' => '']));
        self::assertSame(303, $step('assess', ['risk' => 'low', 'risk_reason' => 'ผู้รับเป็นพนักงานและลบแล้ว']));
        self::assertSame(303, $step('decide', ['decision' => 'notify', 'decision_reason' => 'ที่ปรึกษากฎหมายให้แจ้งเจ้าของข้อมูล (ทดสอบ)']));
        $step('close', ['lessons' => 'ตรวจผู้รับก่อนส่งทุกครั้ง']);
        self::assertSame('open', $db->scalar('SELECT status FROM pv_breaches WHERE id = ?', [$b['id']]), 'decided to notify but nothing recorded');
        self::assertSame(303, $step('notified', ['subjects_notified_at' => $past]));
        self::assertSame(303, $step('close', ['lessons' => 'ตรวจผู้รับก่อนส่งทุกครั้ง']));
        self::assertSame('closed', $db->scalar('SELECT status FROM pv_breaches WHERE id = ?', [$b['id']]));
        self::assertGreaterThanOrEqual(6, (int) $db->scalar("SELECT COUNT(*) FROM pv_events WHERE entity = 'breach' AND entity_id = ?", [$b['id']]));
        $emp = $this->as('employee.demo1');
        self::assertSame(404, $emp->get('/privacy/center/b/' . $bp)->status);
    }

    public function testBreachTimeLimitOnlyWhenTheCompanySetsIt(): void
    {
        $db = $this->t->app->db();
        $le = $this->le('DEMO1');
        $reg = $this->t->app->registry();
        $admin = new \Blue\Core\Authz\Authorizer($db, $this->t->clock, (int) $this->t->user(TestApp::ADMIN)['id']);
        $org = (int) $db->scalar('SELECT id FROM organizations LIMIT 1');
        $from = $this->t->app->clock->now()->modify('-3 days');
        $vp = $reg->propose($admin, 'privacy.breach_notify_hours', 'organization', $org, '24', $from, null, 'test', 'ที่ปรึกษากฎหมาย (ทดสอบ)');
        $this->t->app->workflow()->decide($reg->submit($admin, $vp), (int) $this->t->user('sysadmin2.demo')['id'], 'approve');
        self::assertSame(24, PrivacyCenterService::of($this->t->app)->notifyHours());

        $p1 = $this->as('dpo.demo1');
        $p1->get('/privacy/center?tab=breaches&le=' . $le);
        $past = $this->t->app->clock->now()->setTimezone(new \DateTimeZone('Asia/Bangkok'))->modify('-30 hours')->format('Y-m-d\TH:i');
        $p1->post('/privacy/center/breaches', ['le' => (string) $le, 'discovered_at' => $past, 'summary' => 'เครื่องโน้ตบุ๊กหาย (ทดสอบ)', 'data_categories' => 'ไฟล์งาน']);
        $b = $db->one('SELECT * FROM pv_breaches WHERE legal_entity_id = ?', [$le]);
        self::assertStringContainsString('24 ชั่วโมงตามที่บริษัทกำหนด', $p1->get('/privacy/center/b/' . $b['public_id'])->body);
        self::assertSame(1, PrivacyCenterService::of($this->t->app)->tick(), 'past the limit and undecided');
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'privacy.breach_overdue'"), 'both officers, once');
        self::assertSame(0, PrivacyCenterService::of($this->t->app)->tick());
    }

    private function today(): string
    {
        return $this->t->app->clock->now()->setTimezone(new \DateTimeZone('Asia/Bangkok'))->format('Y-m-d');
    }
}
