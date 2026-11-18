<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Modules\Jd\JdService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5E-2 (DEC-77): M56 job descriptions — versions with approval, acknowledgement of the exact version, position changes. */
final class JdWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(jd|employees|common|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function browser(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private function errorsLogged(): string
    {
        return implode("\n", array_map(static fn (string $f): string => mb_substr((string) file_get_contents($f), -1500), glob($this->t->tmp . '/logs/*') ?: []));
    }

    /** @return list<int> who approved */
    private function approveAll(string $subjectPid): array
    {
        $db = $this->t->app->db();
        $who = [];
        for ($i = 0; $i < 5; $i++) {
            $inst = (string) $db->scalar("SELECT public_id FROM workflow_instances WHERE subject_id = ? AND status IN ('pending','needs_attention') LIMIT 1", [$subjectPid]);
            if ($inst === '') {
                break;
            }
            $u = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? AND k.status = 'open' LIMIT 1", [$inst]);
            self::assertGreaterThan(0, $u);
            $who[] = $u;
            $this->t->app->workflow()->decide($inst, $u, 'approve');
        }
        return $who;
    }

    /** @return array<string, string> */
    private function draft(string $summary = 'ขายสินค้าและดูแลลูกค้าในพื้นที่ที่รับผิดชอบ', string $change = ''): array
    {
        return ['th_summary' => $summary, 'th_duties' => "- พบลูกค้าตามแผน\n- จัดทำใบเสนอราคา\n- ติดตามการชำระเงิน", 'th_qualifications' => 'ปวส. ขึ้นไป', 'th_kpis' => "ยอดขายตามเป้า\nลูกค้าใหม่ต่อเดือน",
            'en_summary' => 'Sell and look after customers', 'en_duties' => 'Visit customers', 'effective_from' => '2026-11-09', 'change_summary' => $change];
    }

    public function testVersionsAcknowledgementAndPositionChange(): void
    {
        $db = $this->t->app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $hr = $this->browser('hr.demo1');
        $emp = $this->browser('employee.demo1');
        self::assertSame(403, $emp->get('/jd/admin')->status);
        $page = $emp->get('/jd');
        self::assertSame(200, $page->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));

        $admin = $hr->get('/jd/admin');
        self::assertSame(200, $admin->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($admin->body));
        self::assertStringContainsString('พนักงานขาย', $admin->body, 'titles in use are listed');
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        self::assertSame(303, $hr->post('/jd/admin', ['legal_entity' => $lePid, 'code' => 'jd-sales', 'position_title' => '  พนักงานขาย ', 'department' => ''])->status);
        $p = $db->one('SELECT * FROM jd_profiles ORDER BY id DESC LIMIT 1');
        self::assertSame(['JD-SALES', 'พนักงานขาย'], [$p['code'], $p['position_title']]);
        $hr->get('/jd/admin');
        self::assertSame(422, $hr->post('/jd/admin', ['legal_entity' => $lePid, 'code' => 'JD-X', 'position_title' => 'พนักงาน ขาย', 'department' => ''])->status, 'same title (spaces ignored)');

        // draft → review → another HR approves → effective → three sales people get a pending acknowledgement
        $hr->get('/jd/admin/p/' . $p['public_id']);
        $x = $hr->post("/jd/admin/p/" . $p["public_id"] . "/draft", ["th_duties" => ""] + $this->draft());
        self::assertSame(422, $x->status, $this->errorsLogged());
        $hr->get('/jd/admin/p/' . $p['public_id']);
        self::assertSame(303, $hr->post('/jd/admin/p/' . $p['public_id'] . '/draft', $this->draft())->status, $this->errorsLogged());
        $v1 = $db->one('SELECT * FROM jd_versions WHERE profile_id = ?', [$p['id']]);
        self::assertSame('draft', $v1['status']);
        $hr->get('/jd/admin/p/' . $p['public_id']);
        $hr->post('/jd/admin/v/' . $v1['public_id'], ['action' => 'submit']);
        self::assertSame('review', $db->scalar('SELECT status FROM jd_versions WHERE id = ?', [$v1['id']]));
        $hr->get('/jd/admin/p/' . $p['public_id']);
        self::assertSame(303, $hr->post('/jd/admin/p/' . $p['public_id'] . '/draft', $this->draft())->status);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM jd_versions WHERE profile_id = ?', [$p['id']]), 'no new draft while one is in review');
        $who = $this->approveAll((string) $v1['public_id']);
        self::assertNotContains((int) $this->t->user('hr.demo1')['id'], $who, 'not the writer');
        self::assertSame('effective', $db->scalar('SELECT status FROM jd_versions WHERE id = ?', [$v1['id']]));
        self::assertSame(3, (int) $db->scalar("SELECT COUNT(*) FROM jd_acks WHERE version_id = ? AND status = 'pending'", [$v1['id']]), 'D1-0004, D1-0005, D1-0006');
        $uid = (int) $this->t->user('employee.demo1')['id'];
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'jd.ack'", [$uid]));

        // acknowledge: must open first, must confirm; evidence kept
        $mine = $emp->get('/jd')->body;
        self::assertStringContainsString('/jd/v/' . $v1['public_id'], $mine);
        $emp->get('/jd');
        self::assertSame(422, $emp->post('/jd/v/' . $v1['public_id'] . '/ack', ['confirm' => '1'])->status, 'open first');
        $view = $emp->get('/jd/v/' . $v1['public_id']);
        self::assertSame(200, $view->status);
        self::assertStringContainsString('จัดทำใบเสนอราคา', $view->body);
        self::assertStringContainsString('paper-doc', $view->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($view->body));
        self::assertSame(422, $emp->post('/jd/v/' . $v1['public_id'] . '/ack', [])->status, 'tick the statement');
        $emp->get('/jd/v/' . $v1['public_id']);
        self::assertSame(303, $emp->post('/jd/v/' . $v1['public_id'] . '/ack', ['confirm' => '1'])->status);
        $empId = (int) $db->scalar("SELECT id FROM employees WHERE employee_code = 'D1-0004'");
        $ack = $db->one('SELECT * FROM jd_acks WHERE version_id = ? AND employee_id = ?', [$v1['id'], $empId]);
        self::assertSame('acknowledged', $ack['status']);
        self::assertSame($v1['content_sha256'], $ack['content_sha256']);
        self::assertSame('10.0.0.1', $ack['ip']);
        self::assertStringContainsString('ฉบับที่ 1', (string) $ack['statement']);

        // HR: report, exception with a reason, remind
        $rep = $hr->get('/jd/admin/p/' . $p['public_id']);
        self::assertSame(200, $rep->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($rep->body));
        $other = (int) $db->scalar("SELECT a.id FROM jd_acks a JOIN employees e ON e.id = a.employee_id WHERE a.version_id = ? AND e.employee_code = 'D1-0005'", [$v1['id']]);
        self::assertSame(422, $hr->post('/jd/admin/p/' . $p['public_id'] . '/exception', ['ack' => (string) $other, 'reason' => ''])->status);
        $hr->get('/jd/admin/p/' . $p['public_id']);
        $hr->post('/jd/admin/p/' . $p['public_id'] . '/exception', ['ack' => (string) $other, 'reason' => 'ลาคลอด 3 เดือน']);
        self::assertSame('exception', $db->scalar('SELECT status FROM jd_acks WHERE id = ?', [$other]));

        // version 2 → everyone again; version 1 stays in history; the old version is no longer effective
        $hr->get('/jd/admin/p/' . $p['public_id']);
        self::assertSame(422, $hr->post('/jd/admin/p/' . $p['public_id'] . '/draft', $this->draft('ขายสินค้า ดูแลลูกค้า และรายงานยอดประจำสัปดาห์'))->status, 'what changed is required');
        $hr->get('/jd/admin/p/' . $p['public_id']);
        $hr->post('/jd/admin/p/' . $p['public_id'] . '/draft', $this->draft('ขายสินค้า ดูแลลูกค้า และรายงานยอดประจำสัปดาห์', 'เพิ่มการรายงานยอดประจำสัปดาห์'));
        $v2 = $db->one("SELECT * FROM jd_versions WHERE profile_id = ? AND status = 'draft'", [$p['id']]);
        $hr->get('/jd/admin/p/' . $p['public_id']);
        $hr->post('/jd/admin/v/' . $v2['public_id'], ['action' => 'submit']);
        $this->approveAll((string) $v2['public_id']);
        self::assertSame(['superseded', 'effective'], [$db->scalar('SELECT status FROM jd_versions WHERE id = ?', [$v1['id']]), $db->scalar('SELECT status FROM jd_versions WHERE id = ?', [$v2['id']])]);
        self::assertSame('pending', $db->scalar('SELECT status FROM jd_acks WHERE version_id = ? AND employee_id = ?', [$v2['id'], $empId]), 'an older acknowledgement never counts for a newer version');
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM jd_acks WHERE version_id = ? AND status = 'pending'", [$v1['id']]), 'open ones of the old version go away');
        $hist = $emp->get('/jd')->body;
        self::assertStringContainsString('/jd/v/' . $v1['public_id'], $hist, 'history keeps version 1');

        // position change → the sales JD no longer applies; a JD for the new title is asked instead
        $db->run("UPDATE employment_history SET position_title = 'ผู้ช่วยขาย' WHERE employee_id = ? AND effective_to IS NULL", [$empId]);
        JdService::syncJob($this->t->app);
        self::assertNull($db->scalar('SELECT id FROM jd_acks WHERE version_id = ? AND employee_id = ?', [$v2['id'], $empId]), 'pending one removed after moving');
        self::assertSame('acknowledged', $db->scalar('SELECT status FROM jd_acks WHERE version_id = ? AND employee_id = ?', [$v1['id'], $empId]), 'done one stays');
        self::assertStringContainsString('ยังไม่มี JD', $emp->get('/jd')->body);
        self::assertSame(200, $emp->get('/jd/v/' . $v1['public_id'])->status, 'can still read what was acknowledged');
        self::assertSame(404, $emp->get('/jd/v/' . $v2['public_id'])->status, 'not assigned → not visible');
        self::assertSame(404, $this->browser('manager.demo1')->get('/jd/v/' . $v1['public_id'])->status);

        // a unit-specific JD wins over the company-wide one
        $sales = (string) $db->scalar("SELECT public_id FROM departments WHERE code = 'SALES' AND legal_entity_id = ?", [$le]);
        $hr->get('/jd/admin');
        $hr->post('/jd/admin', ['legal_entity' => $lePid, 'code' => 'JD-SALES-HQ', 'position_title' => 'พนักงานขาย', 'department' => $sales]);
        $p2 = $db->one("SELECT * FROM jd_profiles WHERE code = 'JD-SALES-HQ'");
        $hr->get('/jd/admin/p/' . $p2['public_id']);
        $hr->post('/jd/admin/p/' . $p2['public_id'] . '/draft', $this->draft('JD ของพนักงานขายในฝ่ายขายโดยเฉพาะ'));
        $v3 = $db->one('SELECT * FROM jd_versions WHERE profile_id = ?', [$p2['id']]);
        $hr->get('/jd/admin/p/' . $p2['public_id']);
        $hr->post('/jd/admin/v/' . $v3['public_id'], ['action' => 'submit']);
        $this->approveAll((string) $v3['public_id']);
        $d5 = (int) $db->scalar("SELECT id FROM employees WHERE employee_code = 'D1-0006'");
        self::assertNotNull($db->scalar('SELECT id FROM jd_acks WHERE version_id = ? AND employee_id = ?', [$v3['id'], $d5]));
        self::assertNull($db->scalar("SELECT id FROM jd_acks WHERE version_id = ? AND employee_id = ? AND status = 'pending'", [$v2['id'], $d5]));
    }
}
