<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Modules\Dashboard\DashboardService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5E-5 (DEC-80): M46 executive dashboard — KPI dictionary, values vs source records, scope, statuses, alerts, export, snapshots. */
final class DashboardWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(dashboard|employees|leave|overtime|recruit|training|attendance|appraisal|common|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    /** The value shown on a card (text right after the card title, up to the next tag). */
    private function card(string $html, string $title): string
    {
        $i = mb_strpos($html, '>' . $title . '</h2>');
        self::assertNotFalse($i, 'card ' . $title);
        $chunk = mb_substr($html, (int) $i, 1500);
        return $chunk;
    }

    private function cardValue(string $html, string $title): string
    {
        preg_match('#dash-card__value">([^<]*)<#u', $this->card($html, $title), $m);
        return trim(html_entity_decode($m[1] ?? ''));
    }

    public function testDashboard(): void
    {
        $db = $this->t->app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $lePid = (string) $db->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$le]);
        $sales = (string) $db->scalar("SELECT public_id FROM departments WHERE code = 'SALES' AND legal_entity_id = ?", [$le]);
        self::assertSame(6, (int) $db->scalar("SELECT COUNT(*) FROM dash_kpis WHERE legal_entity_id = ? AND status = 'active'", [$le]), 'demo KPIs');

        // access
        self::assertSame(403, $this->browser('employee.demo1')->get('/dashboard')->status);
        $ex = $this->browser('exec.demo1');
        $p = $ex->get('/dashboard');
        self::assertSame(200, $p->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($p->body));
        self::assertSame(403, $ex->get('/dashboard/kpis')->status, 'executives read, data owners define');

        // TC-KPI: headcount equals the employee records (active + on leave, current job in DEMO1)
        $expected = (int) $db->scalar("SELECT COUNT(*) FROM employees e JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL WHERE h.legal_entity_id = ? AND e.status IN ('active','on_leave')", [$le]);
        self::assertSame((string) $expected, $this->cardValue($p->body, 'จำนวนพนักงาน'));
        self::assertStringContainsString('ล่าสุด', $this->card($p->body, 'จำนวนพนักงาน'));
        self::assertStringNotContainsString('ดูข้อมูลต้นทาง', $p->body, 'no source link without the source permission');

        // HR (has employees.view) gets the drill-down link; department filter narrows; a company-only measure is marked partial
        $hr = $this->browser('hr.demo1');
        $h = $hr->get('/dashboard', ['le' => $lePid, 'dept' => $sales]);
        self::assertSame(200, $h->status, $this->errorsLogged());
        $salesN = (int) $db->scalar("SELECT COUNT(*) FROM employees e JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL JOIN departments d ON d.id = h.department_id WHERE d.code = 'SALES' AND e.status IN ('active','on_leave')");
        self::assertSame((string) $salesN, $this->cardValue($h->body, 'จำนวนพนักงาน'));
        self::assertStringContainsString('ดูข้อมูลต้นทาง', $this->card($h->body, 'จำนวนพนักงาน'));
        self::assertStringContainsString('ไม่ครบตามตัวกรอง', $this->card($h->body, 'คำขอที่รออนุมัติ'));

        // site-scoped executive: only BR1; a measure that cannot be split by site is not shown
        $br = $this->browser('exec.br1');
        $b = $br->get('/dashboard');
        self::assertSame(200, $b->status, $this->errorsLogged());
        self::assertStringContainsString('คุณเห็นเฉพาะสาขา/หน่วยงานตามสิทธิ์', $b->body);
        $br1N = (int) $db->scalar("SELECT COUNT(*) FROM employees e JOIN employment_history h ON h.employee_id = e.id AND h.effective_to IS NULL JOIN sites s ON s.id = h.site_id WHERE s.code = 'BR1' AND h.legal_entity_id = ? AND e.status IN ('active','on_leave')", [$le]);
        self::assertSame((string) $br1N, $this->cardValue($b->body, 'จำนวนพนักงาน'));
        self::assertLessThan($expected, $br1N);
        self::assertStringContainsString('ไม่มีสิทธิ์ดูภาพรวม', $this->card($b->body, 'คำขอที่รออนุมัติ'));
        self::assertSame('—', $this->cardValue($b->body, 'คำขอที่รออนุมัติ'));
        // asking for another site's department falls back to the person's own scope
        $hq = (string) $db->scalar("SELECT public_id FROM departments WHERE code = 'HR' AND legal_entity_id = ?", [$le]);
        self::assertSame((string) $br1N, $this->cardValue($br->get('/dashboard', ['dept' => $hq])->body, 'จำนวนพนักงาน'));

        // turnover: a leaver this month → leavers ÷ average headcount × 100, parts shown to reconcile
        $db->run("UPDATE employees SET termination_date = '2026-11-05', status = 'former' WHERE employee_code = 'D1-0007'");
        self::assertSame(303, $hr->post('/dashboard/refresh', ['le' => $lePid, 'site' => '', 'dept' => '', 'month' => '2026-11'])->status);
        $h = $hr->get('/dashboard');
        $avg = ($expected + ($expected - 1)) / 2;
        self::assertSame(number_format(1 / $avg * 100, 2) . '%', $this->cardValue($h->body, 'อัตราการลาออก'));
        self::assertStringContainsString('พ้นสภาพ 1', $this->card($h->body, 'อัตราการลาออก'));
        self::assertSame((string) ($expected - 1), $this->cardValue($h->body, 'จำนวนพนักงาน'));

        // dictionary rules: one measure = one KPI, one name = one KPI, thresholds need a direction, alert beyond warn
        $kp = $hr->get('/dashboard/kpis');
        self::assertSame(200, $kp->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($kp->body));
        $base = ['le' => $lePid, 'code' => 'TRN-HRS', 'measure' => 'training.hours', 'name_th' => 'ชั่วโมงอบรม', 'name_en' => 'Training hours', 'purpose' => 'ติดตามการพัฒนา', 'direction' => 'up',
            'warn_at' => '', 'alert_at' => '', 'refresh_minutes' => '360', 'owner' => '', 'status' => 'draft', 'sort_order' => '70', 'reason' => ''];
        $r = $hr->post('/dashboard/kpis', ['measure' => 'employees.headcount'] + $base);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('ตัววัดเดียวกันคือสูตรเดียวกัน', $r->body);
        self::assertSame(422, $hr->post('/dashboard/kpis', ['name_th' => 'จำนวนพนักงาน'] + $base)->status);
        self::assertSame(422, $hr->post('/dashboard/kpis', ['direction' => 'none', 'warn_at' => '5'] + $base)->status);
        $r = $hr->post('/dashboard/kpis', ['warn_at' => '5', 'alert_at' => '8'] + $base); // higher is better: alert must be lower than warn
        self::assertSame(422, $r->status);
        self::assertStringContainsString('เกณฑ์แจ้งเตือนต้องเลยเกณฑ์เฝ้าระวัง', $r->body);
        self::assertSame(303, $hr->post('/dashboard/kpis', $base)->status, $this->errorsLogged());
        $kpid = (string) $db->scalar("SELECT public_id FROM dash_kpis WHERE code = 'TRN-HRS'");
        self::assertSame(422, $hr->post('/dashboard/kpis/' . $kpid, ['status' => 'active'] + $base)->status, 'a live KPI needs an owner');
        self::assertSame(303, $hr->post('/dashboard/kpis/' . $kpid, ['status' => 'active', 'owner' => 'hr.head1'] + $base)->status);
        self::assertSame(1, (int) $db->scalar("SELECT version FROM dash_kpis WHERE code = 'TRN-HRS'"));
        $live = ['status' => 'active', 'owner' => 'hr.head1', 'warn_at' => '10', 'alert_at' => '2'] + $base;
        $r = $hr->post('/dashboard/kpis/' . $kpid, $live);
        self::assertSame(422, $r->status, 'changing a live KPI needs a reason');
        self::assertSame(303, $hr->post('/dashboard/kpis/' . $kpid, ['reason' => 'ตั้งเกณฑ์ตามแผนพัฒนา'] + $live)->status);
        self::assertSame(2, (int) $db->scalar("SELECT version FROM dash_kpis WHERE code = 'TRN-HRS'"));
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM dash_kpi_versions v JOIN dash_kpis k ON k.id = v.kpi_id WHERE k.code = 'TRN-HRS'"));
        $def = $ex->get('/dashboard/k/' . $kpid);
        self::assertSame(200, $def->status, $this->errorsLogged());
        self::assertStringContainsString('ผลรวมชั่วโมงหลักสูตร', $def->body);
        self::assertStringContainsString('ตั้งเกณฑ์ตามแผนพัฒนา', $def->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('#<code[^>]*>.*?</code>#s', '', $def->body)));
        // the new KPI shows with the alert level (0 hours ≤ alert 2), and values carry the version
        $h = $hr->get('/dashboard');
        self::assertStringContainsString('ถึงเกณฑ์แจ้งเตือน', $this->card($h->body, 'ชั่วโมงอบรม'));
        self::assertSame(2, (int) $db->scalar("SELECT MAX(v.version) FROM dash_values v JOIN dash_kpis k ON k.id = v.kpi_id WHERE k.code = 'TRN-HRS'"));

        // alerts: the job tells the owner once per month and level
        self::assertGreaterThan(0, DashboardService::refreshJob($this->t->app));
        DashboardService::refreshJob($this->t->app);
        $owner = (int) $this->t->user('hr.head1')['id'];
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'dashboard.alert'", [$owner]));

        // stale after the refresh interval; refresh makes it fresh again
        $this->t->clock->advance('+2 hours');
        $hr = $this->browser('hr.demo1');
        $h = $hr->get('/dashboard', ['dept' => $sales]);
        self::assertStringContainsString('เก่า', $this->card($h->body, 'จำนวนพนักงาน'));
        self::assertSame(303, $hr->post('/dashboard/refresh', ['le' => $lePid, 'site' => '', 'dept' => $sales, 'month' => '2026-11'])->status);
        self::assertStringContainsString('ล่าสุด', $this->card($hr->get('/dashboard', ['dept' => $sales])->body, 'จำนวนพนักงาน'));

        // earlier month: point-in-time figures say so; month totals compute for that month
        $old = $hr->get('/dashboard', ['month' => '2026-10']);
        self::assertSame(200, $old->status, $this->errorsLogged());
        self::assertStringContainsString('ดูได้เฉพาะเดือนปัจจุบัน', $this->card($old->body, 'คำขอที่รออนุมัติ'));
        self::assertSame((string) $expected, $this->cardValue($old->body, 'จำนวนพนักงาน'));

        // source module switched off: the card says so (error) instead of failing the page
        $db->run("UPDATE modules SET enabled = 0 WHERE code = 'overtime'");
        $this->t->app->modules()->forget();
        $off = $hr->get('/dashboard');
        self::assertSame(200, $off->status, $this->errorsLogged());
        self::assertStringContainsString('ไม่พบตัววัด', $this->card($off->body, 'ชั่วโมง OT ที่อนุมัติ'));
        self::assertStringContainsString('คำนวณไม่สำเร็จ', $this->card($off->body, 'ชั่วโมง OT ที่อนุมัติ'));
        $db->run("UPDATE modules SET enabled = 1 WHERE code = 'overtime'");
        $this->t->app->modules()->forget();

        // export (audited) — signed in again after the clock moved
        $ex = $this->browser('exec.demo1');
        $br = $this->browser('exec.br1');
        $x = $ex->get('/dashboard/export');
        self::assertSame(200, $x->status, $this->errorsLogged());
        $csv = $ex->get('/dashboard/export', ['format' => 'csv']);
        self::assertStringContainsString('HEADCOUNT', $csv->body);
        self::assertStringContainsString('ผลรวม', $csv->body);
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'dashboard.exported'"));

        // snapshot as evidence
        $r = $ex->post('/dashboard/snapshots', ['le' => $lePid, 'site' => '', 'dept' => '', 'month' => '2026-11', 'note' => 'สั้น']);
        self::assertSame(422, $r->status);
        $r = $ex->post('/dashboard/snapshots', ['le' => $lePid, 'site' => '', 'dept' => '', 'month' => '2026-11', 'note' => 'ประชุมผู้บริหาร พ.ย.']);
        self::assertSame(303, $r->status);
        $spid = (string) $db->scalar('SELECT public_id FROM dash_snapshots ORDER BY id DESC LIMIT 1');
        $sv = $ex->get('/dashboard/snapshots/' . $spid);
        self::assertSame(200, $sv->status, $this->errorsLogged());
        self::assertStringContainsString('ประชุมผู้บริหาร พ.ย.', $sv->body);
        self::assertStringContainsString('HEADCOUNT', $sv->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($sv->body));
        self::assertStringContainsString('ประชุมผู้บริหาร', $ex->get('/dashboard/snapshots')->body);
        self::assertSame(404, $br->get('/dashboard/snapshots/' . $spid)->status, 'a partial viewer sees only their own snapshots');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'dashboard.snapshot'"));
        self::assertStringNotContainsString('PHP Warning', $this->errorsLogged());
        self::assertStringNotContainsString('PHP Fatal', $this->errorsLogged());
    }
}
