<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Modules\Helpdesk\HelpdeskPersonalData;
use Blue\Modules\Helpdesk\HelpdeskService;
use Blue\Modules\Inspection\InspectionService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9B-1 (DEC-91): M15 helpdesk and M18 inspections. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok); DEMO1 work
 * calendar Mon–Fri. Demo (examples): categories "คอมพิวเตอร์และระบบ" (respond 4 h · low 40 · normal 16 · high 8 · urgent 4)
 * and "อาคารและไฟฟ้า" (respond 8 · normal 24 · high 8 · urgent 4), lead ga.demo1, technician tech.demo1, policy reopen 7
 * days / auto-close 5 days; points FE-HQ-01 (monthly, fire extinguisher, no ticket category) and SRV-HQ (weekly, server
 * room, temperature 18–27, failures → IT category, high), both from 2026-11-09, inspector tech.demo1.
 */
final class HelpdeskInspectionTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(helpdesk|inspection|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    private function uid(string $user): int
    {
        return (int) $this->t->user($user)['id'];
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function cat(string $prefix): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM hd_categories WHERE name LIKE ?', [$prefix . '%']);
    }

    private function file(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fromBytes($name, $bytes, $this->t->tmp);
    }

    /** @return array<string, mixed> */
    private function tk(string $pid): array
    {
        return $this->t->app->db()->one('SELECT * FROM hd_tickets WHERE public_id = ?', [$pid]) ?? [];
    }

    /** @return array<string, mixed> */
    private function rd(string $pid): array
    {
        return $this->t->app->db()->one('SELECT * FROM in_rounds WHERE public_id = ?', [$pid]) ?? [];
    }

    private function notes(string $user, string $type): int
    {
        return (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = ?', [$this->uid($user), $type]);
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

    private function page(string $user, string $path, int $status = 200): string
    {
        $q = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $q);
        }
        $b = $this->t->browser();
        $b->login($user);
        $r = $b->get($path, $q);
        self::assertSame($status, $r->status, $path . ' as ' . $user);
        if ($status === 200 && str_contains($r->headers['Content-Type'] ?? '', 'text/html')) {
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($r->body), $path);
        }
        return $r->body;
    }

    public function testHelpdesk(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $hd = HelpdeskService::of($app);
        $le = $this->le();
        $emp = $this->az('employee.demo1');
        $ga = $this->az('ga.demo1');
        $tech = $this->az('tech.demo1');
        $it = $this->cat('คอมพิวเตอร์');
        $bld = $this->cat('อาคาร');
        $base = ['category' => $it, 'priority' => 'normal', 'title' => 'เครื่องพิมพ์ชั้น 2 กระดาษติด', 'description' => 'พิมพ์ไม่ออกตั้งแต่เช้า', 'location' => 'ชั้น 2'];

        // ---- open: validation, a dangerous file, SLA deadlines on working days ---------------------------------------------
        $e = $this->errors(fn () => $hd->open($emp, $le, ['category' => '', 'priority' => 'x', 'title' => 'a', 'description' => '']));
        self::assertSame(['helpdesk.error.category', 'helpdesk.error.priority', 'helpdesk.error.title', 'helpdesk.error.description'], [$e['category'], $e['priority'], $e['title'], $e['description']]);
        self::assertArrayHasKey('files', $this->errors(fn () => $hd->open($emp, $le, $base, [$this->file('photo.png', "MZ\x90\x00 this is a program")])));
        $t1 = $hd->open($emp, $le, $base, [$this->file('printer.png', (string) base64_decode(self::PNG))]);
        $r = $this->tk($t1);
        self::assertSame(['open', '2026-11-09 09:00:00', '2026-11-09 13:00:00', '2026-11-10 01:00:00'], [$r['status'], $r['opened_at'], $r['response_due'], $r['resolve_due']]);
        self::assertMatchesRegularExpression('/^HD2611-\d{4}$/', (string) $r['ref']);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM hd_files WHERE ticket_id = ?', [$r['id']]));
        self::assertSame(1, $this->notes('ga.demo1', 'helpdesk.new'));
        // who may open it
        self::assertSame('lead', $hd->ticket($ga, $t1)['role']);
        self::assertSame('agent', $hd->ticket($tech, $t1)['role']);
        foreach (['manager.demo1', 'hr.demo2', 'driver.demo1'] as $u) {
            self::assertSame('http404', $this->refused(fn () => $hd->ticket($this->az($u), $t1)), $u);
        }

        // ---- assign, talk, internal note, waiting for the requester ------------------------------------------------------
        self::assertSame('helpdesk.error.cannot', $this->refused(fn () => $hd->assign($tech, $t1, ['assignee' => (string) $this->uid('tech.demo1')])), 'technicians do not assign');
        self::assertSame('helpdesk.error.assignee', $this->errors(fn () => $hd->assign($ga, $t1, ['assignee' => (string) $this->uid('employee.demo1')]))['assignee']);
        self::assertSame('helpdesk.error.due', $this->errors(fn () => $hd->assign($ga, $t1, ['assignee' => (string) $this->uid('tech.demo1'), 'due' => '2026-11-08T10:00']))['due']);
        $hd->assign($ga, $t1, ['assignee' => (string) $this->uid('tech.demo1'), 'due' => '2026-11-09T16:00']);
        self::assertSame(['assigned', '2026-11-09 16:00:00'], [$this->tk($t1)['status'], $this->tk($t1)['due_at']]);
        self::assertSame([1, 1], [$this->notes('tech.demo1', 'helpdesk.assigned'), $this->notes('employee.demo1', 'helpdesk.update')]);
        self::assertSame('helpdesk.error.reason', $this->errors(fn () => $hd->assign($ga, $t1, ['assignee' => (string) $this->uid('ga.demo1')]))['reason'], 'a change of person needs a reason');
        $this->t->clock->advance('+30 minutes');
        $hd->message($tech, $t1, 'กำลังไปตรวจครับ', [], false);
        self::assertSame('2026-11-09 09:30:00', $this->tk($t1)['first_response_at']);
        $hd->message($tech, $t1, 'ลูกยางดึงกระดาษสึก ต้องสั่งอะไหล่', [$this->file('part.png', (string) base64_decode(self::PNG))], true);
        $seen = $hd->ticket($emp, $t1);
        self::assertCount(1, $seen['messages'], 'the requester does not see work notes');
        self::assertCount(1, $seen['files'], 'nor their files');
        self::assertCount(2, $hd->ticket($tech, $t1)['messages']);
        $internal = (int) $db->scalar('SELECT id FROM hd_files WHERE ticket_id = ? AND internal = 1', [$this->tk($t1)['id']]);
        self::assertSame('http404', $this->refused(fn () => $hd->file($emp, $t1, $internal)));
        self::assertNotSame('', $hd->file($tech, $t1, $internal)['bytes']);
        $hd->setStatus($tech, $t1, 'waiting_user', 'ขอทราบรุ่นเครื่อง');
        $hd->message($emp, $t1, 'รุ่น LaserJet 400', [], false);
        self::assertSame('in_progress', $this->tk($t1)['status'], 'a reply puts it back in progress');

        // ---- resolve, reopen, resolve, confirm with a rating -------------------------------------------------------------
        self::assertSame('helpdesk.error.resolution', $this->errors(fn () => $hd->resolve($tech, $t1, 'ok'))['resolution']);
        $hd->resolve($tech, $t1, 'เปลี่ยนลูกยางดึงกระดาษแล้ว');
        self::assertSame(1, $this->notes('employee.demo1', 'helpdesk.resolved'));
        self::assertSame('helpdesk.error.reason', $this->errors(fn () => $hd->reopen($emp, $t1, ''))['reopen_reason']);
        $hd->reopen($emp, $t1, 'ยังติดอยู่บ้าง');
        self::assertSame(['in_progress', '1'], [$this->tk($t1)['status'], (string) $this->tk($t1)['reopen_count']]);
        $hd->resolve($tech, $t1, 'ทำความสะอาดลูกกลิ้งเพิ่ม');
        self::assertSame('helpdesk.error.rating', $this->errors(fn () => $hd->confirm($emp, $t1, '6', ''))['rating']);
        $hd->confirm($emp, $t1, '4', 'เร็วดี');
        $r = $this->tk($t1);
        self::assertSame(['closed', 'confirmed', '4'], [$r['status'], $r['close_kind'], (string) $r['rating']]);
        self::assertSame('helpdesk.error.cannot', $this->refused(fn () => $hd->message($emp, $t1, 'อีกเรื่อง', [], false)));

        // ---- unanswered → closed by the system after 5 days → no new ticket until rated -------------------------------------
        $t2 = $hd->open($emp, $le, ['title' => 'จอกะพริบ'] + $base);
        $hd->assign($ga, $t2, ['assignee' => (string) $this->uid('tech.demo1')]);
        $hd->resolve($tech, $t2, 'เปลี่ยนสายจอ');
        $this->t->clock->advance('+5 days');
        $this->t->clock->advance('+1 hour');
        self::assertGreaterThanOrEqual(1, $hd->tick());
        self::assertSame(['closed', 'auto', null], [$this->tk($t2)['status'], $this->tk($t2)['close_kind'], $this->tk($t2)['rating']]);
        self::assertSame('helpdesk.error.rate_first', $this->refused(fn () => $hd->open($emp, $le, $base)));
        $hd->rate($emp, $t2, '3', '');
        $t3 = $hd->open($emp, $le, ['priority' => 'high', 'title' => 'อินเทอร์เน็ตช้า'] + $base);

        // ---- SLA across a weekend, breach escalated once; urgency change; transfer; cancel -------------------------------
        // now Sat 2026-11-14 10:00 → high 8 working hours: Sat/Sun do not count → Mon 08:00
        self::assertSame('2026-11-16 08:00:00', $this->tk($t3)['resolve_due']);
        $hd->assign($ga, $t3, ['assignee' => (string) $this->uid('tech.demo1')]);
        $this->t->clock->advance('+2 days'); // Mon 10:00
        $hd->tick();
        self::assertSame('1', (string) $this->tk($t3)['sla_breached']);
        self::assertSame([1, 1], [$this->notes('ga.demo1', 'helpdesk.sla'), $this->notes('tech.demo1', 'helpdesk.sla')]);
        $hd->tick();
        self::assertSame(1, $this->notes('ga.demo1', 'helpdesk.sla'), 'escalated once');
        self::assertSame('helpdesk.error.reason', $this->errors(fn () => $hd->setPriority($ga, $t3, 'urgent', ''))['priority_reason']);
        $hd->setPriority($ga, $t3, 'urgent', 'ทั้งชั้นใช้งานไม่ได้');
        self::assertSame(['urgent', '2026-11-16 04:00:00'], [$this->tk($t3)['priority'], $this->tk($t3)['resolve_due']], 'counted again from the opening: Sat 10:00, the weekend skipped, Mon 00:00 + 4 h');
        $hd->transfer($ga, $t3, $bld, 'เป็นปัญหาสายไฟในอาคาร');
        $r = $this->tk($t3);
        self::assertSame(['open', null], [$r['status'], $r['assignee_id']]);
        self::assertSame((string) $db->scalar('SELECT id FROM hd_categories WHERE public_id = ?', [$bld]), (string) $r['category_id']);
        self::assertSame('helpdesk.error.reason', $this->errors(fn () => $hd->cancel($ga, $t3, ''))['cancel_reason'], 'a lead cancels with a reason');
        $hd->cancel($emp, $t3, '');
        self::assertSame(['cancelled', 'cancelled'], [$this->tk($t3)['status'], $this->tk($t3)['close_kind']]);
        $t4 = $hd->open($emp, $le, $base);
        self::assertSame('open', $this->tk($t4)['status'], 'a cancelled ticket asks no rating');

        // ---- admin ------------------------------------------------------------------------------------------------------
        self::assertSame('helpdesk.error.name_taken', $this->errors(fn () => $hd->saveCategory($ga, $le, ['name' => 'อาคารและไฟฟ้า (DEMO)']))['name']);
        self::assertSame('helpdesk.error.hours', $this->errors(fn () => $hd->saveCategory($ga, $le, ['name' => 'ยานพาหนะ', 'hours_normal' => '0']))['hours_normal']);
        $c = $hd->saveCategory($ga, $le, ['name' => 'ยานพาหนะ', 'hours_normal' => '24']);
        self::assertSame('helpdesk.error.member', $this->errors(fn () => $hd->addMember($ga, $c, 'nobody.here', 'lead'))['member']);
        $hd->addMember($ga, $c, 'D1-0004', 'agent');
        self::assertSame('agent', $hd->roleIn($this->uid('employee.demo1'), (int) $db->scalar('SELECT id FROM hd_categories WHERE public_id = ?', [$c])));
        $hd->removeMember($ga, $c, $this->uid('employee.demo1'));
        self::assertSame('helpdesk.error.days', $this->errors(fn () => $hd->savePolicy($ga, $le, ['reopen_days' => '0']))['reopen_days']);
        self::assertSame('http403', $this->refused(fn () => $hd->saveCategory($emp, $le, ['name' => 'x'])));

        // ---- report ---------------------------------------------------------------------------------------------------------
        $rep = $hd->report($ga, $le, '2026-11');
        $itRow = array_values(array_filter($rep['categories'], static fn (array $x): bool => str_starts_with((string) $x['name'], 'คอมพิวเตอร์')))[0];
        self::assertSame([3, 2, 1, '3.50'], [(int) $itRow['tickets'], (int) $itRow['resolved'], (int) $itRow['reopened'], (string) $itRow['avg_rating']]);
        self::assertStringStartsWith('PK', $hd->export($ga, $le, '2026-11')['bytes']);

        // ---- screens ----------------------------------------------------------------------------------------------------------
        $this->page('employee.demo1', '/helpdesk');
        $this->page('employee.demo1', '/helpdesk/new');
        $body = $this->page('employee.demo1', '/helpdesk/t/' . $t1);
        self::assertStringNotContainsString('ลูกยางดึงกระดาษสึก', $body, 'work note hidden');
        self::assertStringContainsString('9 พ.ย. 2569 09:00', $body, 'opened at local time, not shifted again');
        self::assertStringContainsString('ลูกยางดึงกระดาษสึก', $this->page('tech.demo1', '/helpdesk/t/' . $t1));
        $this->page('tech.demo1', '/helpdesk/queue');
        $this->page('ga.demo1', '/helpdesk/t/' . $t4);
        $this->page('ga.demo1', '/helpdesk/admin');
        $this->page('ga.demo1', '/helpdesk/report?month=2026-11');
        $this->page('ga.demo1', '/helpdesk/report/export?month=2026-11');
        $this->page('employee.demo1', '/helpdesk/queue', 403);
        $this->page('employee.demo1', '/helpdesk/admin', 403);
        $this->page('manager.demo1', '/helpdesk/t/' . $t1, 404);
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $res = $b->post(
            '/helpdesk/new',
            ['le' => (string) $db->scalar("SELECT public_id FROM legal_entities WHERE code = 'DEMO1'"), 'category' => $bld, 'priority' => 'low', 'title' => 'หลอดไฟห้องประชุมขาด', 'description' => 'ห้อง R-101 ดวงที่ 2'],
            ['files[]' => ['bulb.png', (string) base64_decode(self::PNG)]]
        );
        self::assertSame(303, $res->status);
        $res = $b->post('/helpdesk/new', ['category' => '', 'title' => '']);
        self::assertSame(422, $res->status);

        // ---- personal data ----------------------------------------------------------------------------------------------------
        $uid = $this->uid('employee.demo1');
        self::assertSame('tickets', HelpdeskPersonalData::export($app, $uid, 4)[0]['key']);
        HelpdeskPersonalData::erase($app, $uid, 4, $this->uid(TestApp::ADMIN), false);
        self::assertSame(['cancelled', '-'], [$this->tk($t4)['status'], $this->tk($t4)['title']]);
        self::assertSame('4', (string) $this->tk($t1)['rating'], 'the rating stays for the report');
        self::assertSame('-', (string) $db->scalar('SELECT body FROM hd_messages WHERE author_id = ? LIMIT 1', [$uid]));
    }

    public function testInspection(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        $in = InspectionService::of($app);
        $hd = HelpdeskService::of($app);
        $le = $this->le();
        $ga = $this->az('ga.demo1');
        $tech = $this->az('tech.demo1');
        $emp = $this->az('employee.demo1');

        // ---- schedule rules and checklist text --------------------------------------------------------------------------------
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], InspectionService::dueDates('monthly', '2026-01-31', '2026-01-01', '2026-03-31'));
        self::assertSame(['2026-11-16', '2026-11-23'], InspectionService::dueDates('weekly', '2026-11-09', '2026-11-10', '2026-11-29'));
        self::assertSame(['2026-11-09', '2026-11-10', '2026-11-11'], InspectionService::dueDates('daily', '2026-11-09', '2026-11-01', '2026-11-11'));
        $items = InspectionService::parseItems("ไฟสถานะปกติ\n? ทำความสะอาด\nอุณหภูมิ | 18 | 27\nความชื้น | | 60");
        self::assertSame([true, false, 'number', 18.0, null, 60.0], [$items[0]['required'], $items[1]['required'], $items[2]['kind'], $items[2]['min'], $items[3]['min'], $items[3]['max']]);
        self::assertSame('อุณหภูมิ | 18 | 27', InspectionService::itemLine($items[2]));
        foreach (["", "อุณหภูมิ | 30 | 20", "อุณหภูมิ | a | b", "x"] as $bad) {
            self::assertArrayHasKey('items', $this->errors(fn () => InspectionService::parseItems($bad)), $bad);
        }

        // ---- rounds of today and the next ones ------------------------------------------------------------------------------
        $in->tick();
        $srv = (int) $db->scalar("SELECT id FROM in_points WHERE code = 'SRV-HQ'");
        self::assertSame(['2026-11-09', '2026-11-16'], array_column($db->all('SELECT due_date FROM in_rounds WHERE point_id = ? ORDER BY due_date', [$srv]), 'due_date'));
        $mine = $in->mine($tech);
        self::assertCount(4, $mine, 'two today + the next of each point');
        $r1 = (string) $db->scalar("SELECT public_id FROM in_rounds WHERE point_id = ? AND due_date = '2026-11-09'", [$srv]);
        $token = (string) $db->scalar('SELECT qr_token FROM in_points WHERE id = ?', [$srv]);
        self::assertSame($r1, $in->byQr($tech, $token), 'the QR opens today\'s round');
        self::assertSame('http404', $this->refused(fn () => $in->byQr($emp, $token)), 'not the inspector');
        self::assertSame('http404', $this->refused(fn () => $in->round($emp, $r1)));
        $next = (string) $db->scalar("SELECT public_id FROM in_rounds WHERE point_id = ? AND due_date = '2026-11-16'", [$srv]);
        self::assertSame('inspection.error.cannot', $this->refused(fn () => $in->save($tech, $next, [], [], true)), 'not before its day');

        // ---- answer: draft, required items, out-of-range number fails, a photo, a failure becomes a helpdesk ticket ------------
        $in->save($tech, $r1, ['result_2' => 'pass'], [], false);
        self::assertSame('in_progress', $this->rd($r1)['status']);
        $e = $this->errors(fn () => $in->save($tech, $r1, ['value_1' => '30', 'result_2' => 'pass'], [], true));
        self::assertSame(['inspection.error.fail_note', 'inspection.error.required'], [$e['note_1'], $e['result_3']]);
        $in->save($tech, $r1, ['value_1' => '30', 'note_1' => 'แอร์ตัวที่ 2 ไม่เย็น', 'result_2' => 'pass', 'result_3' => 'pass'], [1 => $this->file('temp.png', (string) base64_decode(self::PNG))], true);
        $r = $this->rd($r1);
        self::assertSame(['submitted', 'fail', '1', '0'], [$r['status'], $r['result'], (string) $r['fail_count'], (string) $r['late']]);
        $act = $db->one('SELECT * FROM in_actions WHERE round_id = ?', [$r['id']]) ?? [];
        self::assertNotNull($act['ticket_pid']);
        $ticket = $this->tk((string) $act['ticket_pid']);
        self::assertSame(['inspection', 'high', 'open', $this->uid('tech.demo1')], [$ticket['source'], $ticket['priority'], $ticket['status'], (int) $ticket['requester_id']]);
        self::assertStringContainsString('30', (string) $ticket['description']);
        self::assertSame(1, $this->notes('ga.demo1', 'inspection.review'));
        $photoId = (int) $db->scalar('SELECT id FROM in_results WHERE round_id = ? AND item_no = 1', [$r['id']]);
        self::assertNotSame('', $in->photo($ga, $r1, $photoId)['bytes']);

        // ---- returned for a new check (answers kept), resubmitted: no second ticket ------------------------------------------
        self::assertSame('inspection.error.reason', $this->errors(fn () => $in->review($ga, $r1, false, ''))['review_note']);
        $in->review($ga, $r1, false, 'วัดใหม่หลังซ่อมแอร์');
        $r = $this->rd($r1);
        self::assertSame(['in_progress', '2'], [$r['status'], (string) $r['revision']]);
        self::assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM in_results WHERE round_id = ? AND revision = 2', [$r['id']]), 'starts from the answers given');
        $in->save($tech, $r1, ['value_1' => '24.5', 'result_2' => 'pass', 'result_3' => 'pass'], [], true);
        self::assertSame(['pass', '0'], [$this->rd($r1)['result'], (string) $this->rd($r1)['fail_count']]);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM in_actions WHERE round_id = ?', [$r['id']]), 'no second action');
        self::assertCount(3, $in->round($ga, $r1)['earlier']);

        // ---- accept: the open ticket keeps the round open; the ticket is fixed → the action is done → close ---------------
        $in->review($ga, $r1, true, '');
        self::assertSame('reviewed', $this->rd($r1)['status']);
        self::assertSame('inspection.error.close', $this->refused(fn () => $in->close($ga, $r1)));
        $hd->assign($ga, (string) $act['ticket_pid'], ['assignee' => (string) $this->uid('tech.demo1')]);
        $hd->resolve($tech, (string) $act['ticket_pid'], 'เติมน้ำยาแอร์ตัวที่ 2');
        self::assertTrue($in->round($ga, $r1)['can_close']);
        $in->close($ga, $r1);
        self::assertSame('closed', $this->rd($r1)['status']);
        self::assertNull($hd->unrated($this->uid('tech.demo1')), 'inspection tickets do not block the inspector');

        // ---- all pass (optional item left empty) → closes on acceptance ----------------------------------------------------
        $fe = (string) $db->scalar("SELECT r.public_id FROM in_rounds r JOIN in_points p ON p.id = r.point_id WHERE p.code = 'FE-HQ-01' AND r.due_date = '2026-11-09'");
        $in->save($tech, $fe, ['result_1' => 'pass', 'result_2' => 'pass', 'result_3' => 'pass', 'result_4' => 'pass'], [], true);
        self::assertSame('na', (string) $db->scalar('SELECT result FROM in_results WHERE round_id = ? AND item_no = 5', [$this->rd($fe)['id']]));
        $in->review($ga, $fe, true, '');
        self::assertSame('closed', $this->rd($fe)['status']);

        // ---- a daily point without a ticket category: overdue, late submission, an action assigned by the admin ------------
        $cl = (string) $db->scalar("SELECT public_id FROM in_checklists WHERE name LIKE 'ตรวจถังดับเพลิง%'");
        self::assertSame('inspection.error.inspector', $this->errors(fn () => $in->savePoint($ga, $le, ['code' => 'LT-01', 'name' => 'ไฟฉุกเฉิน', 'checklist' => $cl, 'frequency' => 'daily', 'start_date' => '2026-11-09', 'inspector' => 'nobody.here']))['inspector']);
        $lt = $in->savePoint($ga, $le, ['code' => 'lt-01', 'name' => 'ไฟฉุกเฉินบันไดหนีไฟ', 'checklist' => $cl, 'frequency' => 'daily', 'start_date' => '2026-11-09', 'inspector' => 'D1-0004']);
        self::assertSame('inspection.error.code_taken', $this->errors(fn () => $in->savePoint($ga, $le, ['code' => 'LT-01', 'name' => 'ซ้ำ', 'checklist' => $cl, 'frequency' => 'daily', 'start_date' => '2026-11-09', 'inspector' => 'D1-0004']))['code']);
        $ltId = (int) $db->scalar('SELECT id FROM in_points WHERE public_id = ?', [$lt]);
        $this->t->clock->advance('+2 days'); // Wed 11
        $in->tick();
        self::assertSame(['overdue', 'overdue', 'scheduled', 'scheduled'], array_column($db->all('SELECT status FROM in_rounds WHERE point_id = ? ORDER BY due_date', [$ltId]), 'status'));
        self::assertSame(2, $this->notes('employee.demo1', 'inspection.overdue'));
        $in->tick();
        self::assertSame(2, $this->notes('employee.demo1', 'inspection.overdue'), 'told once per round');
        $late = (string) $db->scalar("SELECT public_id FROM in_rounds WHERE point_id = ? AND due_date = '2026-11-09'", [$ltId]);
        $in->save($emp, $late, ['result_1' => 'fail', 'note_1' => 'เข็มตก', 'result_2' => 'pass', 'result_3' => 'pass', 'result_4' => 'pass'], [], true);
        self::assertSame('1', (string) $this->rd($late)['late']);
        $act = $db->one('SELECT * FROM in_actions WHERE round_id = ?', [$this->rd($late)['id']]) ?? [];
        self::assertNull($act['ticket_pid']);
        self::assertSame('inspection.error.due', $this->errors(fn () => $in->assignAction($ga, (string) $act['public_id'], ['assignee' => 'tech.demo1', 'due_date' => '2026-11-01']))['due_date']);
        $in->assignAction($ga, (string) $act['public_id'], ['assignee' => 'tech.demo1', 'due_date' => '2026-11-13']);
        self::assertCount(1, $in->myActions($tech));
        self::assertSame('http404', $this->refused(fn () => $in->doneAction($emp, (string) $act['public_id'], 'ok ok')));
        $in->review($ga, $late, true, '');
        $in->doneAction($tech, (string) $act['public_id'], 'เปลี่ยนถังใหม่');
        $in->close($ga, $late);
        self::assertSame('closed', $this->rd($late)['status']);

        // ---- a new checklist version applies from its date; rounds already made keep theirs -----------------------------------
        self::assertSame('inspection.error.effective_from', $this->errors(fn () => $in->saveChecklist($ga, $le, ['name' => 'ตรวจถังดับเพลิง (DEMO)', 'items' => 'a1', 'effective_from' => '2026-11-01'], $cl))['effective_from']);
        $in->saveChecklist($ga, $le, ['name' => 'ตรวจถังดับเพลิง (DEMO)', 'items' => "เข็มแรงดันอยู่ในช่องสีเขียว\nน้ำหนักถัง (กก.) | 6 |", 'effective_from' => '2026-11-13', 'note' => 'เพิ่มชั่งน้ำหนัก'], $cl);
        $this->t->clock->advance('+2 days'); // Fri 13
        $in->tick();
        $v12 = (int) $db->scalar("SELECT v.version_no FROM in_rounds r JOIN in_checklist_versions v ON v.id = r.version_id WHERE r.point_id = ? AND r.due_date = '2026-11-12'", [$ltId]);
        $v13 = (int) $db->scalar("SELECT v.version_no FROM in_rounds r JOIN in_checklist_versions v ON v.id = r.version_id WHERE r.point_id = ? AND r.due_date = '2026-11-13'", [$ltId]);
        self::assertSame([1, 2], [$v12, $v13]);

        // ---- board, report, screens ----------------------------------------------------------------------------------------------
        $board = $in->board($ga, $le);
        self::assertGreaterThanOrEqual(1, count($board['overdue']));
        $rows = $in->report($ga, $le, '2026-11');
        $ltRow = array_values(array_filter($rows, static fn (array $x): bool => $x['code'] === 'LT-01'))[0];
        self::assertSame([1, 0, 1], [(int) $ltRow['late'], (int) $ltRow['on_time'], (int) $ltRow['fails']]);
        self::assertStringStartsWith('PK', $in->export($ga, $le, '2026-11')['bytes']);
        self::assertSame('http403', $this->refused(fn () => $in->report($emp, $le, '2026-11')));
        $this->page('tech.demo1', '/inspection');
        $this->page('tech.demo1', '/inspection/r/' . $next);
        $this->page('employee.demo1', '/inspection/r/' . (string) $db->scalar("SELECT public_id FROM in_rounds WHERE point_id = ? AND due_date = '2026-11-13'", [$ltId]));
        $this->page('ga.demo1', '/inspection/r/' . $r1);
        $this->page('ga.demo1', '/inspection/board');
        $this->page('ga.demo1', '/inspection/admin');
        $this->page('ga.demo1', '/inspection/c/' . $cl);
        $body = $this->page('ga.demo1', '/inspection/p/' . $lt);
        self::assertStringContainsString('<svg', $body);
        $this->page('ga.demo1', '/inspection/p/' . $lt . '/label');
        $this->page('ga.demo1', '/inspection/report?month=2026-11');
        $this->page('ga.demo1', '/inspection/report/export?month=2026-11');
        $this->page('ga.demo1', '/inspection/r/' . $r1 . '/photo/' . $photoId);
        $this->page('employee.demo1', '/inspection/board', 403);
        $this->page('employee.demo1', '/inspection/p/' . $lt, 404);
        $b = $this->t->browser();
        $b->login('tech.demo1');
        $res = $b->get('/inspection/q/' . $token);
        self::assertContains($res->status, [302, 303]);
        $old = $token;
        $in->newQr($ga, (string) $db->scalar('SELECT public_id FROM in_points WHERE id = ?', [$srv]));
        self::assertSame('http404', $this->refused(fn () => $in->byQr($tech, $old)), 'the old label stops working');
        $round = (string) $db->scalar("SELECT public_id FROM in_rounds WHERE point_id = ? AND due_date = '2026-11-13'", [$ltId]);
        $b2 = $this->t->browser();
        $b2->login('employee.demo1');
        $res = $b2->post('/inspection/r/' . $round . '/submit', ['result_1' => 'pass', 'value_2' => '6.5'], ['photo_1' => ['ext.png', (string) base64_decode(self::PNG)]]);
        self::assertSame(303, $res->status);
        self::assertSame('pass', $this->rd($round)['result']);
    }
}
