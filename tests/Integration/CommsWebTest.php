<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Modules\Announcements\AnnouncementService;
use Blue\Modules\Directory\DirectoryService;
use Blue\Modules\Doccontrol\DocControlService;
use Blue\Modules\Employees\Audience;
use Blue\Modules\Forms\FormService;
use Blue\Tests\Support\TestApp;

/** Phase 5D (DEC-72): target groups, M02 forms, M19 announcements, M40 controlled documents + acknowledgement, M47 directory. */
final class CommsWebTest extends TestCase
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

    private function file(string $name): UploadedFile
    {
        return UploadedFile::fromBytes($name, (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/' . $name), $this->t->tmp);
    }

    /** @return array{0: string, 1: string} department public ids: employee.demo1's unit and another unit of DEMO1 */
    private function units(): array
    {
        $db = $this->t->app->db();
        $mine = (int) $db->scalar("SELECT h.department_id FROM employment_history h JOIN employees e ON e.id = h.employee_id WHERE e.employee_code = 'D1-0004' AND h.effective_to IS NULL");
        $other = (string) $db->scalar('SELECT public_id FROM departments WHERE legal_entity_id = ? AND id <> ? AND parent_id IS NULL ORDER BY id LIMIT 1', [$this->le(), $mine]);
        return [(string) $db->scalar('SELECT public_id FROM departments WHERE id = ?', [$mine]), $other];
    }

    private function approve(string $subjectTable, string $pid): void
    {
        $db = $this->t->app->db();
        $inst = (string) $db->scalar("SELECT i.public_id FROM workflow_instances i JOIN {$subjectTable} s ON s.workflow_instance_id = i.id WHERE s.public_id = ?", [$pid]);
        $who = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? AND k.status = 'open' LIMIT 1", [$inst]);
        self::assertGreaterThan(0, $who, 'an approver got the task');
        self::assertNotSame((int) $this->t->user('hr.demo1')['id'], $who, 'never the maker');
        $this->t->app->workflow()->decide($inst, $who, 'approve');
    }

    public function testAudienceMatchesByUnitIncludingChildrenAndGroup(): void
    {
        [$mine, $other] = $this->units();
        $app = $this->t->app;
        $job = $app->db()->one("SELECT h.* FROM employment_history h JOIN employees e ON e.id = h.employee_id WHERE e.employee_code = 'D1-0004' AND h.effective_to IS NULL");
        self::assertTrue(Audience::matches($app, $this->le(), Audience::empty(), $job), 'empty = everyone in the company');
        self::assertTrue(Audience::matches($app, $this->le(), Audience::fromInput($app, $this->le(), ['departments' => [$mine]]), $job));
        self::assertFalse(Audience::matches($app, $this->le(), Audience::fromInput($app, $this->le(), ['departments' => [$other]]), $job));
        self::assertFalse(Audience::matches($app, $this->le(), Audience::fromInput($app, $this->le(), ['groups' => 'NOPE']), $job));
        $parent = $app->db()->scalar('SELECT parent_id FROM departments WHERE public_id = ?', [$mine]);
        if ($parent !== null) {
            $pp = (string) $app->db()->scalar('SELECT public_id FROM departments WHERE id = ?', [$parent]);
            self::assertTrue(Audience::matches($app, $this->le(), Audience::fromInput($app, $this->le(), ['departments' => [$pp]]), $job), 'a parent unit includes its children');
        }
        self::assertFalse(Audience::matches($app, (int) $app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO2'"), Audience::empty(), $job), 'another company');
        try {
            Audience::fromInput($app, $this->le(), ['groups' => 'bad group!']);
            self::fail('bad group code');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('audience', $e->errors);
        }
        self::assertArrayHasKey((int) $app->db()->scalar("SELECT id FROM employees WHERE employee_code = 'D1-0004'"), Audience::members($app, $this->le(), Audience::empty(), '2026-11-09'));
    }

    public function testFormsVersionApprovalAudienceDownloadAndStars(): void
    {
        $svc = FormService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $me = $this->az('employee.demo1');
        [$mine, $other] = $this->units();
        $db = $this->t->app->db();
        try {
            $svc->save($hr, $this->le(), ['code' => '!', 'title' => '', 'category' => '']);
            self::fail('invalid');
        } catch (ValidationException $e) {
            self::assertSame(['code', 'title', 'category'], array_keys($e->errors));
        }
        $pid = $svc->save($hr, $this->le(), ['code' => 'HR-01', 'title' => 'แบบฟอร์มขอเอกสารรับรอง', 'category' => 'บุคคล', 'language' => 'th', 'departments' => [$mine]]);
        self::assertSame([], $svc->browse($me), 'nothing published yet');
        $v1 = $svc->upload($hr, $pid, $this->file('a.pdf'), 'ฉบับแรก');
        try {
            $svc->upload($hr, $pid, $this->file('b.pdf'), 'ซ้อน');
            self::fail('one open version');
        } catch (BusinessException $e) {
            self::assertSame('forms.error.open_version', $e->messageKey);
        }
        $svc->submit($hr, $v1);
        $this->approve('frm_versions', $v1);
        $rows = $svc->browse($me);
        self::assertCount(1, $rows, 'published + in my unit');
        $svc->star($me, $pid, true);
        $f = $svc->file($me, $pid, null, 'download');
        self::assertStringStartsWith('%PDF', $f['bytes']);
        self::assertSame(1, (int) $db->scalar('SELECT download_count FROM frm_forms WHERE public_id = ?', [$pid]));
        self::assertSame('preview', $svc->file($me, $pid, null, 'preview')['event']);

        // a new version supersedes; users only get the current one; starred users are told
        $v2 = $svc->upload($hr, $pid, $this->file('b.pdf'), 'แก้ช่องลายมือชื่อ');
        $svc->submit($hr, $v2);
        $this->approve('frm_versions', $v2);
        self::assertSame(['superseded', 'published'], array_map('strval', array_column($db->all('SELECT status FROM frm_versions WHERE form_id = (SELECT id FROM frm_forms WHERE public_id = ?) ORDER BY version_no', [$pid]), 'status')));
        self::assertNotNull($db->scalar("SELECT id FROM notifications WHERE event_type = 'forms.new_version'"));
        try {
            $svc->file($me, $pid, $v1, 'download');
            self::fail('old version for users');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        self::assertStringStartsWith('%PDF', $svc->file($hr, $pid, $v1, 'download')['bytes'], 'managers may');

        // target group: another unit cannot see it
        $svc->save($hr, $this->le(), ['code' => 'HR-01', 'title' => 'แบบฟอร์มขอเอกสารรับรอง', 'category' => 'บุคคล', 'departments' => [$other]], $pid);
        self::assertSame([], $svc->browse($me));
        try {
            $svc->form($me, $pid);
            self::fail('outside the group');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        try {
            $svc->save($this->az('hr.demo2'), $this->le(), ['code' => 'X1', 'title' => 'x', 'category' => 'x']);
            self::fail('other company');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testAnnouncementsApprovalScheduleReadAckAndEngagement(): void
    {
        $svc = AnnouncementService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $me = $this->az('employee.demo1');
        $db = $this->t->app->db();
        $now = $svc->local($svc->nowUtc());
        try {
            $svc->save($hr, $this->le(), ['title' => '', 'body' => '', 'category' => '', 'starts_at' => 'x', 'popup_seconds' => '3', 'video_url' => 'http://x']);
            self::fail('invalid');
        } catch (ValidationException $e) {
            foreach (['title', 'body', 'category', 'starts_at', 'popup_seconds', 'video_url'] as $k) {
                self::assertArrayHasKey($k, $e->errors, $k);
            }
        }
        // normal priority publishes at once and notifies the target group
        $a = $svc->save($hr, $this->le(), ['title' => 'ปิดปรับปรุงลิฟต์', 'body' => "วันเสาร์นี้\n\nใช้บันไดหนีไฟ", 'category' => 'อาคาร', 'priority' => 'normal', 'starts_at' => $now,
            'requires_ack' => '1', 'popup_seconds' => '5', 'link_url' => 'https://example.org/notice']);
        $svc->submit($hr, $a);
        self::assertSame('published', $db->scalar('SELECT status FROM ann_posts WHERE public_id = ?', [$a]));
        self::assertNotNull($db->scalar("SELECT id FROM notifications WHERE event_type = 'announcements.published' AND user_id = ?", [$this->t->user('employee.demo1')['id']]));
        $home = $svc->forHome($me);
        self::assertSame($a, $home['popup']['public_id'] ?? null, 'login pop-up');
        self::assertNull($svc->forHome($me)['popup'], 'only once');
        self::assertCount(1, $svc->board($me, '', '', 'unread'));
        $view = $svc->view($me, $a);
        self::assertSame(1, (int) $view['mine']['views']);
        $svc->acknowledge($me, $a);
        $svc->react($me, $a, 'thanks');
        self::assertSame('https://example.org/notice', $svc->click($me, $a, 'link'));
        $st = $svc->stats($hr, $a);
        self::assertSame([1, 1, 1], [$st['acked'], $st['reactions']['thanks'], $st['clicks']]);
        self::assertGreaterThan(1, $st['audience']);
        self::assertSame($st['audience'] - 1, $st['not_read_count']);
        self::assertGreaterThan(0, $svc->remind($hr, $a));

        // editing a published one = new revision; the old acknowledgement no longer counts
        $svc->save($hr, $this->le(), ['title' => 'ปิดปรับปรุงลิฟต์ (เปลี่ยนวัน)', 'body' => 'วันอาทิตย์แทน', 'category' => 'อาคาร', 'priority' => 'normal', 'starts_at' => $now, 'requires_ack' => '1'], $a);
        self::assertSame(2, (int) $db->scalar('SELECT revision FROM ann_posts WHERE public_id = ?', [$a]));
        self::assertSame(0, $svc->stats($hr, $a)['acked']);
        self::assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM ann_revisions WHERE post_id = (SELECT id FROM ann_posts WHERE public_id = ?)', [$a]));

        // important: another person approves; scheduled for later then published by the job
        $later = $svc->local($this->t->clock->now()->modify('+2 hours')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        $b = $svc->save($hr, $this->le(), ['title' => 'นโยบายวันหยุดใหม่', 'body' => 'รายละเอียด', 'category' => 'HR', 'priority' => 'important', 'starts_at' => $later]);
        $svc->submit($hr, $b);
        self::assertSame('review', $db->scalar('SELECT status FROM ann_posts WHERE public_id = ?', [$b]));
        $this->approve('ann_posts', $b);
        self::assertSame('scheduled', $db->scalar('SELECT status FROM ann_posts WHERE public_id = ?', [$b]));
        self::assertCount(1, $svc->board($me), 'not visible before its time');
        $this->t->clock->advance('+3 hours');
        self::assertGreaterThanOrEqual(1, $svc->tick());
        self::assertSame('published', $db->scalar('SELECT status FROM ann_posts WHERE public_id = ?', [$b]));
        $svc->withdraw($hr, $b, 'ประกาศผิด');
        try {
            $svc->view($me, $b);
            self::fail('withdrawn');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
    }

    public function testControlledDocumentApprovalEffectiveAcknowledgementAndAudienceChange(): void
    {
        $svc = DocControlService::of($this->t->app);
        $hr = $this->az('hr.demo1');
        $me = $this->az('employee.demo1');
        $db = $this->t->app->db();
        [$mine, $other] = $this->units();
        $d = $svc->save($hr, $this->le(), ['code' => 'POL-01', 'title' => 'นโยบายความปลอดภัยข้อมูล', 'doc_type' => 'policy', 'review_months' => '12', 'departments' => [$mine]]);
        try {
            $svc->upload($hr, $d, $this->file('alpha.png'), ['change_summary' => 'ฉบับแรก', 'effective_from' => '2026-11-09', 'requires_ack' => '1']);
            self::fail('PDF only');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('file', $e->errors);
        }
        $v1 = $svc->upload($hr, $d, $this->file('a.pdf'), ['change_summary' => 'ฉบับแรก', 'effective_from' => '2026-11-09', 'requires_ack' => '1']);
        $svc->submit($hr, $v1);
        $this->approve('dc_versions', $v1);
        self::assertSame('effective', $db->scalar('SELECT status FROM dc_versions WHERE public_id = ?', [$v1]), 'effective today');
        $mineList = $svc->mine($me);
        self::assertCount(1, $mineList['pending']);
        try {
            $svc->acknowledge($me, $d, true, '10.0.0.1', 'phpunit');
            self::fail('must open first');
        } catch (BusinessException $e) {
            self::assertSame('doccontrol.error.open_first', $e->messageKey);
        }
        $svc->file($me, $d, null);
        try {
            $svc->acknowledge($me, $d, false, '10.0.0.1', 'phpunit');
            self::fail('confirm');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('confirm', $e->errors);
        }
        $svc->acknowledge($me, $d, true, '10.0.0.1', 'phpunit');
        $ack = $db->one("SELECT a.* FROM dc_acks a JOIN dc_versions v ON v.id = a.version_id WHERE v.public_id = ? AND a.status = 'acknowledged'", [$v1]);
        self::assertNotNull($ack);
        self::assertSame((string) $db->scalar('SELECT sha256 FROM dc_versions WHERE public_id = ?', [$v1]), $ack['file_sha256']);
        self::assertSame('2027-11-09', $db->scalar('SELECT review_due FROM dc_versions WHERE public_id = ?', [$v1]));

        // version 2 effective later: v1 stays until then; the old acknowledgement does not count for v2
        $v2 = $svc->upload($hr, $d, $this->file('b.pdf'), ['change_summary' => 'เพิ่มหมวดรหัสผ่าน', 'effective_from' => '2026-11-12', 'requires_ack' => '1']);
        $svc->submit($hr, $v2);
        $this->approve('dc_versions', $v2);
        self::assertSame('approved', $db->scalar('SELECT status FROM dc_versions WHERE public_id = ?', [$v2]));
        $this->t->clock->advance('+3 days');
        DocControlService::syncJob($this->t->app);
        self::assertSame(['superseded', 'effective'], array_map('strval', array_column($db->all('SELECT status FROM dc_versions WHERE document_id = (SELECT id FROM dc_documents WHERE public_id = ?) ORDER BY version_no', [$d]), 'status')));
        self::assertCount(1, $svc->mine($me)['pending'], 'v2 needs a new acknowledgement');
        try {
            $svc->file($me, $d, $v1);
            self::fail('only the current version for readers');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        $rep = $svc->report((int) $db->scalar('SELECT id FROM dc_versions WHERE public_id = ?', [$v2]));
        self::assertSame(0, $rep['acked']);
        self::assertGreaterThanOrEqual(1, $rep['pending']);
        $hrAckId = (int) $db->scalar("SELECT a.id FROM dc_acks a JOIN dc_versions v ON v.id = a.version_id JOIN employees e ON e.id = a.employee_id WHERE v.public_id = ? AND e.employee_code = 'D1-0004'", [$v2]);
        $svc->exception($hr, $d, $hrAckId, 'ลาคลอดยาว 3 เดือน');
        self::assertSame(1, $svc->report((int) $db->scalar('SELECT id FROM dc_versions WHERE public_id = ?', [$v2]))['exception']);

        // the target group moves away from my unit: open tasks of people outside it are dropped
        $svc->save($hr, $this->le(), ['code' => 'POL-01', 'title' => 'นโยบายความปลอดภัยข้อมูล', 'doc_type' => 'policy', 'review_months' => '12', 'departments' => [$other]], $d);
        try {
            $svc->view($me, $d);
            self::fail('no longer in the group');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM dc_acks a JOIN dc_versions v ON v.id = a.version_id WHERE v.public_id = ? AND a.status = 'acknowledged'", [$v1]), 'evidence of v1 kept');
    }

    public function testDirectoryFieldsSearchTeamPinAndExport(): void
    {
        $svc = DirectoryService::of($this->t->app);
        $me = $this->az('employee.demo1');
        $db = $this->t->app->db();
        $db->run("UPDATE employees SET work_email = 'manager.demo@demo.invalid', work_phone = '02-000-0003' WHERE employee_code = 'D1-0003'");
        self::assertSame([], $svc->search($me, ''), 'type something first');
        $code = 'D1-0003';
        $found = $svc->search($me, $code);
        self::assertCount(1, $found);
        self::assertSame(['manager.demo@demo.invalid', '02-000-0003'], [$found[0]['email'], $found[0]['phone']]);
        $svc->saveSettings($this->az('hr.demo1'), $this->le(), ['fields' => ['position', 'department', 'site', 'extension']]);
        $found = $svc->search($me, $code);
        self::assertNull($found[0]['email'], 'hidden by the company');
        self::assertNull($found[0]['phone']);
        self::assertSame([], $svc->search($this->az('hr.demo2'), $code), 'another company is not searchable by default');
        $db->run("UPDATE employees SET status = 'former' WHERE employee_code = 'D1-0005'");
        self::assertSame([], $svc->search($me, 'D1-0005'), 'leavers never appear');
        $team = array_column($svc->search($me, '', 'team'), 'code');
        self::assertContains('D1-0003', $team, 'my manager is in my team');
        $svc->pin($me, (string) $found[0]['public_id'], true);
        self::assertSame(['D1-0003'], array_column($svc->search($me, '', 'favorites'), 'code'));
        $svc->saveExtension($me, '1234');
        self::assertSame('1234', $svc->search($this->az('manager.demo1'), 'D1-0004')[0]['extension']);
        try {
            $svc->saveExtension($me, 'abc');
            self::fail('bad extension');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('extension', $e->errors);
        }
        $x = $svc->export($this->az('hr.demo1'), $this->le());
        self::assertNotContains('email', $x['headers'], 'only visible fields');
        try {
            $svc->export($me, $this->le());
            self::fail('export needs permission');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testScreensRenderWithoutRawKeys(): void
    {
        $hr = $this->t->browser();
        $hr->login('hr.demo1');
        $le = (string) $this->t->app->db()->scalar("SELECT public_id FROM legal_entities WHERE code = 'DEMO1'");
        $res = $hr->post('/forms/admin', ['legal_entity' => $le, 'code' => 'FIN-02', 'title' => 'ใบเบิกเงินสดย่อย', 'category' => 'การเงิน', 'language' => 'th']);
        self::assertSame(303, $res->status);
        $formPage = (string) parse_url((string) $res->headers['Location'], PHP_URL_PATH);
        $up = $hr->post($formPage . '/upload', ['change_note' => 'ฉบับแรก'], ['file' => ['a.pdf', (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/a.pdf')]]);
        self::assertSame(303, $up->status, 'multipart upload');
        $res = $hr->post('/announcements/admin', ['legal_entity' => $le, 'title' => 'ทดสอบ', 'body' => 'เนื้อหา', 'category' => 'ทั่วไป', 'priority' => 'normal', 'starts_at' => '2026-11-09T09:00']);
        self::assertSame(303, $res->status);
        $annPage = (string) parse_url((string) $res->headers['Location'], PHP_URL_PATH);
        $res = $hr->post('/policies/admin', ['legal_entity' => $le, 'code' => 'WI-01', 'title' => 'วิธีปฏิบัติงานคลังสินค้า', 'doc_type' => 'work_instruction']);
        self::assertSame(303, $res->status);
        $dcPage = (string) parse_url((string) $res->headers['Location'], PHP_URL_PATH);
        foreach (['/forms', '/forms/admin', $formPage, '/announcements', '/announcements/admin', $annPage, '/policies', '/policies/admin', $dcPage, '/directory?q=D1', '/directory/admin', '/'] as $path) {
            [$pp, $qs] = array_pad(explode('?', $path, 2), 2, '');
            parse_str($qs, $query);
            $p = $hr->get($pp, $query);
            self::assertSame(200, $p->status, $path);
            self::assertDoesNotMatchRegularExpression('/\b(forms|announcements|doccontrol|directory|employees)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/', strip_tags($p->body), 'no raw keys on ' . $path);
        }
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        foreach (['/forms', '/announcements', '/policies', '/directory'] as $path) {
            self::assertSame(200, $emp->get($path)->status, $path);
        }
        foreach (['/forms/admin', '/announcements/admin', '/policies/admin', '/directory/admin'] as $path) {
            self::assertSame(403, $emp->get($path)->status, $path);
        }
        $partial = $emp->get('/directory', ['q' => 'D1', 'partial' => '1']);
        self::assertStringStartsWith('<div data-dir-results', trim($partial->body), 'type-ahead fragment');
    }
}
