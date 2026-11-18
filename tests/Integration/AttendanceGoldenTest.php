<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Modules\Attendance\AttendanceService;
use Blue\Modules\Attendance\PunchPuller;
use Blue\Tests\Support\CollectorTransport;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;
use BlueCollector\Api;
use BlueCollector\Store;

/**
 * GD-TIME v1 (P0-06 §7, DEC-41) — the engine against expected values that come from open spreadsheet formulas
 * (tests/fixtures/gd-time/GD-TIME_v1.xlsx, rebuilt by build_gd_time.py), not from this code.
 *
 * Every punch goes the real way in: the simulated Time Collector → signed Punch API → pull (then a second pull from
 * the start: every source_event_id again), and the punch file → import → confirm. The period is then closed by one HR
 * and approved by another; both the daily results and the frozen payroll input are compared.
 * Status of the dataset: AI-prepared — unverified until HR Time checks it. The work rule is TEST ONLY.
 */
final class AttendanceGoldenTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/gd-time/';

    private TestApp $t;
    private Store $store;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
        $this->store = new Store($this->t->tmp . '/collector.sqlite');
        $now = fn (): int => $this->t->app->clock->now()->getTimestamp();
        $this->t->app->setHttpTransport(new CollectorTransport(new Api($this->store), \Closure::fromCallable($now)));
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    /** @return list<array<string, string>> */
    private static function csv(string $name): array
    {
        $fh = fopen(self::DIR . $name, 'r');
        self::assertNotFalse($fh);
        $head = (array) fgetcsv($fh, null, ',', '"', '');
        $rows = [];
        while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $rows[] = array_combine(array_map('strval', $head), array_map('strval', $r));
        }
        fclose($fh);
        return $rows;
    }

    private function as(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private function approve(string $inst): void
    {
        $b = $this->as('hr.head1');
        $b->get('/approvals/' . $inst);
        $res = $b->post('/approvals/' . $inst . '/decide', ['decision' => 'approve', 'comment' => '', '_back' => '/approvals/' . $inst]);
        if (str_contains((string) ($res->headers['Location'] ?? ''), '/reauth')) {
            $b->get('/reauth');
            $b->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/approvals/' . $inst]);
            $b->get('/approvals/' . $inst);
            $b->post('/approvals/' . $inst . '/decide', ['decision' => 'approve', 'comment' => '', '_back' => '/approvals/' . $inst]);
        }
    }

    public function testEngineMatchesTheGoldenDataset(): void
    {
        $db = $this->t->app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $now = $this->t->app->clock->now()->getTimestamp();

        // the TEST rule of the dataset (sheet "rule"): 08:00–17:00, break 60, late grace 5, early grace 0, new day at 04:00
        $hr = $this->as('hr.demo1');
        $hr->get('/attendance/admin');
        $res = $hr->post('/attendance/admin/schedule', ['le' => (string) $le, 'day_start' => '04:00', 'correction_max_days' => '31',
            'groups' => [['group' => '*', 'start' => '08:00', 'end' => '17:00', 'break_minutes' => '60', 'late_grace_minutes' => '5', 'early_grace_minutes' => '0']],
            'effective_from' => '2026-01-01', 'source_reference' => 'GD-TIME v1 — TEST ONLY, not company rules', 'reason' => 'golden dataset']);
        self::assertSame(303, $res->status, $res->body);
        $this->approve((string) $db->scalar('SELECT i.public_id FROM workflow_instances i JOIN config_versions v ON v.public_id = i.subject_id ORDER BY i.id DESC LIMIT 1'));
        AttendanceService::of($this->t->app)->forget();

        // Time Collector on, a key issued in Blue OS and added at the Collector (as IT would)
        $db->run("UPDATE integrations SET status = 'active', endpoint = 'http://127.0.0.1:8787', privacy_review = 'approved', privacy_ref = 'TEST' WHERE code = 'hip.time_collector'");
        $key = $this->t->app->integrations()->issueCredential(
            new \Blue\Core\Authz\Authorizer($db, $this->t->clock, (int) $this->t->user(TestApp::ADMIN)['id']),
            (string) $db->scalar("SELECT public_id FROM integrations WHERE code = 'hip.time_collector'"),
            null
        );
        $this->store->addKey($key['key_id'], $key['secret'], $now);
        $this->store->addDevice('SIM-BR1-01', 'SIMBR101', 'CiF510-FP', 'Asia/Bangkok', $now);

        $punches = self::csv('punches.csv');
        $api = array_values(array_filter($punches, static fn (array $p): bool => $p['source'] === 'api'));
        $file = array_values(array_filter($punches, static fn (array $p): bool => $p['source'] === 'file'));
        foreach ($api as $p) {
            self::assertNotNull($this->store->record('SIM-BR1-01', $p['device_user_code'], $p['local_time'], 'finger', $now));
        }
        PunchPuller::of($this->t->app)->run('manual', null);
        // E-01: pull everything again from the start — every source_event_id arrives a second time and is stored once
        $db->run('UPDATE att_pull_runs SET cursor_to = NULL');
        PunchPuller::of($this->t->app)->run('manual', null);
        self::assertSame(count($api), (int) $db->scalar("SELECT COUNT(*) FROM att_raw_punches WHERE source = 'api'"), 'source_event_id seen twice, stored once');

        // F-01/F-02: the punch file (HH:MM in local time)
        $csv = "employee_code,date,time,direction,device_code,note\n";
        foreach ($file as $p) {
            $csv .= $p['employee_code'] . ',' . substr($p['local_time'], 0, 10) . ',' . substr($p['local_time'], 11, 5) . ",,,GD-TIME {$p['case']}\n";
        }
        $hr->get('/import/attendance.punches', ['le' => (string) $le]);
        self::assertSame(303, $hr->post('/import/attendance.punches', ['le' => (string) $le], ['file' => ['gd-time.csv', $csv]])->status);
        $batch = (string) $db->scalar('SELECT public_id FROM import_batches ORDER BY id DESC LIMIT 1');
        self::assertSame(count($file), (int) $db->scalar('SELECT ok_rows FROM import_batches WHERE public_id = ?', [$batch]));
        $hr->get('/import/batch/' . $batch);
        $hr->post('/import/batch/' . $batch . '/confirm', []);
        self::assertSame(count($file), (int) $db->scalar("SELECT COUNT(*) FROM att_raw_punches WHERE source = 'file'"));

        // close the period: create → review (recalculates) → send → another HR approves → snapshot
        self::assertSame(303, $hr->post('/attendance/periods', ['le' => (string) $le, 'period_start' => '2026-11-02', 'period_end' => '2026-11-08', 'label' => 'GD-TIME v1'])->status);
        $pid = (string) $db->scalar('SELECT public_id FROM att_periods ORDER BY id DESC LIMIT 1');
        self::assertSame(200, $hr->get('/attendance/periods/' . $pid)->status);
        self::assertSame(303, $hr->post('/attendance/periods/' . $pid . '/submit', ['confirm' => '1', 'note' => 'GD-TIME'])->status);
        $this->approve((string) $db->scalar('SELECT i.public_id FROM workflow_instances i JOIN att_periods p ON p.workflow_instance_id = i.id WHERE p.public_id = ?', [$pid]));
        $p = $db->one('SELECT * FROM att_periods WHERE public_id = ?', [$pid]);
        self::assertSame('finalized', $p['status']);

        // every employee-day against sheet "expected_days"
        $tz = new \DateTimeZone('Asia/Bangkok');
        $minute = static function (?string $utc, string $workDate) use ($tz): string {
            if ($utc === null) {
                return '';
            }
            $t = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            $base = \DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, $tz);
            return (string) intdiv($t->getTimestamp() - ($base !== false ? $base->getTimestamp() : 0), 60);
        };
        $diff = [];
        $expected = self::csv('expected_days.csv');
        self::assertCount(49, $expected);
        foreach ($expected as $x) {
            $d = $db->one(
                'SELECT d.* FROM att_days d JOIN employees e ON e.id = d.employee_id WHERE e.employee_code = ? AND d.work_date = ?',
                [$x['employee_code'], $x['work_date']]
            );
            self::assertNotNull($d, $x['employee_code'] . ' ' . $x['work_date'] . ' has no result');
            $got = [
                'day_type' => (string) $d['day_type'], 'punch_count' => (string) $d['punch_count'],
                'in_min' => $minute($d['first_in'], $x['work_date']), 'out_min' => $minute($d['last_out'], $x['work_date']),
                'worked_minutes' => (string) $d['worked_minutes'], 'late_minutes' => (string) $d['late_minutes'], 'early_minutes' => (string) $d['early_minutes'],
                'status' => (string) $d['status'],
            ];
            foreach ($got as $k => $v) {
                if ($v !== $x[$k]) {
                    $diff[] = "{$x['employee_code']} {$x['work_date']} {$k}: expected '{$x[$k]}' got '{$v}'";
                }
            }
        }
        self::assertSame([], $diff, 'daily results differ from GD-TIME');

        // the frozen payroll input against sheet "expected_period"
        $lines = [];
        foreach ($this->t->app->ledger()->open((string) $p['snapshot_run_pid'])['outputs']['lines'] as $l) {
            $lines[$l['employee_code']] = $l;
        }
        foreach (self::csv('expected_period.csv') as $x) {
            self::assertArrayHasKey($x['employee_code'], $lines);
            foreach ($x as $k => $v) {
                if ($k !== 'employee_code' && (string) $lines[$x['employee_code']][$k] !== $v) {
                    $diff[] = "{$x['employee_code']} {$k}: expected '{$v}' got '{$lines[$x['employee_code']][$k]}'";
                }
            }
        }
        self::assertSame([], $diff, 'payroll input differs from GD-TIME');
    }
}
