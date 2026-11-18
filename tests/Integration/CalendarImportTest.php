<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Csv;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Xlsx;
use Blue\Core\Import\CalendarDaysImport;
use Blue\Tests\Support\TestApp;

/** 6.14 work calendars + 6.10 import (template, validation, preview, confirm) + SLA on working days (DEC-56). */
final class CalendarImportTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function az(string $username): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($username)['id']);
    }

    private function le(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    private function site(string $le, string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT s.id FROM sites s JOIN legal_entities l ON l.id = s.legal_entity_id WHERE l.code = ? AND s.code = ?', [$le, $code]);
    }

    private function cal(string $code): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM calendars WHERE code = ?', [$code]);
    }

    public function testWorkingDayResolutionPatternHolidaysAndInheritance(): void
    {
        $c = $this->t->app->calendars();
        $hq = $this->site('DEMO1', 'HQ');
        self::assertTrue($c->isWorkingDay($hq, null, '2026-12-29')['working'], 'Tuesday');
        self::assertFalse($c->isWorkingDay($hq, null, '2026-12-27')['working'], 'Sunday');
        $h = $c->isWorkingDay($hq, null, '2026-12-30');
        self::assertFalse($h['working']);
        self::assertSame('holiday', $h['type']);
        self::assertSame('DEMO1-WORK', $h['calendar'], 'site falls back to its company calendar');
        self::assertNull($c->isWorkingDay(null, $this->le('DEMO2'), '2026-12-29')['working'], 'no calendar → unknown, never guessed');
        // shuttle: Mon–Sat, inherits the company holiday
        self::assertTrue($c->isWorkingDay($hq, null, '2026-12-26', 'service')['working'], 'shuttle runs on Saturday');
        self::assertFalse($c->isWorkingDay($hq, null, '2026-12-30', 'service')['working'], 'company holiday inherited');
        self::assertSame(22, $c->workingDaysBetween($hq, null, '2026-12-01', '2026-12-31'), 'December 2026: 23 weekdays minus the company holiday');
    }

    public function testSiteCalendarOverridesAndRightsAreScoped(): void
    {
        $c = $this->t->app->calendars();
        $br1 = $this->site('DEMO1', 'BR1');
        $pid = $c->create($this->az('hr.demo1'), ['code' => 'DEMO1-BR1', 'name' => 'สาขา 1', 'type' => 'work', 'scope_level' => 'site', 'scope_id' => $br1, 'pattern' => '0111111', 'base' => $this->cal('DEMO1-WORK')]);
        self::assertTrue($c->isWorkingDay($br1, null, '2026-12-26')['working'], 'branch works Saturdays');
        self::assertFalse($c->isWorkingDay($br1, null, '2026-12-30')['working'], 'still gets the company holiday');
        $c->setDay($this->az('hr.demo1'), $pid, '2026-12-30', 'working', null, '', 'stock count');
        self::assertTrue($c->isWorkingDay($br1, null, '2026-12-30')['working'], 'own exception wins');
        try {
            $c->setDay($this->az('hr.demo2'), $pid, '2026-12-31', 'holiday', 'company', 'x', '');
            self::fail('another company edited the calendar');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        try {
            $c->setDay($this->az('employee.demo1'), $pid, '2026-12-31', 'holiday', 'company', 'x', '');
            self::fail('an employee edited the calendar');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus, 'employees may view but not change');
        }
        try {
            $c->create($this->az('hr.demo1'), ['code' => 'DUP', 'name' => 'x', 'type' => 'work', 'scope_level' => 'site', 'scope_id' => $br1, 'pattern' => '0111110', 'base' => '']);
            self::fail('two work calendars for one site');
        } catch (ValidationException $e) {
            self::assertSame('calendar.error.scope_taken', $e->errors['scope']);
        }
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'calendar.day_set'"));
    }

    public function testSlaCountsWorkingDaysOnly(): void
    {
        $c = $this->t->app->calendars();
        // Friday 2026-12-25 10:00 Bangkok + 24 working hours → skip Sat/Sun → Monday 10:00
        $start = new DateTimeImmutable('2026-12-25 03:00:00', new \DateTimeZone('UTC'));
        $due = $c->addWorkingHours($this->le('DEMO1'), $start, 24);
        self::assertSame('2026-12-28 03:00', $due?->format('Y-m-d H:i'));
        // Tuesday 29th + 24h → Wednesday is the company holiday → Thursday
        $due = $c->addWorkingHours($this->le('DEMO1'), new DateTimeImmutable('2026-12-29 03:00:00', new \DateTimeZone('UTC')), 24);
        self::assertSame('2026-12-31 03:00', $due?->format('Y-m-d H:i'));
        self::assertNull($c->addWorkingHours($this->le('DEMO2'), $start, 24), 'no calendar → caller keeps plain hours');
    }

    public function testImportPreviewThenConfirmWritesOnlyAfterConfirmation(): void
    {
        $imp = $this->t->app->imports();
        $hr = $this->az('hr.demo1');
        $params = ['calendar' => $this->cal('DEMO1-WORK')];
        $tpl = Xlsx::read($imp->template($hr, 'calendar.days', $params));
        self::assertSame(['วันที่', 'ชนิดวัน', 'ประเภทวันหยุด', 'ชื่อวันหยุด', 'หมายเหตุ'], $tpl[0], 'template header in the user\'s language');

        $csv = Csv::write(['date', 'ชนิดวัน', 'kind', 'name', 'note'], [
            ['2027-01-04', 'holiday', 'company', 'วันหยุดบริษัท (DEMO)', ''],
            ['05/01/2570', 'วันหยุด', 'พิเศษ', 'หยุดพิเศษ (DEMO)', 'BE date'],
            ['2026-12-30', 'working', '', '', 'replaces the demo holiday'],
            ['2027-02-30', 'holiday', 'company', 'bad date', ''],
            ['2027-01-04', 'holiday', 'company', 'duplicate', ''],
            ['2027-01-06', 'holiday', '', '', 'no name'],
            ['2027-01-07', 'sometimes', '', '', ''],
        ]);
        $pid = $imp->upload($hr, 'calendar.days', $params, UploadedFile::fromBytes('days.csv', $csv, $this->t->tmp));
        $prev = $imp->preview($hr, $pid, '', 1, 50);
        self::assertSame([7, 3, 4], [(int) $prev['batch']['total_rows'], (int) $prev['batch']['ok_rows'], (int) $prev['batch']['error_rows']]);
        $errs = array_column($prev['rows'], 'errors', 'row_no');
        self::assertSame('calendar.error.date', $errs[5]['date']);
        self::assertSame('import.error.duplicate_in_file', $errs[6]['date']);
        self::assertSame('calendar.error.holiday_name', $errs[7]['name']);
        self::assertSame('calendar.error.type', $errs[8]['type']);
        self::assertSame('update', $prev['rows'][2]['action']);
        self::assertSame(1, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM calendar_days'), 'nothing written before confirmation');
        self::assertStringNotContainsString('วันหยุดบริษัท', (string) $this->t->app->db()->scalar('SELECT GROUP_CONCAT(HEX(data_enc)) FROM import_rows'), 'row data encrypted');

        try {
            $imp->confirm($hr, $pid, false);
            self::fail('confirmed with errors without skipping them');
        } catch (BusinessException $e) {
            self::assertSame('import.error.has_errors', $e->messageKey);
        }
        try {
            $imp->preview($this->az('hr.demo2'), $pid, '', 1, 20);
            self::fail('someone else opened the batch');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        self::assertSame(3, $imp->confirm($hr, $pid, true));
        $c = $this->t->app->calendars();
        self::assertFalse($c->isWorkingDay(null, $this->le('DEMO1'), '2027-01-05')['working'], 'Buddhist-Era date converted');
        self::assertTrue($c->isWorkingDay(null, $this->le('DEMO1'), '2026-12-30')['working'], 'existing day updated');
        self::assertSame('import', $this->t->app->db()->scalar("SELECT source FROM calendar_days WHERE day_date = '2027-01-04'"));
        try {
            $imp->confirm($hr, $pid, true);
            self::fail('confirmed twice');
        } catch (BusinessException $e) {
            self::assertSame('import.error.not_pending', $e->messageKey);
        }
        $report = Xlsx::read($imp->errorReport($hr, $pid));
        self::assertCount(5, $report, 'header + 4 error rows');
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'import.applied'"));
    }

    public function testImportRefusesWrongFilesMissingColumnsAndExpiredBatches(): void
    {
        $imp = $this->t->app->imports();
        $hr = $this->az('hr.demo1');
        $params = ['calendar' => $this->cal('DEMO1-WORK')];
        try {
            $imp->upload($hr, 'calendar.days', $params, UploadedFile::fromBytes('x.xlsx', DocumentsTest::PDF, $this->t->tmp));
            self::fail('a PDF named .xlsx was accepted');
        } catch (ValidationException $e) {
            self::assertSame('import.error.type', $e->errors['file']);
        }
        try {
            $imp->upload($hr, 'calendar.days', $params, UploadedFile::fromBytes('x.csv', "name,note\nA,B\n", $this->t->tmp));
            self::fail('missing columns accepted');
        } catch (BusinessException $e) {
            self::assertSame('import.error.missing_columns', $e->messageKey);
        }
        try {
            $imp->upload($this->az('hr.demo2'), 'calendar.days', $params, UploadedFile::fromBytes('x.csv', "date,type\n2027-01-01,holiday\n", $this->t->tmp));
            self::fail('imported into another company calendar');
        } catch (HttpException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        $pid = $imp->upload($hr, 'calendar.days', $params, UploadedFile::fromBytes('x.csv', "date,type,name\n2027-01-08,holiday,X\n", $this->t->tmp));
        $this->t->clock->advance('+25 hours');
        self::assertSame(1, $imp->expire());
        self::assertNull($this->t->app->db()->scalar('SELECT data_enc FROM import_rows'));
        $this->expectException(BusinessException::class);
        $imp->confirm($hr, $pid, false);
    }

    public function testDateParsing(): void
    {
        self::assertSame('2026-12-05', CalendarDaysImport::parseDate('5/12/2569'));
        self::assertSame('2026-12-05', CalendarDaysImport::parseDate('2026-12-5'));
        self::assertSame('2026-12-05', CalendarDaysImport::parseDate('46361'));
        self::assertNull(CalendarDaysImport::parseDate('31/02/2026'));
        self::assertNull(CalendarDaysImport::parseDate('tomorrow'));
    }
}
