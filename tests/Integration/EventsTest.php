<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Modules\Events\EventService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9D-2 (DEC-98): M50 events. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). DEMO1 employees with accounts:
 * employee.demo1, manager.demo1, hr.demo1, hr.head1. ga.demo1 (WPA) organises and runs the desk.
 */
final class EventsTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(events|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function ev(): EventService
    {
        return EventService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function code(string $user): string
    {
        return (string) $this->t->app->db()->scalar('SELECT e.employee_code FROM employees e JOIN users u ON u.employee_id = e.id WHERE u.username = ?', [$user]);
    }

    private function name(string $user): string
    {
        return (string) $this->t->app->db()->scalar('SELECT e.name_th FROM employees e JOIN users u ON u.employee_id = e.id WHERE u.username = ?', [$user]);
    }

    /** @param array<string, mixed> $over */
    private function create(array $over = []): string
    {
        return $this->ev()->save($this->az('ga.demo1'), $this->le(), $over + ['title' => 'วิ่งมินิมาราธอน', 'kind' => 'งานวิ่ง', 'venue' => 'สวนลุม', 'starts_at' => '2026-11-12T08:00', 'ends_at' => '2026-11-12T12:00',
            'reg_until' => '2026-11-11T17:00', 'quota' => '2', 'waitlist' => '1', 'walk_in' => '1', 'option_label' => 'ไซซ์เสื้อ', 'options' => "S\nM"]);
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

    public function testRegistrationWaitlistDeskWalkInsAndReport(): void
    {
        $s = $this->ev();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $emp = $this->az('employee.demo1');
        $mgr = $this->az('manager.demo1');
        $head = $this->az('hr.head1');
        $hr = $this->az('hr.demo1');
        // G9D2-09: validation
        $e = $this->errors(fn () => $this->create(['title' => 'x', 'starts_at' => '2026-11-09T08:00', 'reg_until' => '2026-11-20T00:00', 'quota' => '', 'options' => 'S']));
        self::assertSame(['events.error.title', 'events.error.past', 'events.error.reg_until', 'events.error.waitlist_quota', 'events.error.options'], [$e['title'], $e['starts_at'], $e['reg_until'], $e['quota'], $e['options']]);
        self::assertSame('events.error.too_long_window', $this->errors(fn () => $this->create(['ends_at' => '2026-12-01T08:00']))['starts_at']);
        self::assertSame('http403', $this->refused(fn () => $s->save($emp, $this->le(), [])));
        $pid = $this->create();
        self::assertSame('http404', $this->refused(fn () => $s->event($emp, $pid)), 'a draft is not visible');
        $s->transition($ga, $pid, 'published');
        self::assertGreaterThanOrEqual(4, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'events.event'"));
        // G9D2-10: registration — choice required, quota 2, then the waitlist in order; cancelling moves the first up
        self::assertSame('events.error.choice', $this->errors(fn () => $s->register($emp, $pid, ''))['choice']);
        self::assertSame('registered', $s->register($emp, $pid, 'M'));
        self::assertSame('events.error.already_registered', $this->refused(fn () => $s->register($emp, $pid, 'M')));
        self::assertSame('registered', $s->register($mgr, $pid, 'S'));
        self::assertSame('full', $s->event($head, $pid)['phase']);
        self::assertSame('waitlisted', $s->register($head, $pid, 'M'));
        self::assertSame('waitlisted', $s->register($hr, $pid, 'S'));
        self::assertSame(2, $s->event($hr, $pid)['position']);
        $s->cancelMine($emp, $pid);
        self::assertSame('registered', (string) $db->scalar('SELECT r.status FROM ev_regs r JOIN users u ON u.employee_id = r.employee_id WHERE u.username = ?', ['hr.head1']));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title_key = 'events.notify.promoted'", [$this->t->user('hr.head1')['id']]));
        self::assertSame(1, $s->event($hr, $pid)['position']);
        // G9D2-11: the day before → one reminder each for the two registered; registration closes
        $this->t->clock->advance('+2 days');
        $s->tick();
        $s->tick();
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE title_key = 'events.notify.reminder'"));
        $this->t->clock->advance('+9 hours');
        self::assertSame('events.error.registration_closed', $this->refused(fn () => $s->register($emp, $pid, 'M')));
        // G9D2-12: desk opens 2 hours before the start (Thu 06:00)
        self::assertSame('not_open', $s->checkIn($ga, $pid, $this->code('hr.head1'))['result']);
        $this->t->clock->advance('+12 hours');
        self::assertSame('http403', $this->refused(fn () => $s->checkIn($emp, $pid, $this->code('hr.head1'))));
        // an RFID card the system does not know → pair it once, then it checks in by itself
        self::assertSame('unknown_card', $s->checkIn($ga, $pid, '04 A1 B2 C3')['result']);
        self::assertSame(['result' => 'ok', 'name' => $this->name('hr.head1')], $s->checkIn($ga, $pid, '04 A1 B2 C3', $this->code('hr.head1')));
        self::assertSame('already', $s->checkIn($ga, $pid, '04a1b2c3')['result']);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM ev_cards'));
        self::assertSame(64, strlen((string) $db->scalar('SELECT card_hash FROM ev_cards')));
        // walk-ins: the waitlisted hr.demo1 (by name from the list) and the cancelled employee.demo1 (by code)
        self::assertSame('walk_in', $s->checkIn($ga, $pid, $this->code('hr.demo1') . ' — ' . $this->name('hr.demo1'))['result']);
        self::assertSame('walk_in', $s->checkIn($ga, $pid, $this->code('employee.demo1'))['result']);
        self::assertSame('unknown', $s->checkIn($ga, $pid, 'ไม่มีใคร')['result']);
        self::assertSame('card', (string) $db->scalar('SELECT r.check_method FROM ev_regs r JOIN users u ON u.employee_id = r.employee_id WHERE u.username = ?', ['hr.head1']));
        // G9D2-13: report after the start — registered ahead 2, came 1, walk-ins 2, no-show 1 (manager), rate 50%
        $this->t->clock->advance('+3 hours');
        $rep = $s->report($ga, $pid);
        $c = $rep['counts'];
        self::assertSame([2, 1, 2, 1, 50.0], [$c['expected'], $c['came'], $c['walk_in'], $c['no_show'], $c['rate']]);
        self::assertSame(['M' => 2, 'S' => 2], $rep['choice_counts']);
        $csv = $s->exportCsv($ga, $pid);
        self::assertCount(5, array_filter(explode("\n", trim($csv))));
        self::assertStringContainsString('ไม่มา', $csv);
        $this->t->clock->advance('+3 hours'); // Thu 12:00 — the end
        $s->tick();
        self::assertSame('completed', (string) $db->scalar('SELECT status FROM ev_events WHERE public_id = ?', [$pid]));
        self::assertSame('not_open', $s->checkIn($ga, $pid, $this->code('manager.demo1'))['result']);
    }

    public function testNoWalkInsCancellationAndScreens(): void
    {
        $s = $this->ev();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $emp = $this->az('employee.demo1');
        $pid = $this->create(['title' => 'สัมมนาความปลอดภัย', 'walk_in' => '', 'quota' => '', 'waitlist' => '', 'option_label' => '', 'options' => '', 'starts_at' => '2026-11-09T10:00', 'ends_at' => '2026-11-09T12:00', 'reg_until' => '2026-11-09T09:30']);
        $s->transition($ga, $pid, 'published');
        $s->register($this->az('manager.demo1'), $pid, '');
        // G9D2-14: no walk-ins → someone not registered is refused; the registered one checks in
        self::assertSame('no_walk_in', $s->checkIn($ga, $pid, $this->code('employee.demo1'))['result']);
        self::assertSame('ok', $s->checkIn($ga, $pid, $this->code('manager.demo1'))['result']);
        // G9D2-15: cancel with a reason → the registered are told
        $p2 = $this->create(['title' => 'แข่งฟุตบอลภายใน']);
        $s->transition($ga, $p2, 'published');
        $s->register($emp, $p2, 'S');
        self::assertSame('events.error.reason', $this->errors(fn () => $s->transition($ga, $p2, 'cancelled', ''))['reason']);
        $s->transition($ga, $p2, 'cancelled', 'สนามปิดปรับปรุง');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title_key = 'events.notify.cancelled'", [$this->t->user('employee.demo1')['id']]));
        self::assertSame('events.error.registration_closed', $this->refused(fn () => $s->register($this->az('hr.head1'), $p2, 'S')));
        self::assertSame('events.error.not_draft', $this->refused(fn () => $s->save($ga, $this->le(), ['title' => 'x'], $p2)));
        // screens
        $g = $this->t->browser();
        $g->login('ga.demo1');
        $draft = $this->create(['title' => 'ร่างกิจกรรมปีใหม่']);
        foreach (['/events/manage', '/events/manage/new', '/events/m/' . $pid, '/events/m/' . $p2, '/events/m/' . $draft, '/events/m/' . $pid . '/desk', '/events/m/' . $pid . '/report', '/events/e/' . $pid] as $path) {
            $res = $g->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        $desk = $g->post('/events/m/' . $pid . '/desk', ['who' => $this->code('employee.demo1')]);
        self::assertSame(200, $desk->status);
        self::assertStringContainsString('role="status"', $desk->body);
        self::assertSame(200, $g->get('/events/m/' . $pid . '/export')->status);
        self::assertSame(422, $g->post('/events/manage/new', ['title' => ''])->status);
        self::assertSame(303, $g->post('/events/m/' . $draft . '/publish')->status);
        $e1 = $this->t->browser();
        $e1->login('employee.demo1');
        foreach (['/events', '/events/e/' . $draft] as $path) {
            $res = $e1->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertSame(422, $e1->post('/events/e/' . $draft . '/join', ['choice' => ''])->status);
        self::assertSame(303, $e1->post('/events/e/' . $draft . '/join', ['choice' => 'S'])->status);
        self::assertSame(303, $e1->post('/events/e/' . $draft . '/leave')->status);
        self::assertSame(403, $e1->get('/events/manage')->status);
        self::assertSame(403, $e1->get('/events/m/' . $pid . '/desk')->status);
    }
}
