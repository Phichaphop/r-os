<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Blue\Core\Modules\ModuleRegistry;
use Blue\Modules\Attendance\DayCalculator;
use Blue\Modules\Attendance\PunchImport;
use Blue\Modules\Attendance\WorkSchedule;
use BlueCollector\Api;
use BlueCollector\Signature;
use BlueCollector\Store;

/** Phase 4B (DEC-64) — day rules, schedule validation, import parsing and the simulated Collector's Punch API. Values are test values. */
final class AttendanceUnitTest extends TestCase
{
    private const RULE = ['start' => '08:00', 'end' => '17:00', 'break_minutes' => 60, 'late_grace_minutes' => 5, 'early_grace_minutes' => 0];
    private const WORK = ['working' => true, 'type' => 'working', 'name' => null];

    private static function local(string $local): DateTimeImmutable
    {
        return (DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, new DateTimeZone('Asia/Bangkok')) ?: throw new \RuntimeException())->setTimezone(new DateTimeZone('UTC'));
    }

    private static function calc(array $punches, ?array $rule = self::RULE, array $day = self::WORK, ?array $leave = null, ?array $corr = null, string $date = '2026-11-05'): array
    {
        return DayCalculator::calculate($date, new DateTimeZone('Asia/Bangkok'), $day, $leave, $punches, $corr, $rule, '2026-11-09');
    }

    public function testDayRules(): void
    {
        $r = self::calc([self::local('2026-11-05 17:10'), self::local('2026-11-05 08:04'), self::local('2026-11-05 08:05')]);
        self::assertSame('ok', $r['status'], 'within the grace, any order, double scan');
        self::assertSame(0, $r['late_minutes']);
        self::assertSame(9 * 60 + 6 - 60, $r['worked_minutes']);
        self::assertSame(3, $r['punch_count']);
        $late = self::calc([self::local('2026-11-05 08:06'), self::local('2026-11-05 17:00')]);
        self::assertSame(['late', 6], [$late['status'], $late['late_minutes']], 'beyond the grace counts from the start');
        $both = self::calc([self::local('2026-11-05 08:30'), self::local('2026-11-05 16:00')]);
        self::assertSame(['late_early', 30, 60], [$both['status'], $both['late_minutes'], $both['early_minutes']]);
        self::assertSame('missing_out', self::calc([self::local('2026-11-05 08:00')])['status']);
        self::assertSame('missing_in', self::calc([self::local('2026-11-05 16:55')])['status'], 'one punch after mid-day = out');
        self::assertSame('in_progress', self::calc([self::local('2026-11-09 08:00')], self::RULE, self::WORK, null, null, '2026-11-09')['status'], 'today is not yet "missing"');
        self::assertSame('absent', self::calc([])['status']);
        self::assertSame('no_data', self::calc([], null)['status'], 'without a schedule in force nobody is called absent');
        self::assertSame('off', self::calc([], self::RULE, ['working' => false, 'type' => 'weekend', 'name' => null])['status']);
        self::assertSame('off_worked', self::calc([self::local('2026-11-05 09:00')], self::RULE, ['working' => false, 'type' => 'holiday', 'name' => 'x'])['status']);
        self::assertSame('no_calendar', self::calc([], self::RULE, ['working' => null, 'type' => null, 'name' => null])['status']);
        self::assertSame('leave', self::calc([], self::RULE, self::WORK, ['part' => 'full', 'name' => 'ลาป่วย'])['status']);
        $half = self::calc([self::local('2026-11-05 13:00'), self::local('2026-11-05 17:00')], self::RULE, self::WORK, ['part' => 'am', 'name' => 'ลากิจ']);
        self::assertSame(['half_leave', null], [$half['status'], $half['late_minutes']], 'no lateness on a half-day leave');
        $none = self::calc([self::local('2026-11-05 09:30'), self::local('2026-11-05 17:00')], null);
        self::assertSame(['ok', null, 450], [$none['status'], $none['late_minutes'], $none['worked_minutes']], 'no schedule → never late, no break');
        $fixed = self::calc([self::local('2026-11-05 08:00')], self::RULE, self::WORK, null, ['in' => null, 'out' => self::local('2026-11-05 17:30')]);
        self::assertSame(['ok', 'punch', 'correction'], [$fixed['status'], $fixed['in_source'], $fixed['out_source']]);
        self::assertSame('2026-11-04', DayCalculator::workDate(self::local('2026-11-05 03:30'), new DateTimeZone('Asia/Bangkok'), 240), 'before the day start → yesterday');
        self::assertSame('2026-11-05', DayCalculator::workDate(self::local('2026-11-05 04:00'), new DateTimeZone('Asia/Bangkok'), 240));
    }

    public function testScheduleValidation(): void
    {
        [$v, $e] = WorkSchedule::validate(['day_start' => '04:00', 'correction_max_days' => '', 'groups' => [
            ['group' => '*', 'start' => '08:00', 'end' => '17:00', 'break_minutes' => '60'], ['group' => 'daily', 'start' => '07:30', 'end' => '16:30'], ['group' => '', 'start' => '', 'end' => ''],
        ]]);
        self::assertSame([], $e);
        self::assertSame(['*', 'DAILY'], array_keys($v['groups']));
        self::assertNull($v['correction_max_days']);
        self::assertSame('07:30', WorkSchedule::forGroup($v, 'DAILY')['start']);
        self::assertSame('08:00', WorkSchedule::forGroup($v, 'MONTHLY')['start'], 'falls back to *');
        self::assertNull(WorkSchedule::forGroup(null, 'DAILY'));
        $cases = [
            [['day_start' => '07:00', 'groups' => [['group' => '*', 'start' => '08:00', 'end' => '17:00']]], 'day_start'],
            [['day_start' => '04:00', 'groups' => [['group' => '*', 'start' => '18:00', 'end' => '06:00']]], 'groups.0.end'],
            [['day_start' => '04:00', 'groups' => [['group' => '*', 'start' => '03:00', 'end' => '06:00']]], 'groups.0.start'],
            [['day_start' => '04:00', 'groups' => [['group' => '*', 'start' => '08:00', 'end' => '09:00', 'break_minutes' => '60']]], 'groups.0.break_minutes'],
            [['day_start' => '04:00', 'groups' => [['group' => '*', 'start' => '08:00', 'end' => '17:00'], ['group' => '*', 'start' => '09:00', 'end' => '18:00']]], 'groups.1.group'],
            [['day_start' => '04:00', 'groups' => [['group' => 'bad code!', 'start' => '08:00', 'end' => '17:00']]], 'groups.0.group'],
            [['day_start' => '04:00', 'correction_max_days' => '0', 'groups' => [['group' => '*', 'start' => '08:00', 'end' => '17:00']]], 'correction_max_days'],
            [['day_start' => '04:00', 'groups' => []], '_form'],
        ];
        foreach ($cases as [$in, $key]) {
            [$v2, $e2] = WorkSchedule::validate($in);
            self::assertNull($v2, (string) json_encode($in));
            self::assertArrayHasKey($key, $e2, (string) json_encode($in));
        }
        self::assertSame('08:02:00', PunchImport::parseTime('8:02'));
        self::assertSame('08:02:30', PunchImport::parseTime('08:02:30'));
        self::assertSame('08:00:00', PunchImport::parseTime('0.3333333333'));
        self::assertNull(PunchImport::parseTime('24:00'));
        self::assertNull(PunchImport::parseTime('eight'));
        $m = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/attendance/module.json'), true);
        self::assertSame([], ModuleRegistry::validate($m, 'attendance'));
    }

    public function testCollectorPunchApi(): void
    {
        $file = sys_get_temp_dir() . '/blue-collector-' . bin2hex(random_bytes(4)) . '.sqlite';
        $now = 1_794_189_600; // 2026-11-09T02:00:00Z
        try {
            $s = new Store($file);
            $s->addDevice('DEV-1', 'SER1', 'CiF510-FP', 'Asia/Bangkok', $now);
            $s->addKey('rk_0123456789abcdef', 'secret-secret-secret-1234', $now);
            for ($i = 0; $i < 5; $i++) {
                self::assertNotNull($s->record('DEV-1', '000104', '2026-11-0' . ($i + 2) . ' 08:01:00', 'finger', $now));
            }
            self::assertNull($s->record('DEV-1', '000104', '2026-11-02 08:01:00', 'finger', $now, 1), 'same device sequence = same event, stored once');
            $api = new Api($s);
            $sign = static fn (string $p, ?int $ts = null, string $secret = 'secret-secret-secret-1234'): array => Signature::headers('rk_0123456789abcdef', $secret, 'GET', $p, $ts ?? $now);
            self::assertSame(401, $api->handle('GET', '/v1/punches', [], $now)['status']);
            self::assertSame('bad_signature', $api->handle('GET', '/v1/punches?after=&limit=2', $sign('/v1/punches?after=&limit=2', null, 'wrong-wrong-wrong-wrong-1'), $now)['body']['error']);
            self::assertSame('stale_timestamp', $api->handle('GET', '/v1/punches?after=&limit=2', $sign('/v1/punches?after=&limit=2', $now - 301), $now)['body']['error']);
            $h = $sign('/v1/punches?after=&limit=2');
            $p1 = $api->handle('GET', '/v1/punches?after=&limit=2', $h, $now);
            self::assertSame(200, $p1['status']);
            self::assertCount(2, $p1['body']['items']);
            self::assertTrue($p1['body']['has_more']);
            self::assertSame('replay', $api->handle('GET', '/v1/punches?after=&limit=2', $h, $now)['body']['error'], 'a nonce is used once');
            self::assertSame(['event_id', 'device_id', 'device_serial', 'device_user_code', 'occurred_at', 'device_local_time', 'device_timezone', 'verify_method', 'collected_at'], array_keys($p1['body']['items'][0]), 'no biometric field exists');
            self::assertSame('2026-11-02T01:01:00Z', $p1['body']['items'][0]['occurred_at']);
            $q = '/v1/punches?after=' . $p1['body']['next_cursor'] . '&limit=10';
            $p2 = $api->handle('GET', $q, $sign($q), $now);
            self::assertCount(3, $p2['body']['items']);
            self::assertFalse($p2['body']['has_more']);
            $q3 = '/v1/punches?after=' . $p2['body']['next_cursor'] . '&limit=10';
            self::assertSame([], $api->handle('GET', $q3, $sign($q3), $now)['body']['items'], 'nothing new after the last cursor');
            foreach (['/v1/punches?after=&limit=0', '/v1/punches?after=&limit=1001', '/v1/punches?after=%27%3B--&limit=5'] as $bad) {
                self::assertSame(400, $api->handle('GET', $bad, $sign($bad), $now)['status'], $bad);
            }
            $hp = '/v1/health';
            $health = $api->handle('GET', $hp, $sign($hp), $now);
            self::assertSame('DEV-1', $health['body']['devices'][0]['device_id']);
            self::assertSame(405, $api->handle('POST', '/v1/punches', [], $now)['status'], 'read only');
            self::assertSame(404, $api->handle('GET', '/v1/delete', [], $now)['status']);
            self::assertSame(200, $api->handle('GET', '/collector/v1/health', $sign('/collector/v1/health'), $now)['status'], 'works in a sub-folder');
            self::assertSame(['c1' => 7], ['c1' => Api::decodeCursor(Api::encodeCursor(7))]);
            $s->revokeKey('rk_0123456789abcdef', $now);
            self::assertSame(401, $api->handle('GET', $hp, $sign($hp), $now)['status'], 'revoked key');
            for ($i = 0; $i < Api::RATE_PER_MIN; $i++) {
                $api->handle('GET', $hp, [], $now);
            }
            self::assertSame(429, $api->handle('GET', $hp, [], $now)['status']);
        } finally {
            @unlink($file);
            @unlink($file . '-wal');
            @unlink($file . '-shm');
        }
    }
}
