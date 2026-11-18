<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Modules\Eid\CardService;
use Blue\Modules\Training\TrainingService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5E-3 (DEC-78): M10 training — courses, sessions, enrolment (seats, waitlist, clash, prerequisite, approval), check-in, results, certificates, matrix. */
final class TrainingWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(training|employees|common|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    /** @return array<string, string> */
    private function session(string $course, string $date, string $from, string $to, string $seats): array
    {
        return ['course' => $course, 'start_date' => $date, 'end_date' => '', 'start_time' => $from, 'end_time' => $to, 'location' => 'ห้องประชุมใหญ่', 'trainer' => 'วิทยากรภายนอก', 'seats' => $seats, 'cost' => '12,000', 'enroll_until' => ''];
    }

    private function newSession(TestBrowser $hr, string $lePid, array $in): string
    {
        $hr->get('/training/admin');
        $res = $hr->post('/training/admin/sessions', ['le' => $lePid] + $in);
        self::assertSame(303, $res->status, $this->errorsLogged());
        $pid = (string) $this->t->app->db()->scalar('SELECT public_id FROM trn_sessions ORDER BY id DESC LIMIT 1');
        $hr->get('/training/admin/s/' . $pid);
        $hr->post('/training/admin/s/' . $pid . '/status', ['to' => 'open']);
        return $pid;
    }

    public function testTrainingFlow(): void
    {
        $db = $this->t->app->db();
        $app = $this->t->app;
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $hr = $this->browser('hr.demo1');
        $emp = $this->browser('employee.demo1');
        self::assertSame(403, $emp->get('/training/admin')->status);
        $page = $hr->get('/training/admin');
        self::assertSame(200, $page->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));

        // courses
        $c = $hr->get('/training/admin/courses');
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($c->body));
        self::assertSame(422, $hr->post('/training/admin/courses', ['le' => $lePid, 'code' => 'SAFE-01', 'title' => 'ความปลอดภัยในการทำงาน', 'hours' => '0'])->status);
        $hr->get('/training/admin/courses');
        $hr->post('/training/admin/courses', ['le' => $lePid, 'code' => 'safe-01', 'title' => 'ความปลอดภัยในการทำงาน', 'hours' => '6', 'pass_score' => '70', 'valid_months' => '12', 'required_titles' => 'พนักงานขาย, ผู้ช่วยขาย']);
        $safe = (string) $db->scalar("SELECT public_id FROM trn_courses WHERE code = 'SAFE-01'");
        $hr->get('/training/admin/courses');
        $hr->post('/training/admin/courses', ['le' => $lePid, 'code' => 'LEAD-01', 'title' => 'ภาวะผู้นำ', 'hours' => '12', 'needs_approval' => '1', 'prerequisite' => $safe]);
        $lead = (string) $db->scalar("SELECT public_id FROM trn_courses WHERE code = 'LEAD-01'");
        self::assertNotSame('', $lead);

        // today's session, 2 places: one self, two by HR → one waitlisted; never twice
        $s1 = $this->newSession($hr, $lePid, $this->session($safe, '2026-11-09', '09:00', '16:00', '2'));
        self::assertSame('open', $db->scalar('SELECT status FROM trn_sessions WHERE public_id = ?', [$s1]));
        $list = $emp->get('/training');
        self::assertSame(200, $list->status, $this->errorsLogged());
        self::assertStringContainsString('ความปลอดภัยในการทำงาน', $list->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($list->body));
        $emp->get('/training/s/' . $s1);
        self::assertSame(303, $emp->post('/training/s/' . $s1 . '/enroll', [])->status);
        $emp->get('/training/s/' . $s1);
        $emp->post('/training/s/' . $s1 . '/enroll', []);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM trn_enrollments'), 'never twice');
        $hr->get('/training/admin/s/' . $s1);
        $hr->post('/training/admin/s/' . $s1 . '/enroll', ['codes' => "D1-0005\nD1-0006, X-999"]);
        $st = static fn (string $code, string $sess) => $db->scalar('SELECT e.status FROM trn_enrollments e JOIN employees p ON p.id = e.employee_id JOIN trn_sessions s ON s.id = e.session_id WHERE p.employee_code = ? AND s.public_id = ?', [$code, $sess]);
        self::assertSame(['enrolled', 'enrolled', 'waitlisted'], [$st('D1-0004', $s1), $st('D1-0005', $s1), $st('D1-0006', $s1)]);

        // a session tomorrow (1 place): clash with an overlapping one; cancelling frees the place for the waitlist
        $s2 = $this->newSession($hr, $lePid, $this->session($safe, '2026-11-10', '09:00', '12:00', '1'));
        $s3 = $this->newSession($hr, $lePid, $this->session($safe, '2026-11-10', '10:00', '15:00', '10'));
        $emp->get('/training/s/' . $s2);
        $emp->post('/training/s/' . $s2 . '/enroll', []);
        $emp->get('/training/s/' . $s3);
        $emp->post('/training/s/' . $s3 . '/enroll', []);
        self::assertNull($st('D1-0004', $s3), 'clash refused');
        $hr->get('/training/admin/s/' . $s2);
        $hr->post('/training/admin/s/' . $s2 . '/enroll', ['codes' => 'D1-0003']);
        self::assertSame('waitlisted', $st('D1-0003', $s2));
        $enr = (string) $db->scalar("SELECT e.public_id FROM trn_enrollments e JOIN employees p ON p.id = e.employee_id JOIN trn_sessions s ON s.id = e.session_id WHERE p.employee_code = 'D1-0004' AND s.public_id = ?", [$s2]);
        $emp->get('/training');
        $emp->post('/training/e/' . $enr . '/cancel', []);
        self::assertSame(['cancelled', 'enrolled'], [$st('D1-0004', $s2), $st('D1-0003', $s2)], 'the waitlist moves up');
        $mgrUid = (int) $this->t->user('manager.demo1')['id'];
        self::assertGreaterThanOrEqual(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'training.enrolment'", [$mgrUid]));
        self::assertGreaterThanOrEqual(1, TrainingService::remindersJob($app));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'training.reminder'", [$mgrUid]), 'reminded the day before');
        TrainingService::remindersJob($app);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'training.reminder'", [$mgrUid]), 'once');
        // cancelling a session tells everyone
        $hr->get('/training/admin/s/' . $s3);
        $hr->post('/training/admin/s/' . $s3 . '/enroll', ['codes' => 'D1-0007']);
        $hr->get('/training/admin/s/' . $s3);
        self::assertSame(422, $hr->post('/training/admin/s/' . $s3 . '/status', ['to' => 'cancelled', 'reason' => ''])->status);
        $hr->get('/training/admin/s/' . $s3);
        $hr->post('/training/admin/s/' . $s3 . '/status', ['to' => 'cancelled', 'reason' => 'วิทยากรติดภารกิจ']);
        self::assertSame(['cancelled', 'cancelled'], [$db->scalar('SELECT status FROM trn_sessions WHERE public_id = ?', [$s3]), $st('D1-0007', $s3)]);

        // check-in: employee code, twice, someone waitlisted, unknown, and the e-ID card QR
        $mg = $hr->get('/training/admin/s/' . $s1);
        self::assertSame(200, $mg->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($mg->body));
        $scan = function (string $input) use ($hr, $s1): string {
            $hr->get('/training/admin/s/' . $s1);
            $hr->post('/training/admin/s/' . $s1 . '/checkin', ['scan' => $input]);
            return strip_tags($hr->get('/training/admin/s/' . $s1)->body);
        };
        self::assertStringContainsString('เช็คชื่อแล้ว: พนักงานตัวอย่าง (DEMO)', $scan('D1-0004'));
        self::assertStringContainsString('เช็คชื่อไปแล้ว', $scan('D1-0004'));
        self::assertStringContainsString('ไม่ได้ลงทะเบียนรอบนี้', $scan('D1-0006'));
        self::assertStringContainsString('ไม่พบพนักงาน', $scan('ZZ-0000'));
        $d5 = (string) $db->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0005'");
        $cards = CardService::of($app);
        $cards->issue($this->az('hr.demo1'), $d5);
        $card = $cards->current((int) $db->scalar('SELECT id FROM employees WHERE public_id = ?', [$d5]));
        self::assertNotNull($card);
        self::assertStringContainsString('เช็คชื่อแล้ว: พนักงานขายสอง (DEMO)', $scan($cards->qr($card, 'http://localhost')['url']));
        self::assertSame('qr', $db->scalar("SELECT e.check_method FROM trn_enrollments e JOIN employees p ON p.id = e.employee_id JOIN trn_sessions s ON s.id = e.session_id WHERE p.employee_code = 'D1-0005' AND s.public_id = ?", [$s1]));

        // results (pass mark 70) → certificates; prerequisite then approval course
        $ids = [];
        foreach ($db->all("SELECT e.id, p.employee_code FROM trn_enrollments e JOIN employees p ON p.id = e.employee_id JOIN trn_sessions s ON s.id = e.session_id WHERE s.public_id = ?", [$s1]) as $r) {
            $ids[(string) $r['employee_code']] = (int) $r['id'];
        }
        $hr->get('/training/admin/s/' . $s1);
        self::assertSame(422, $hr->post('/training/admin/s/' . $s1 . '/evaluate', ['score' => [$ids['D1-0004'] => 'abc']])->status);
        $hr->get('/training/admin/s/' . $s1);
        $hr->post('/training/admin/s/' . $s1 . '/evaluate', ['score' => [$ids['D1-0004'] => '85', $ids['D1-0005'] => '60']]);
        self::assertSame(['passed', 'failed'], [$st('D1-0004', $s1), $st('D1-0005', $s1)]);
        $hr->get('/training/admin/s/' . $s1);
        $hr->post('/training/admin/s/' . $s1 . '/certs', []);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM trn_certificates'), $this->errorsLogged());
        $cert = $db->one('SELECT * FROM trn_certificates ORDER BY id DESC LIMIT 1');
        self::assertMatchesRegularExpression('/^TRN-2026-\d{5}$/', (string) $cert['cert_no']);
        self::assertSame('2027-11-09', $cert['expires_on']);
        $doc = $emp->get('/training/c/' . $cert['public_id']);
        self::assertSame(200, $doc->status);
        self::assertStringContainsString('พนักงานตัวอย่าง (DEMO)', $doc->body);
        self::assertSame(404, $this->browser('manager.demo1')->get('/training/c/' . $cert['public_id'])->status, 'not someone else\'s');
        $hr->get('/training/admin/s/' . $s1);
        $hr->post('/training/admin/cert/' . $cert['public_id'] . '/revoke', ['reason' => 'แก้ชื่อหลักสูตร', 'reissue' => '1']);
        $all = $db->all('SELECT status, version_no, cert_no FROM trn_certificates WHERE enrollment_id = ? ORDER BY id', [$cert['enrollment_id']]);
        self::assertSame(['revoked', 'valid'], array_column($all, 'status'));
        self::assertSame($all[0]['cert_no'], $all[1]['cert_no'], 'same number, new version');
        $hr->get('/training/admin/s/' . $s1);
        $hr->post('/training/admin/s/' . $s1 . '/status', ['to' => 'completed']);
        self::assertSame('completed', $db->scalar('SELECT status FROM trn_sessions WHERE public_id = ?', [$s1]));

        // matrix: who needs SAFE-01 and where they stand
        $m = $hr->get('/training/admin/matrix');
        self::assertSame(200, $m->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($m->body));
        $matrix = TrainingService::of($app)->matrix($le);
        $state = [];
        foreach ($matrix['rows'] as $row) {
            $state[(string) $row['employee']['employee_code']] = array_values(array_filter($row['cells']))[0] ?? '';
        }
        self::assertSame(['valid', 'missing'], [$state['D1-0004'], $state['D1-0005']]);
        self::assertSame('booked', $state['D1-0006'], 'on the waiting list');
        self::assertArrayNotHasKey('D1-0003', $state, 'not a position that needs it');

        // expiry reminder (30 days)
        $db->run("UPDATE trn_certificates SET expires_on = '2026-11-30' WHERE status = 'valid'");
        TrainingService::remindersJob($app);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'training.expiry'", [(int) $this->t->user('employee.demo1')['id']]));

        // approval course: prerequisite now passed → pending → the line manager approves → enrolled
        $s4 = $this->newSession($hr, $lePid, ['course' => $lead] + $this->session($lead, '2026-11-20', '09:00', '16:00', '5'));
        $emp->get('/training/s/' . $s4);
        $emp->post('/training/s/' . $s4 . '/enroll', []);
        self::assertSame('pending', $st('D1-0004', $s4));
        $e4 = (string) $db->scalar("SELECT e.public_id FROM trn_enrollments e JOIN trn_sessions s ON s.id = e.session_id WHERE s.public_id = ? LIMIT 1", [$s4]);
        $inst = (string) $db->scalar("SELECT public_id FROM workflow_instances WHERE subject_id = ?", [$e4]);
        $task = (int) $db->scalar("SELECT k.assignee_id FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id WHERE i.public_id = ? AND k.status = 'open'", [$inst]);
        self::assertSame($mgrUid, $task, 'the line manager');
        $app->workflow()->decide($inst, $task, 'approve');
        self::assertSame('enrolled', $st('D1-0004', $s4));
        $mine = $emp->get('/training');
        self::assertStringContainsString('ภาวะผู้นำ', $mine->body);
        self::assertStringContainsString('TRN-2026-', $mine->body, 'my certificate listed');
        // prerequisite refused for someone who failed
        $hr->get('/training/admin/s/' . $s4);
        $hr->post('/training/admin/s/' . $s4 . '/enroll', ['codes' => 'D1-0005']);
        self::assertNull($st('D1-0005', $s4), 'prerequisite not passed');
        $p = $hr->get('/training/admin/e/' . $d5);
        self::assertSame(200, $p->status);
        self::assertStringContainsString('ความปลอดภัยในการทำงาน', $p->body);
    }
}
