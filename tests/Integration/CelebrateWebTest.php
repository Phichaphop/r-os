<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Modules\Celebrate\CelebrateService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5E-2 (DEC-77): M45 birthdays & work anniversaries — off until turned on with a notice, day-month only, once per event, opt-out. */
final class CelebrateWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(celebrate|employees|common|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    private function birth(string $code, string $date): void
    {
        $this->t->app->db()->run('UPDATE employees SET birth_date_enc = ? WHERE employee_code = ?', [$this->t->app->crypto()->encrypt($date), $code]);
    }

    public function testLeapDay(): void
    {
        self::assertSame('2027-02-28', CelebrateService::occurrence(2027, '02-29'));
        self::assertSame('2028-02-29', CelebrateService::occurrence(2028, '02-29'));
        self::assertSame('2027-11-09', CelebrateService::occurrence(2027, '11-09'));
    }

    public function testBoardNotificationsAndPrivacy(): void
    {
        $db = $this->t->app->db();
        $app = $this->t->app;
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $this->birth('D1-0004', '1995-11-09');   // today (clock 2026-11-09)
        $this->birth('D1-0005', '1990-11-20');   // later this month
        $this->birth('D1-0006', '1992-03-15');   // another month
        $db->run("UPDATE employees SET hire_date = '2016-11-09' WHERE employee_code = 'D1-0003'"); // 10 years today

        // off by default: nothing calculated or shown
        self::assertSame(0, CelebrateService::job($app));
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM cel_events'));
        $emp = $this->browser('employee.demo1');
        $off = $emp->get('/celebrate');
        self::assertSame(200, $off->status, $this->errorsLogged());
        self::assertStringContainsString('ยังไม่เปิดแสดง', $off->body);

        // turning on needs the company's notice
        $hr = $this->browser('hr.demo1');
        $admin = $hr->get('/celebrate/admin');
        self::assertSame(200, $admin->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($admin->body));
        self::assertSame(422, $hr->post('/celebrate/admin', ['le' => $lePid, 'birthdays_enabled' => '1', 'notice_text' => ''])->status);
        $hr->get('/celebrate/admin');
        self::assertSame(303, $hr->post('/celebrate/admin', ['le' => $lePid, 'birthdays_enabled' => '1', 'anniversaries_enabled' => '1', 'notify_manager' => '1', 'notify_hr' => '1',
            'board_scope' => 'company', 'milestones' => '5, 10, 20', 'notice_text' => '(ตัวอย่าง DEMO) บริษัทแสดงวัน-เดือนเกิดและครบรอบงานเพื่อร่วมแสดงความยินดี พนักงานซ่อนของตนเองได้ที่หน้า "ของฉัน"'])->status);
        self::assertSame(1, (int) $db->scalar('SELECT notice_version FROM cel_settings WHERE legal_entity_id = ?', [$le]));
        $events = $db->all('SELECT c.*, e.employee_code FROM cel_events c JOIN employees e ON e.id = c.employee_id ORDER BY c.event_date');
        $codes = array_map(static fn (array $x): string => $x['employee_code'] . ':' . $x['kind'] . ':' . $x['event_date'], $events);
        self::assertContains('D1-0004:birthday:2026-11-09', $codes);
        self::assertContains('D1-0005:birthday:2026-11-20', $codes);
        self::assertContains('D1-0003:anniversary:2026-11-09', $codes);
        self::assertNotContains('D1-0006:birthday:2027-03-15', $codes, 'outside the window');
        self::assertSame(10, (int) $db->scalar("SELECT c.years FROM cel_events c JOIN employees e ON e.id = c.employee_id WHERE e.employee_code = 'D1-0003' AND c.kind = 'anniversary'"));

        // the hourly job sends today's events once (a retry sends nothing new)
        CelebrateService::job($app);
        $empUid = (int) $this->t->user('employee.demo1')['id'];
        $mgrUid = (int) $this->t->user('manager.demo1')['id'];
        $hrUid = (int) $this->t->user('hr.demo1')['id'];
        $count = static fn (int $uid, string $type): int => (int) $db->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = ?', [$uid, $type]);
        self::assertSame(1, $count($empUid, 'celebrate.greeting'), 'the person gets a greeting');
        self::assertSame(1, $count($mgrUid, 'celebrate.greeting'), 'the manager gets their own anniversary greeting');
        self::assertGreaterThanOrEqual(1, $count($mgrUid, 'celebrate.note'), 'the line manager is told');
        self::assertSame(2, $count($hrUid, 'celebrate.note'), 'HR is told of both');
        CelebrateService::job($app);
        self::assertSame(1, $count($empUid, 'celebrate.greeting'));
        self::assertSame(2, $count($hrUid, 'celebrate.note'));
        self::assertSame('scheduled', $db->scalar("SELECT c.status FROM cel_events c JOIN employees e ON e.id = c.employee_id WHERE e.employee_code = 'D1-0005'"), 'later this month');

        // the board: day and month only — never the birth year
        $board = $hr->get('/celebrate');
        self::assertSame(200, $board->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($board->body));
        self::assertStringContainsString('พนักงานตัวอย่าง (DEMO)', $board->body);
        self::assertStringContainsString('พนักงานขายสอง (DEMO)', $board->body);
        self::assertStringContainsString('ครบ 10 ปี', $board->body);
        foreach (['1995', '1990', '2538', '2533'] as $year) {
            self::assertStringNotContainsString($year, strip_tags($board->body), 'no birth year');
        }
        self::assertStringNotContainsString('พนักงานขายสาม', $board->body, 'not this month');
        self::assertSame(200, $hr->get('/celebrate', ['m' => '2026-12'])->status, 'other months can be browsed');

        // one wish per person per event, never to oneself
        $ev = (string) $db->scalar("SELECT c.public_id FROM cel_events c JOIN employees e ON e.id = c.employee_id WHERE e.employee_code = 'D1-0004' AND c.kind = 'birthday'");
        $hr->get('/celebrate');
        self::assertSame(303, $hr->post('/celebrate/e/' . $ev . '/wish', ['message' => 'สุขสันต์วันเกิดนะ'])->status);
        $hr->get('/celebrate');
        $hr->post('/celebrate/e/' . $ev . '/wish', ['message' => 'อีกครั้ง']);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM cel_wishes'));
        self::assertSame(1, $count($empUid, 'celebrate.wish'));
        $emp->get('/celebrate');
        $emp->post('/celebrate/e/' . $ev . '/wish', ['message' => 'ถึงตัวเอง']);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM cel_wishes'), 'not to oneself');
        $me = $emp->get('/celebrate/me');
        self::assertSame(200, $me->status);
        self::assertStringContainsString('สุขสันต์วันเกิดนะ', $me->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($me->body));

        // opt-out: hidden from the board, open events cancelled, wishes refused
        $ev5 = (string) $db->scalar("SELECT c.public_id FROM cel_events c JOIN employees e ON e.id = c.employee_id WHERE e.employee_code = 'D1-0005'");
        $emp->get('/celebrate/me');
        $emp->post('/celebrate/me', ['hide' => ['birthday']]);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM cel_optouts'));
        $hrAz = new \Blue\Core\Authz\Authorizer($db, $this->t->clock, $hrUid);
        self::assertNotContains($ev, array_column(CelebrateService::of($app)->board($hrAz)['birthdays'], 'public_id'), 'hidden from the board');
        $emp->get('/celebrate/me');
        $emp->post('/celebrate/me', []);
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM cel_optouts'), 'shown again');

        // HR cancels an event with a reason; a former employee is never calculated
        $hr->get('/celebrate/admin');
        $hr->post('/celebrate/admin/e/' . $ev5 . '/cancel', ['reason' => 'ครอบครัวมีเหตุไม่คาดคิด']);
        self::assertSame('cancelled', $db->scalar('SELECT status FROM cel_events WHERE public_id = ?', [$ev5]));
        $db->run("UPDATE employees SET status = 'former' WHERE employee_code = 'D1-0006'");
        $db->run("UPDATE employees SET hire_date = '2021-11-25' WHERE employee_code = 'D1-0006'");
        CelebrateService::of($app)->generate($le);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM cel_events c JOIN employees e ON e.id = c.employee_id WHERE e.employee_code = 'D1-0006'"));
        // turning off: the board says so and the job sends nothing
        $hr->get('/celebrate/admin');
        $hr->post('/celebrate/admin', ['le' => $lePid, 'notice_text' => '(ตัวอย่าง DEMO) บริษัทแสดงวัน-เดือนเกิดและครบรอบงานเพื่อร่วมแสดงความยินดี']);
        self::assertStringContainsString('ยังไม่เปิดแสดง', $emp->get('/celebrate')->body);
        self::assertSame(403, $emp->get('/celebrate/admin')->status);
    }
}
