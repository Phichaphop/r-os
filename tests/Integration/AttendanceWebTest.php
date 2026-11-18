<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Modules\Attendance\AttendanceService;
use Blue\Modules\Attendance\PunchPuller;
use Blue\Tests\Support\CollectorTransport;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;
use BlueCollector\Api;
use BlueCollector\Reader\SimulatedReader;
use BlueCollector\Store;

/**
 * Phase 4B (DEC-64) — M34 attendance through the HTTP kernel with the simulated Time Collector answering in-process.
 * Test clock: Mon 2026-11-09 09:00 Bangkok (02:00Z); DEMO1 calendar Mon–Fri. Work-schedule values are TEST values.
 * Demo device user codes: D1-0004 → 000104, D1-0005 → 000105 …
 */
final class AttendanceWebTest extends TestCase
{
    private TestApp $t;
    private Store $store;
    private CollectorTransport $http;

    private const UNTRANSLATED = '/(?<![.\w])(attendance|employees|workflow|config|import)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
        $this->store = new Store($this->t->tmp . '/collector.sqlite');
        $now = fn (): int => $this->t->app->clock->now()->getTimestamp();
        $this->http = new CollectorTransport(new Api($this->store), \Closure::fromCallable($now));
        $this->t->app->setHttpTransport($this->http);
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

    private function db(): \Blue\Core\Database\Connection
    {
        return $this->t->app->db();
    }

    private function le(): int
    {
        return (int) $this->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function emp(string $code): int
    {
        return (int) $this->db()->scalar('SELECT id FROM employees WHERE employee_code = ?', [$code]);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function decide(TestBrowser $b, string $inst, string $decision, string $comment = ''): void
    {
        $b->get('/approvals/' . $inst);
        $res = $b->post('/approvals/' . $inst . '/decide', ['decision' => $decision, 'comment' => $comment, '_back' => '/approvals/' . $inst]);
        if (str_contains((string) ($res->headers['Location'] ?? ''), '/reauth')) {
            $b->get('/reauth');
            $b->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/approvals/' . $inst]);
            $b->get('/approvals/' . $inst);
            $b->post('/approvals/' . $inst . '/decide', ['decision' => $decision, 'comment' => $comment, '_back' => '/approvals/' . $inst]);
        }
    }

    /** Activates hip.time_collector, issues a key in Blue OS and adds it to the Collector (as IT would). */
    private function connect(): string
    {
        $db = $this->db();
        $db->run("UPDATE integrations SET status = 'active', endpoint = 'http://127.0.0.1:8787', privacy_review = 'approved', privacy_ref = 'TEST' WHERE code = 'hip.time_collector'");
        $pid = (string) $db->scalar("SELECT public_id FROM integrations WHERE code = 'hip.time_collector'");
        $key = $this->t->app->integrations()->issueCredential($this->az(TestApp::ADMIN), $pid, null);
        $this->store->addKey($key['key_id'], $key['secret'], $this->t->app->clock->now()->getTimestamp());
        $this->store->addDevice('SIM-BR1-01', 'SIMBR101', 'CiF510-FP', 'Asia/Bangkok', $this->t->app->clock->now()->getTimestamp());
        return $key['key_id'];
    }

    /** Simulated punches (device local dates) — weekdays only. */
    private function simulate(array $dates, array $users, array $options = []): int
    {
        $n = 0;
        foreach ((new SimulatedReader($dates, $users, $options, '08:00', '17:00', 42))->read() as $p) {
            $n += $this->store->record('SIM-BR1-01', $p['user_code'], $p['local'], $p['verify'], $this->t->app->clock->now()->getTimestamp()) !== null ? 1 : 0;
        }
        return $n;
    }

    private function schedule(): void
    {
        $hr = $this->as('hr.demo1');
        $hr->get('/attendance/admin');
        $res = $hr->post('/attendance/admin/schedule', ['le' => (string) $this->le(), 'day_start' => '04:00', 'correction_max_days' => '31',
            'groups' => [['group' => '*', 'start' => '08:00', 'end' => '17:00', 'break_minutes' => '60', 'late_grace_minutes' => '5', 'early_grace_minutes' => '0']],
            'effective_from' => '2026-01-01', 'source_reference' => 'TEST ONLY — not company rules', 'reason' => 'test setup']);
        self::assertSame(303, $res->status, $res->body);
        $inst = (string) $this->db()->scalar('SELECT i.public_id FROM workflow_instances i JOIN config_versions v ON v.public_id = i.subject_id ORDER BY i.id DESC LIMIT 1');
        $this->decide($this->as('hr.head1'), $inst, 'approve');
        self::assertSame('approved', $this->db()->scalar("SELECT v.status FROM config_versions v JOIN config_definitions d ON d.id = v.definition_id WHERE d.config_key = 'attendance.schedule'"));
        AttendanceService::of($this->t->app)->forget();
    }

    public function testPagesRenderPerRoleAndNothingIsShipped(): void
    {
        self::assertSame(7, (int) $this->db()->scalar('SELECT COUNT(*) FROM att_device_users WHERE is_demo = 1'), 'demo device codes');
        self::assertSame(0, (int) $this->db()->scalar("SELECT COUNT(*) FROM config_versions v JOIN config_definitions d ON d.id = v.definition_id WHERE d.config_key = 'attendance.schedule'"), 'no schedule shipped');
        self::assertSame(0, (int) $this->db()->scalar('SELECT COUNT(*) FROM att_raw_punches'));
        $pages = [
            'employee.demo1' => ['/attendance', '/attendance/day/2026-11-06'],
            'manager.demo1' => ['/attendance', '/attendance/team', '/attendance/e/' . $this->db()->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0005'")],
            'hr.demo1' => ['/attendance', '/attendance/team', '/attendance/admin', '/attendance/collector', '/import/attendance.punches?le=' . $this->le()],
            TestApp::ADMIN => ['/attendance/collector'],
        ];
        foreach ($pages as $user => $paths) {
            $b = $this->as($user);
            foreach ($paths as $p) {
                [$path, $qs] = array_pad(explode('?', $p, 2), 2, '');
                parse_str($qs, $q);
                $res = $b->get($path, $q);
                self::assertSame(200, $res->status, "{$user} {$p}");
                self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body), "untranslated key for {$user} on {$p}");
            }
        }
        $e = $this->as('employee.demo1');
        self::assertStringContainsString('ยังไม่มีเวลาทำงานมาตรฐาน', $e->get('/attendance')->body);
        self::assertStringContainsString('ยังไม่เคยได้รับข้อมูลจากเครื่องสแกน', $e->get('/attendance')->body, 'honest "data as of"');
        foreach (['/attendance/admin', '/attendance/collector'] as $p) {
            self::assertSame(403, $e->get($p)->status, $p);
        }
        self::assertSame(303, $e->get('/attendance/team')->status, 'no reports → back with a message');
        $other = (string) $this->db()->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0005'");
        self::assertSame(404, $e->get('/attendance/e/' . $other)->status, 'another employee\'s time is not even acknowledged');
        $sys = $this->as(TestApp::ADMIN);
        self::assertSame(403, $sys->get('/attendance/admin')->status, 'the system admin does not see attendance data');
        self::assertSame(404, $sys->get('/attendance/e/' . $other)->status);
        self::assertStringContainsString('ยังไม่ได้ตั้งค่า', $sys->get('/attendance/collector')->body);
    }

    public function testPullStoresRawPunchesOnceAndBuildsDays(): void
    {
        $this->connect();
        $n = $this->simulate(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'], ['000104', '000105', '000199'], ['000105' => ['miss' => 'out'], '000104' => ['double' => true]]);
        self::assertSame(5 * 3 + 5 * 1 + 5 * 2, $n);
        $sys = $this->as(TestApp::ADMIN);
        self::assertStringContainsString('พร้อมใช้งาน', $sys->get('/attendance/collector')->body);
        $res = $sys->post('/attendance/collector/pull');
        self::assertSame(303, $res->status);
        $run = $this->db()->one('SELECT * FROM att_pull_runs ORDER BY id DESC LIMIT 1');
        self::assertSame('success', $run['status']);
        self::assertSame(30, (int) $run['inserted']);
        self::assertSame(10, (int) $run['unmapped'], '000199 belongs to nobody');
        self::assertNotNull($run['cursor_to']);
        self::assertSame('new', $this->db()->scalar("SELECT status FROM att_devices WHERE device_code = 'SIM-BR1-01'"), 'device appears by itself');
        self::assertGreaterThanOrEqual(2, count($this->http->calls), 'punches + health');
        foreach ($this->http->calls as $c) {
            self::assertArrayHasKey('X-Blue-Signature', $c['headers']);
            self::assertStringNotContainsString('secret', strtolower($c['url']));
        }
        // second pull: nothing new (cursor) — and a pull from the start again stores nothing twice
        $sys->post('/attendance/collector/pull');
        self::assertSame(0, (int) $this->db()->scalar('SELECT inserted FROM att_pull_runs ORDER BY id DESC LIMIT 1'));
        $this->db()->run('UPDATE att_pull_runs SET cursor_to = NULL');
        $r3 = PunchPuller::of($this->t->app)->run('manual', null);
        self::assertSame(0, (int) $r3['inserted']);
        self::assertSame(30, (int) $r3['duplicates'], 'unique (source, event id)');
        self::assertSame(30, (int) $this->db()->scalar('SELECT COUNT(*) FROM att_raw_punches'));

        // days: D1-0004 has in + double in + out → first and last; D1-0005 forgot to scan out
        $d4 = $this->db()->one("SELECT * FROM att_days WHERE employee_id = ? AND work_date = '2026-11-03'", [$this->emp('D1-0004')]);
        self::assertSame('ok', $d4['status'], 'no schedule → never "late"');
        self::assertNull($d4['late_minutes']);
        self::assertSame(3, (int) $d4['punch_count']);
        self::assertGreaterThan(8 * 60, (int) $d4['worked_minutes']);
        $d5 = $this->db()->one("SELECT * FROM att_days WHERE employee_id = ? AND work_date = '2026-11-03'", [$this->emp('D1-0005')]);
        self::assertSame('missing_out', $d5['status']);
        $e = $this->as('employee.demo1');
        $page = $e->get('/attendance', ['month' => '2026-11'])->body;
        self::assertStringContainsString('ข้อมูลเครื่องสแกนล่าสุดเมื่อ', $page);
        self::assertStringContainsString('ปกติ', $page);
        $day = $e->get('/attendance/day/2026-11-03')->body;
        self::assertStringContainsString('ตัวรับเวลา', $day, 'raw punch source shown');
        self::assertMatchesRegularExpression('/ลายนิ้วมือ \(ไม่เก็บข้อมูลลายนิ้วมือ\)|ใบหน้า \(ไม่เก็บภาพใบหน้า\)|บัตร/u', $day, 'how the person verified — never the biometric itself');
        // unmapped code visible to HR, mapping it attributes the old punches without touching them
        $hr = $this->as('hr.demo1');
        $admin = $hr->get('/attendance/admin')->body;
        self::assertStringContainsString('000199', $admin);
        $res = $hr->post('/attendance/admin/codes', ['le' => (string) $this->le(), 'code' => '000199', 'employee_code' => 'D1-0006', 'valid_from' => '2026-11-01', 'device' => '']);
        self::assertSame(303, $res->status, $res->body);
        self::assertSame(5, (int) $this->db()->scalar("SELECT COUNT(*) FROM att_days WHERE employee_id = ? AND status = 'ok'", [$this->emp('D1-0006')]));
        self::assertSame(0, (int) $this->db()->scalar('SELECT COUNT(*) FROM att_raw_punches WHERE employee_id IS NOT NULL'), 'raw rows untouched — the mapping says who');
        $dup = $hr->post('/attendance/admin/codes', ['le' => (string) $this->le(), 'code' => '000199', 'employee_code' => 'D1-0007', 'valid_from' => '2026-11-05']);
        self::assertSame(422, $dup->status, 'a code has one owner at a time');
    }

    public function testFailuresKeyRotationAndBiometricItemsAreRefused(): void
    {
        $oldKey = $this->connect();
        $this->simulate(['2026-11-05'], ['000104']);
        // network down → failed run, cursor unchanged, logged without secrets
        $this->http->override = static fn (string $url): array => ['status' => 0, 'body' => '', 'error' => 'network:7', 'ms' => 3];
        $r = PunchPuller::of($this->t->app)->run('manual', null);
        self::assertSame('failed', $r['status']);
        self::assertNull($r['cursor_to']);
        self::assertSame(0, (int) $this->db()->scalar('SELECT COUNT(*) FROM att_raw_punches'));
        $log = $this->db()->one("SELECT l.* FROM integration_logs l JOIN integrations i ON i.id = l.integration_id WHERE i.code = 'hip.time_collector' AND l.status = 'failed' ORDER BY l.id DESC LIMIT 1");
        self::assertSame('network:7', $log['error_code']);
        $this->http->override = null;
        // a new key in Blue OS that is not yet on the Collector → 401 → the previous key is used
        $pid = (string) $this->db()->scalar("SELECT public_id FROM integrations WHERE code = 'hip.time_collector'");
        $this->t->app->integrations()->issueCredential($this->az(TestApp::ADMIN), $pid, null);
        $r2 = PunchPuller::of($this->t->app)->run('manual', null);
        self::assertSame('success', $r2['status']);
        self::assertSame(2, (int) $r2['inserted']);
        self::assertSame($oldKey, $this->db()->scalar("SELECT key_id FROM integration_logs WHERE status = 'success' ORDER BY id DESC LIMIT 1"));
        // an answer carrying a template field is refused item by item; garbage is refused
        $this->http->override = static fn (string $url): ?array => str_contains($url, '/v1/punches') ? ['status' => 200, 'error' => null, 'ms' => 1, 'body' => (string) json_encode([
            'items' => [
                ['event_id' => 'HIP-X-1', 'device_id' => 'SIM-BR1-01', 'device_user_code' => '000104', 'occurred_at' => '2026-11-06T01:00:00Z', 'verify_method' => 'finger', 'finger_template' => 'AAAA'],
                ['event_id' => 'HIP-X-2', 'device_id' => 'SIM-BR1-01', 'device_user_code' => '000104', 'occurred_at' => 'yesterday'],
                ['event_id' => 'HIP-X-3', 'device_id' => 'SIM-BR1-01', 'device_user_code' => '000104', 'occurred_at' => '2026-11-06T01:00:00Z', 'verify_method' => 'card'],
            ], 'next_cursor' => 'YzE6OTk', 'has_more' => false])] : null;
        $r3 = PunchPuller::of($this->t->app)->run('manual', null);
        self::assertSame(1, (int) $r3['inserted']);
        self::assertSame(2, (int) $r3['invalid']);
        self::assertSame(0, (int) $this->db()->scalar("SELECT COUNT(*) FROM att_raw_punches WHERE source_event_id = 'HIP-X-1'"), 'never stored');
        // production refuses plain HTTP
        self::assertFalse(PunchPuller::urlAllowed('http://127.0.0.1:8787', true));
        self::assertTrue(PunchPuller::urlAllowed('http://192.168.1.20:8787', false));
        self::assertFalse(PunchPuller::urlAllowed('http://collector.example.com', false));
        self::assertFalse(PunchPuller::urlAllowed('https://u:p@collector.example.com', false));
        self::assertTrue(PunchPuller::urlAllowed('https://collector.example.com/base', true));
        // interval: 1–24 h, audited
        $hr = $this->as('hr.demo1');
        $hr->get('/attendance/collector');
        self::assertSame(303, $hr->post('/attendance/collector/interval', ['hours' => '6'])->status);
        self::assertSame(360, (int) $this->db()->scalar("SELECT interval_minutes FROM scheduled_jobs WHERE job_type = 'attendance.pull'"));
        self::assertSame(422, $hr->post('/attendance/collector/interval', ['hours' => '0'])->status);
    }

    public function testScheduleIsMakerCheckerAndLatenessFollowsIt(): void
    {
        $hr = $this->as('hr.demo1');
        $hr->get('/attendance/admin');
        $bad = $hr->post('/attendance/admin/schedule', ['le' => (string) $this->le(), 'day_start' => '09:00', 'groups' => [['group' => '*', 'start' => '17:00', 'end' => '08:00']],
            'effective_from' => '2026-01-01', 'source_reference' => 'x', 'reason' => 'x x']);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('เวลาเลิกงานต้องหลังเวลาเริ่มงาน', $bad->body);
        self::assertStringContainsString('ระหว่าง 00:00 ถึง 06:00', $bad->body);
        $sys = $this->as(TestApp::ADMIN);
        self::assertSame(403, $sys->post('/attendance/admin/schedule', ['le' => (string) $this->le()])->status, 'SYS does not own the schedule');
        $hr->post('/attendance/admin/schedule', ['le' => (string) $this->le(), 'day_start' => '04:00', 'groups' => [['group' => '*', 'start' => '08:00', 'end' => '17:00', 'break_minutes' => '60', 'late_grace_minutes' => '5']],
            'effective_from' => '2026-01-01', 'source_reference' => 'TEST ONLY', 'reason' => 'x x']);
        $inst = (string) $this->db()->scalar('SELECT public_id FROM workflow_instances ORDER BY id DESC LIMIT 1');
        $assignees = array_column($this->db()->all('SELECT u.username FROM workflow_tasks k JOIN workflow_instances i ON i.id = k.instance_id JOIN users u ON u.id = k.assignee_id WHERE i.public_id = ?', [$inst]), 'username');
        self::assertSame(['hr.head1'], $assignees, 'only the other HR approver');
        $head = $this->as('hr.head1');
        $page = $head->get('/approvals/' . $inst)->body;
        self::assertStringContainsString('08:00–17:00 · พัก 60 นาที · ผ่อนผันเข้า 5 นาที', $page, 'the approver reads hours, not JSON');
        self::assertStringStartsWith('เวลาทำงานมาตรฐาน · 2026-01-01', (string) $this->db()->scalar('SELECT title FROM workflow_instances WHERE public_id = ?', [$inst]));
        $this->decide($head, $inst, 'approve');
        AttendanceService::of($this->t->app)->forget();

        $this->connect();
        $this->simulate(['2026-11-04', '2026-11-05'], ['000104', '000105'], ['000104' => ['late' => 30]]);
        PunchPuller::of($this->t->app)->run('manual', null);
        $d = $this->db()->one("SELECT * FROM att_days WHERE employee_id = ? AND work_date = '2026-11-05'", [$this->emp('D1-0004')]);
        self::assertSame('late', $d['status']);
        self::assertGreaterThanOrEqual(10, (int) $d['late_minutes'], 'beyond the grace counts from the start time');
        $d5 = $this->db()->one("SELECT * FROM att_days WHERE employee_id = ? AND work_date = '2026-11-05'", [$this->emp('D1-0005')]);
        self::assertSame('ok', $d5['status']);
        self::assertSame(0, (int) $d5['late_minutes']);
        $span = (strtotime((string) $d5['last_out']) - strtotime((string) $d5['first_in'])) / 60;
        self::assertSame((int) $span - 60, (int) $d5['worked_minutes'], 'break deducted');
        // absence on a working day without punches (D1-0006 has no punches) and the weekend is a day off
        AttendanceService::of($this->t->app)->recalc($this->emp('D1-0006'), '2026-11-06', '2026-11-08');
        self::assertSame('absent', $this->db()->scalar("SELECT status FROM att_days WHERE employee_id = ? AND work_date = '2026-11-06'", [$this->emp('D1-0006')]));
        self::assertSame('off', $this->db()->scalar("SELECT status FROM att_days WHERE employee_id = ? AND work_date = '2026-11-07'", [$this->emp('D1-0006')]));
        // team view for the manager and recalculation
        $m = $this->as('manager.demo1');
        $team = $m->get('/attendance/team', ['month' => '2026-11'])->body;
        self::assertStringContainsString('D1-0004', $team);
        self::assertSame(303, $m->post('/attendance/team/recalc', ['month' => '2026-11'])->status);
    }

    public function testCorrectionGoesToTheManagerAndNeverTouchesRawPunches(): void
    {
        $this->schedule();
        $this->connect();
        $this->simulate(['2026-11-05'], ['000104'], ['000104' => ['miss' => 'out']]);
        PunchPuller::of($this->t->app)->run('manual', null);
        $emp = $this->emp('D1-0004');
        self::assertSame('missing_out', $this->db()->scalar("SELECT status FROM att_days WHERE employee_id = ? AND work_date = '2026-11-05'", [$emp]));
        $rawBefore = $this->db()->all('SELECT * FROM att_raw_punches ORDER BY id');

        $e = $this->as('employee.demo1');
        $e->get('/attendance/day/2026-11-05');
        $future = $e->post('/attendance/corrections', ['work_date' => '2026-11-12', 'kind' => 'missing_out', 'proposed_out' => '17:30', 'reason' => 'ลืมสแกน']);
        self::assertSame(422, $future->status);
        $noTime = $e->post('/attendance/corrections', ['work_date' => '2026-11-05', 'kind' => 'missing_out', 'reason' => 'ลืมสแกน']);
        self::assertSame(422, $noTime->status);
        $res = $e->post(
            '/attendance/corrections',
            ['work_date' => '2026-11-05', 'kind' => 'missing_out', 'proposed_out' => '17:30', 'reason' => 'ลืมสแกนออก'],
            ['evidence' => ['photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')]]
        );
        self::assertSame(303, $res->status, $res->body);
        $c = $this->db()->one('SELECT c.*, i.public_id AS inst FROM att_corrections c JOIN workflow_instances i ON i.id = c.workflow_instance_id ORDER BY c.id DESC LIMIT 1');
        self::assertSame('pending', $c['status']);
        self::assertNotNull($c['document_pid']);
        $again = $e->post('/attendance/corrections', ['work_date' => '2026-11-05', 'kind' => 'missing_out', 'proposed_out' => '17:31', 'reason' => 'again']);
        self::assertSame(422, $again->status, 'one open request per day');
        $assignees = array_column($this->db()->all('SELECT u.username FROM workflow_tasks k JOIN users u ON u.id = k.assignee_id WHERE k.instance_id = ?', [$c['workflow_instance_id']]), 'username');
        self::assertSame(['manager.demo1'], $assignees);
        self::assertSame(200, $this->as('employee.demo1')->get('/attendance/c/' . $c['public_id'] . '/evidence')->status, 'own evidence is downloadable');
        $m = $this->as('manager.demo1');
        $page = $m->get('/approvals/' . $c['inst'])->body;
        self::assertStringContainsString('ลืมสแกนออก', $page);
        self::assertStringContainsString('17:30', $page);
        self::assertSame(200, $m->get('/attendance/c/' . $c['public_id'] . '/evidence')->status, 'the deciding manager may open the evidence');
        $this->decide($m, (string) $c['inst'], 'approve');
        self::assertSame('approved', $this->db()->scalar('SELECT status FROM att_corrections WHERE id = ?', [$c['id']]));
        $day = $this->db()->one("SELECT * FROM att_days WHERE employee_id = ? AND work_date = '2026-11-05'", [$emp]);
        self::assertSame('correction', $day['out_source']);
        self::assertSame('2026-11-05 10:30:00', $day['last_out'], '17:30 Bangkok = 10:30 UTC');
        self::assertContains($day['status'], ['ok', 'late']);
        self::assertSame($rawBefore, $this->db()->all('SELECT * FROM att_raw_punches ORDER BY id'), 'raw punches unchanged');
        $audit = $this->db()->one("SELECT diff_json FROM audit_events WHERE action = 'attendance.correction_approved' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString('before', (string) $audit['diff_json']);
        // other people cannot see it; HR can
        self::assertSame(200, $this->as('employee.demo1')->get('/attendance/c/' . $c['public_id'])->status, 'the owner sees it');
        self::assertSame(404, $this->as('manager.demo1')->get('/attendance/e/' . $this->db()->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0002'"))->status, 'a manager sees only direct reports');
        $hr = $this->as('hr.demo1');
        self::assertSame(200, $hr->get('/attendance/c/' . $c['public_id'])->status);
        self::assertSame(404, $this->as(TestApp::ADMIN)->get('/attendance/c/' . $c['public_id'])->status);
        // withdraw a second request
        $e2 = $this->as('employee.demo1');
        $e2->get('/attendance/day/2026-11-04');
        $e2->post('/attendance/corrections', ['work_date' => '2026-11-04', 'kind' => 'wrong_time', 'proposed_in' => '08:00', 'reason' => 'เวลาเครื่องผิด']);
        $c2 = (string) $this->db()->scalar('SELECT public_id FROM att_corrections ORDER BY id DESC LIMIT 1');
        $e2->get('/attendance/c/' . $c2);
        self::assertSame(303, $e2->post('/attendance/c/' . $c2 . '/withdraw', ['reason' => 'ส่งผิด'])->status);
        self::assertSame('cancelled', $this->db()->scalar('SELECT status FROM att_corrections WHERE public_id = ?', [$c2]));
    }

    public function testImportFileIsIdempotentAndChecksEveryRow(): void
    {
        $hr = $this->as('hr.demo1');
        $tpl = $hr->get('/import/attendance.punches/template', ['le' => (string) $this->le()]);
        self::assertSame(200, $tpl->status);
        self::assertStringContainsString('BLUE_PUNCH_IMPORT_XLSX_v1.xlsx', (string) ($tpl->headers['Content-Disposition'] ?? ''));
        $csv = "employee_code,date,time,direction,device_code,note\n"
            . "D1-0005,2026-11-06,08:01,in,GATE-1,ใบลงเวลา\n"
            . "D1-0005,06/11/2569,17:05,ออก,GATE-1,\n"
            . "D1-0005,2026-11-06,08:01,in,GATE-1,ซ้ำในไฟล์\n"
            . "D1-9999,2026-11-06,08:00,,,\n"
            . "D1-0005,2026-11-20,08:00,,,\n"
            . "D1-0005,2026-11-06,25:00,,,\n";
        $hr->get('/import/attendance.punches', ['le' => (string) $this->le()]);
        $up = $hr->post('/import/attendance.punches', ['le' => (string) $this->le()], ['file' => ['punches.csv', $csv]]);
        self::assertSame(303, $up->status, $up->body);
        $batch = (string) $this->db()->scalar('SELECT public_id FROM import_batches ORDER BY id DESC LIMIT 1');
        $b = $this->db()->one('SELECT * FROM import_batches WHERE public_id = ?', [$batch]);
        self::assertSame(2, (int) $b['ok_rows']);
        self::assertSame(4, (int) $b['error_rows']);
        $hr->get('/import/batch/' . $batch);
        $hr->post('/import/batch/' . $batch . '/confirm', ['skip_errors' => '1']);
        self::assertSame(2, (int) $this->db()->scalar("SELECT COUNT(*) FROM att_raw_punches WHERE source = 'file'"));
        $day = $this->db()->one("SELECT * FROM att_days WHERE employee_id = ? AND work_date = '2026-11-06'", [$this->emp('D1-0005')]);
        self::assertSame('2026-11-06 01:01:00', $day['first_in']);
        self::assertSame('2026-11-06 10:05:00', $day['last_out']);
        // the same file again adds nothing
        $hr->post('/import/attendance.punches', ['le' => (string) $this->le()], ['file' => ['punches.csv', $csv]]);
        $b2 = (string) $this->db()->scalar('SELECT public_id FROM import_batches ORDER BY id DESC LIMIT 1');
        $hr->get('/import/batch/' . $b2);
        $hr->post('/import/batch/' . $b2 . '/confirm', ['skip_errors' => '1']);
        self::assertSame(2, (int) $this->db()->scalar("SELECT COUNT(*) FROM att_raw_punches WHERE source = 'file'"));
        $e = $this->as('employee.demo1');
        self::assertSame(403, $e->get('/import/attendance.punches', ['le' => (string) $this->le()])->status);
    }

    public function testDeviceAdminAndMappingEnd(): void
    {
        $this->connect();
        $this->simulate(['2026-11-05'], ['000104']);
        PunchPuller::of($this->t->app)->run('manual', null);
        $dev = (string) $this->db()->scalar("SELECT public_id FROM att_devices WHERE device_code = 'SIM-BR1-01'");
        $site = (int) $this->db()->scalar("SELECT s.id FROM sites s JOIN legal_entities l ON l.id = s.legal_entity_id WHERE l.code = 'DEMO1' AND s.code = 'BR1'");
        $sys = $this->as(TestApp::ADMIN);
        $sys->get('/attendance/collector');
        $sys->post('/attendance/collector/devices/' . $dev, ['name' => 'หน้าสาขา 1', 'site' => '0', 'status' => 'active']);
        self::assertSame('new', $this->db()->scalar('SELECT status FROM att_devices WHERE public_id = ?', [$dev]), 'active needs a site');
        $sys->post('/attendance/collector/devices/' . $dev, ['name' => 'หน้าสาขา 1', 'site' => (string) $site, 'status' => 'active']);
        self::assertSame('active', $this->db()->scalar('SELECT status FROM att_devices WHERE public_id = ?', [$dev]));
        $e = $this->as('employee.demo1');
        self::assertSame(403, $e->post('/attendance/collector/devices/' . $dev, ['name' => 'x', 'site' => (string) $site, 'status' => 'active'])->status);
        // end the demo mapping of D1-0004 on 2026-11-04 → the punch on 11-05 no longer counts for D1-0004
        $map = (string) $this->db()->scalar("SELECT public_id FROM att_device_users WHERE device_user_code = '000104'");
        $hr = $this->as('hr.demo1');
        $hr->get('/attendance/admin');
        self::assertSame(303, $hr->post('/attendance/admin/codes/' . $map . '/end', ['le' => (string) $this->le(), 'valid_to' => '2026-11-04'])->status);
        AttendanceService::of($this->t->app)->recalc($this->emp('D1-0004'), '2026-11-05', '2026-11-05');
        self::assertSame('no_data', $this->db()->scalar("SELECT status FROM att_days WHERE employee_id = ? AND work_date = '2026-11-05'", [$this->emp('D1-0004')]), 'no schedule in this test → "no data", never "absent"');
        self::assertStringContainsString('000104', $hr->get('/attendance/admin')->body, 'now listed as without an employee');
    }

    /**
     * 4C (DEC-66): period review → send for close (lock) → another HR approves (Calculation Ledger snapshot + event) →
     * payroll file from the snapshot → reopen with approval → close again (new revision supersedes the first).
     */
    public function testPeriodCloseSnapshotExportAndReopen(): void
    {
        $db = $this->db();
        $le = $this->le();
        $this->schedule();
        $this->connect();
        $this->simulate(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'], ['000104', '000105'], ['000105' => ['miss' => 'out']]);
        PunchPuller::of($this->t->app)->run('manual', null);
        $db->run("UPDATE employees SET name_th = '=HYPERLINK(\"x\")' WHERE employee_code = 'D1-0001'");
        $e = $this->as('employee.demo1');
        $e->get('/attendance/day/2026-11-05');
        $e->post('/attendance/corrections', ['work_date' => '2026-11-05', 'kind' => 'wrong_time', 'proposed_in' => '07:55', 'reason' => 'สแกนช้า']);
        $corr = $db->one('SELECT c.*, i.public_id AS inst FROM att_corrections c JOIN workflow_instances i ON i.id = c.workflow_instance_id ORDER BY c.id DESC LIMIT 1');
        self::assertSame('pending', $corr['status']);

        // who may see the pages
        self::assertSame(403, $e->get('/attendance/periods')->status, 'employees do not close periods');
        $sys = $this->as(TestApp::ADMIN);
        self::assertSame(403, $sys->get('/attendance/periods')->status, 'the system admin does not see attendance data');
        $hr = $this->as('hr.demo1');
        $list = $hr->get('/attendance/periods');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('2026-10-01', $list->body, 'first suggestion: the previous month');
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($list->body));

        // create: validation, overlap
        self::assertSame(422, $hr->post('/attendance/periods', ['le' => (string) $le, 'period_start' => '2026-11-08', 'period_end' => '2026-11-02'])->status);
        self::assertSame(422, $hr->post('/attendance/periods', ['le' => (string) $le, 'period_start' => '2026-09-01', 'period_end' => '2026-11-08'])->status, 'longer than 62 days');
        $hr->get('/attendance/periods');
        self::assertSame(303, $hr->post('/attendance/periods', ['le' => (string) $le, 'period_start' => '2026-11-02', 'period_end' => '2026-11-08', 'label' => 'ทดสอบ'])->status);
        $pid = (string) $db->scalar('SELECT public_id FROM att_periods ORDER BY id DESC LIMIT 1');
        $hr->get('/attendance/periods');
        self::assertSame(422, $hr->post('/attendance/periods', ['le' => (string) $le, 'period_start' => '2026-11-08', 'period_end' => '2026-11-20'])->status, 'overlap');

        // review: a pending correction blocks; warnings need a confirmation
        $page = $hr->get('/attendance/periods/' . $pid);
        self::assertSame(200, $page->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body));
        self::assertStringContainsString('คำขอแก้เวลาค้าง', $page->body);
        self::assertSame(422, $hr->post('/attendance/periods/' . $pid . '/submit', ['confirm' => '1'])->status, 'pending correction blocks');
        $this->decide($this->as('manager.demo1'), (string) $corr['inst'], 'approve');
        $hr->get('/attendance/periods/' . $pid);
        self::assertSame(422, $hr->post('/attendance/periods/' . $pid . '/submit', [])->status, 'warnings must be confirmed');
        $hr->get('/attendance/periods/' . $pid);
        self::assertSame(303, $hr->post('/attendance/periods/' . $pid . '/submit', ['confirm' => '1', 'note' => 'ตรวจแล้ว'])->status);
        $p = $db->one('SELECT * FROM att_periods WHERE public_id = ?', [$pid]);
        self::assertSame('pending', $p['status']);
        self::assertSame(1, (int) $p['revision']);
        self::assertSame('locked', $db->scalar('SELECT status FROM locks WHERE public_id = ?', [$p['lock_pid']]));
        self::assertSame('2026-11-02..2026-11-08', $db->scalar('SELECT period_ref FROM locks WHERE public_id = ?', [$p['lock_pid']]));

        // frozen: corrections, the punch file, leave and recalculation leave the dates alone
        $e->get('/attendance/day/2026-11-03');
        $blocked = $e->post('/attendance/corrections', ['work_date' => '2026-11-04', 'kind' => 'wrong_time', 'proposed_in' => '07:50', 'reason' => 'ลองแก้']);
        self::assertSame(422, $blocked->status);
        self::assertStringContainsString('งวดที่ส่งปิดหรือปิดแล้ว', $blocked->body);
        $seen = [];
        $row = \Blue\Modules\Attendance\PunchImport::create($this->t->app)->validateRow(['employee_code' => 'D1-0005', 'date' => '2026-11-04', 'time' => '08:00'], ['legal_entity_id' => $le], $seen);
        self::assertSame('attendance.error.period_closed', $row['errors']['date'] ?? null);
        try {
            $this->t->app->locks()->assertRangeWritable('attendance', '2026-11-08', '2026-11-10', $le);
            self::fail('leave over a locked day must be refused');
        } catch (\Blue\Core\Error\BusinessException $ex) {
            self::assertSame(423, $ex->httpStatus);
        }
        self::assertNull($this->t->app->locks()->assertRangeWritable('attendance', '2026-11-09', '2026-11-10', $le), 'the day after is free');
        $before = $db->one("SELECT * FROM att_days WHERE employee_id = ? AND work_date = '2026-11-03'", [$this->emp('D1-0005')]);
        self::assertSame('missing_out', $before['status']);
        $this->store->record('SIM-BR1-01', '000105', '2026-11-03 17:10:00', 'card', $this->t->app->clock->now()->getTimestamp());
        PunchPuller::of($this->t->app)->run('manual', null);
        self::assertSame('missing_out', $db->scalar("SELECT status FROM att_days WHERE employee_id = ? AND work_date = '2026-11-03'", [$this->emp('D1-0005')]), 'raw punch stored, day frozen');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM att_raw_punches WHERE device_user_code = '000105' AND occurred_at_utc = '2026-11-03 10:10:00'"));

        // approve: the sender cannot, another HR does → snapshot, event, lock snapshot
        $inst = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$p['workflow_instance_id']]);
        $assignees = array_column($db->all("SELECT u.username FROM workflow_tasks k JOIN users u ON u.id = k.assignee_id WHERE k.instance_id = ? AND k.status = 'open'", [$p['workflow_instance_id']]), 'username');
        self::assertNotContains('hr.demo1', $assignees, 'maker ≠ checker');
        self::assertContains('hr.head1', $assignees);
        self::assertNotContains(TestApp::ADMIN, $assignees, 'never falls to the system admin');
        $approval = $this->as('hr.head1')->get('/approvals/' . $inst)->body;
        self::assertStringContainsString('ตรวจแล้ว', $approval, 'the sender\'s note is on the approval page');
        $this->decide($this->as('hr.head1'), $inst, 'approve');
        $p = $db->one('SELECT * FROM att_periods WHERE public_id = ?', [$pid]);
        self::assertSame('finalized', $p['status']);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', (string) $p['snapshot_run_pid']);
        $run = $this->t->app->ledger()->open((string) $p['snapshot_run_pid']);
        self::assertTrue($run['intact']);
        self::assertSame('approved', $run['run']['status']);
        self::assertSame((string) $p['output_hash'], hash('sha256', (string) json_encode(['lines' => $run['outputs']['lines'], 'leave_types' => $run['outputs']['leave_types']], JSON_UNESCAPED_UNICODE)));
        self::assertSame('calc:' . $p['snapshot_run_pid'], $db->scalar('SELECT snapshot_ref FROM locks WHERE public_id = ?', [$p['lock_pid']]));
        $ev = $db->one("SELECT * FROM outbox_events WHERE event_name = 'attendance.period.finalized'");
        self::assertNotNull($ev);
        self::assertStringContainsString($pid, (string) $ev['payload_json']);
        $lines = $run['outputs']['lines'];
        $l5 = array_values(array_filter($lines, static fn (array $l): bool => $l['employee_code'] === 'D1-0005'))[0];
        self::assertSame(5, $l5['missing_punch_days'], 'forgot to scan out every day; the late punch for the 3rd is not in this snapshot');
        $l4 = array_values(array_filter($lines, static fn (array $l): bool => $l['employee_code'] === 'D1-0004'))[0];
        self::assertSame(1, $l4['corrected_days']);
        self::assertSame(5, $l4['worked_days']);

        // payroll file: only people with attendance.export, only finalized, always from the snapshot, every hand-out logged
        self::assertSame(403, $e->get('/attendance/periods/' . $pid . '/export')->status);
        $x = $hr->get('/attendance/periods/' . $pid . '/export');
        self::assertSame(200, $x->status);
        self::assertStringStartsWith('PK', $x->body);
        self::assertStringContainsString('BLUE_PAYROLL_TIME_INPUT_v1_DEMO1_2026-11-02_2026-11-08_r1.xlsx', (string) $x->headers['Content-Disposition']);
        $rows = \Blue\Core\Files\Xlsx::read($x->body);
        self::assertSame('employee_code', $rows[0][0]);
        self::assertCount(count($lines) + 1, array_filter($rows, static fn (array $r): bool => implode('', $r) !== ''));
        $csv = $hr->get('/attendance/periods/' . $pid . '/export', ['format' => 'csv']);
        self::assertStringStartsWith("\xEF\xBB\xBFemployee_code,", $csv->body);
        self::assertStringContainsString("'=HYPERLINK", $csv->body, 'a text cell that looks like a formula is neutralised in CSV');
        self::assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM att_period_exports WHERE period_id = ?', [$p['id']]));
        self::assertSame(hash('sha256', $x->body), $db->scalar("SELECT file_sha256 FROM att_period_exports WHERE format = 'xlsx'"));
        $final = $hr->get('/attendance/periods/' . $pid)->body;
        self::assertStringContainsString('ส่งให้ Payroll แล้ว', $final);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($final));

        // reopen: reason required, approved by another HR → open; the late punch now counts; close again = revision 2
        self::assertSame(422, $hr->post('/attendance/periods/' . $pid . '/reopen', ['reason' => 'x'])->status);
        $hr->get('/attendance/periods/' . $pid);
        self::assertSame(303, $hr->post('/attendance/periods/' . $pid . '/reopen', ['reason' => 'สแกนออกของ D1-0005 วันที่ 3 มาถึงช้า'])->status);
        self::assertSame('reopen_pending', $db->scalar('SELECT status FROM att_periods WHERE public_id = ?', [$pid]));
        self::assertSame(422, $e->post('/attendance/corrections', ['work_date' => '2026-11-04', 'kind' => 'wrong_time', 'proposed_in' => '07:50', 'reason' => 'ลองแก้'])->status, 'still closed while waiting');
        $rinst = (string) $db->scalar('SELECT i.public_id FROM att_periods p JOIN workflow_instances i ON i.id = p.reopen_instance_id WHERE p.public_id = ?', [$pid]);
        $this->decide($this->as('hr.head1'), $rinst, 'approve');
        self::assertSame('open', $db->scalar('SELECT status FROM att_periods WHERE public_id = ?', [$pid]));
        self::assertSame('reopened', $db->scalar('SELECT status FROM locks WHERE public_id = ?', [$p['lock_pid']]));
        self::assertNotNull($db->scalar("SELECT id FROM outbox_events WHERE event_name = 'attendance.period.reopened'"));
        $hr->get('/attendance/periods/' . $pid); // review recalculates the open period
        self::assertNotSame('missing_out', $db->scalar("SELECT status FROM att_days WHERE employee_id = ? AND work_date = '2026-11-03'", [$this->emp('D1-0005')]));
        $hr->get('/attendance/periods/' . $pid);
        self::assertSame(303, $hr->post('/attendance/periods/' . $pid . '/submit', ['confirm' => '1'])->status);
        $p2 = $db->one('SELECT * FROM att_periods WHERE public_id = ?', [$pid]);
        self::assertSame(2, (int) $p2['revision']);
        $this->decide($this->as('hr.head1'), (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$p2['workflow_instance_id']]), 'approve');
        $p2 = $db->one('SELECT * FROM att_periods WHERE public_id = ?', [$pid]);
        self::assertSame('finalized', $p2['status']);
        self::assertNotSame($p['snapshot_run_pid'], $p2['snapshot_run_pid']);
        self::assertSame(
            (int) $db->scalar('SELECT id FROM calculation_runs WHERE public_id = ?', [$p['snapshot_run_pid']]),
            (int) $db->scalar('SELECT supersedes_run_id FROM calculation_runs WHERE public_id = ?', [$p2['snapshot_run_pid']]),
            'the second close supersedes the first; the first stays'
        );
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM outbox_events WHERE event_name = 'attendance.period.finalized'"));
        $lines2 = $this->t->app->ledger()->open((string) $p2['snapshot_run_pid'])['outputs']['lines'];
        self::assertSame(4, array_values(array_filter($lines2, static fn (array $l): bool => $l['employee_code'] === 'D1-0005'))[0]['missing_punch_days'], 'after the reopen the late punch counts');
        $x2 = $hr->get('/attendance/periods/' . $pid . '/export');
        self::assertStringContainsString('_r2.xlsx', (string) $x2->headers['Content-Disposition']);
    }

    /** A close sent back releases the lock; resubmitting reuses the request; the snapshot cannot be altered. */
    public function testPeriodReturnedReleasesLockAndSnapshotCannotBeAltered(): void
    {
        $db = $this->db();
        $le = $this->le();
        $this->schedule();
        $hr = $this->as('hr.demo1');
        $hr->get('/attendance/periods');
        $hr->post('/attendance/periods', ['le' => (string) $le, 'period_start' => '2026-11-02', 'period_end' => '2026-11-06']);
        $pid = (string) $db->scalar('SELECT public_id FROM att_periods ORDER BY id DESC LIMIT 1');
        $hr->get('/attendance/periods/' . $pid);
        self::assertSame(303, $hr->post('/attendance/periods/' . $pid . '/submit', ['confirm' => '1'])->status);
        $p = $db->one('SELECT * FROM att_periods WHERE public_id = ?', [$pid]);
        $inst = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE id = ?', [$p['workflow_instance_id']]);
        $this->decide($this->as('hr.head1'), $inst, 'return', 'ตรวจวันขาดอีกครั้ง');
        self::assertSame('open', $db->scalar('SELECT status FROM att_periods WHERE public_id = ?', [$pid]));
        self::assertSame('reopened', $db->scalar('SELECT status FROM locks WHERE public_id = ?', [$p['lock_pid']]));
        self::assertNull($this->t->app->locks()->assertRangeWritable('attendance', '2026-11-03', '2026-11-03', $le), 'writable again');
        // sent again from the period page = the same request resubmitted
        $hr->get('/attendance/periods/' . $pid);
        self::assertSame(303, $hr->post('/attendance/periods/' . $pid . '/submit', ['confirm' => '1'])->status);
        self::assertSame('pending', $db->scalar('SELECT status FROM workflow_instances WHERE public_id = ?', [$inst]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM workflow_instances WHERE subject_type = 'att_period_close'"));
        $this->decide($this->as('hr.head1'), $inst, 'approve');
        self::assertSame('finalized', $db->scalar('SELECT status FROM att_periods WHERE public_id = ?', [$pid]));
        // the stored snapshot cannot be altered (append-only at the database) — the export reads it intact
        try {
            $db->run("UPDATE calculation_snapshots SET payload_sha256 = REPEAT('0', 64) WHERE run_id = (SELECT id FROM calculation_runs WHERE public_id = (SELECT snapshot_run_pid FROM att_periods WHERE public_id = ?))", [$pid]);
            self::fail('snapshots must be append-only');
        } catch (\PDOException $ex) {
            self::assertStringContainsString('append-only', $ex->getMessage());
        }
        self::assertSame(200, $hr->get('/attendance/periods/' . $pid . '/export')->status);
    }
}
