<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use Blue\Core\Authz\Authorizer;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Safety\SafetyPolicy;
use Blue\Modules\Safety\SafetyService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;
use PHPUnit\Framework\TestCase;

/**
 * 0.45.0 (R7): the safety policy — an AI-drafted, labelled starting policy (severity levels, alert chain with escalation,
 * likelihood / impact scales, risk bands), edited by the safety team and published by another approver with the reviewer
 * recorded; once published it drives the level meanings, who is alerted, the escalation of untriaged reports and the risk
 * band on the investigation. Printable internal forms (report, near-miss card, witness statement, risk matrix) and the
 * investigation sheet.
 */
final class SafetyPolicyWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(safety|form)\.[a-z_]+(\.[a-z_\-]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
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

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function uid(string $user): int
    {
        return (int) $this->t->user($user)['id'];
    }

    /** @return array<string, mixed> the policy form filled from the AI template, with overrides */
    private function form(array $over = []): array
    {
        $p = SafetyPolicy::template('th');
        $in = ['_save' => '1'];
        foreach (SafetyPolicy::LEVELS as $l) {
            $in['name_' . $l] = $p['levels'][$l]['name'];
            $in['meaning_' . $l] = $p['levels'][$l]['meaning'];
            $in['examples_' . $l] = $p['levels'][$l]['examples'];
            foreach (SafetyPolicy::WHO as $w) {
                if ($p['chain'][$l][$w]) {
                    $in[$w . '_' . $l] = '1';
                }
            }
            $in['after_' . $l] = (string) ($p['chain'][$l]['after_min'] ?? '');
            $in['then_' . $l] = $p['chain'][$l]['then'];
        }
        foreach (SafetyPolicy::SCALE as $s) {
            $in['likelihood_' . $s] = $p['likelihood'][$s];
            $in['impact_' . $s] = $p['impact'][$s];
        }
        foreach ($p['bands'] as $b) {
            if ($b['key'] !== 'very_high') {
                $in['band_max_' . $b['key']] = (string) $b['max'];
            }
            $in['band_action_' . $b['key']] = $b['action'];
        }
        return $over + $in;
    }

    private function notes(string $user, string $type): int
    {
        return (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'safety.incident' AND title_key = ?", [$this->uid($user), $type]);
    }

    public function testDraftPublishAndThePolicyAtWork(): void
    {
        $db = $this->t->app->db();
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $sfo = $this->as('ga.demo1');
        $page = $sfo->get('/safety/policy');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('ยังไม่มีนโยบายที่เผยแพร่', $page->body);
        self::assertSame(403, $this->as('employee.demo1')->get('/safety/policy')->status, 'employees do not edit the policy');

        // the AI draft — labelled; one draft at a time
        self::assertSame(303, $sfo->post('/safety/policy/draft', ['from' => 'ai'])->status);
        $d = $db->one("SELECT * FROM sf_policies WHERE legal_entity_id = ? AND status = 'draft'", [$le]);
        self::assertNotNull($d);
        self::assertSame([1, 1], [(int) $d['ai_draft'], (int) $d['version']]);
        self::assertStringContainsString('ร่างโดย AI — ต้องตรวจก่อนใช้', $sfo->get('/safety/policy')->body);
        $sfo->post('/safety/policy/draft', ['from' => 'ai']);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM sf_policies'), 'one draft at a time');
        $content = json_decode((string) $d['content_json'], true);
        self::assertSame(30, $content['chain']['4']['after_min'], 'AI default: level 4 escalates after 30 minutes');

        // editing: validation, then a change
        $bad = $sfo->post('/safety/policy/save', $this->form(['after_3' => '2', 'band_max_low' => '30', 'name_1' => '']));
        self::assertSame(422, $bad->status);
        foreach (['ใส่ 5–10,080 นาที', 'คะแนนต้องมากกว่าระดับก่อนหน้า'] as $msg) {
            self::assertStringContainsString($msg, $bad->body);
        }
        self::assertSame(303, $sfo->post('/safety/policy/save', $this->form(['name_3' => 'ร้ายแรง (ทดสอบ)']))->status);

        // publishing: not by the person who drafted / edited; reviewer and date required
        $sfo->post('/safety/policy/publish', ['reviewed' => '1', 'reviewer' => 'ผู้ตรวจ', 'reviewed_on' => '2026-11-08']);
        self::assertSame('draft', $db->scalar('SELECT status FROM sf_policies WHERE id = ?', [$d['id']]), 'the editor cannot publish');
        $exec = $this->as('exec.demo1');
        self::assertSame(200, $exec->get('/safety/policy')->status, 'executives approve the policy');
        self::assertSame(422, $exec->post('/safety/policy/publish', ['reviewer' => 'ผู้ตรวจ', 'reviewed_on' => '2099-01-01'])->status);
        self::assertSame(303, $exec->post('/safety/policy/publish', ['reviewed' => '1', 'reviewer' => 'หัวหน้าฝ่ายผลิต (ทำหน้าที่ จป. ชั่วคราว)', 'reviewed_on' => '2026-11-08'])->status);
        $p = $db->one('SELECT * FROM sf_policies WHERE id = ?', [$d['id']]);
        self::assertSame(['published', 0, $this->uid('exec.demo1')], [$p['status'], (int) $p['ai_draft'], (int) $p['published_by']]);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'safety.policy_published'"));
        self::assertStringContainsString('ร้ายแรง (ทดสอบ)', $sfo->get('/safety/report')->body, 'the report form shows the policy meaning');

        // alert chain: level 3 → the team + the reporter's manager + HR at once, not executives
        $svc = SafetyService::of($this->t->app);
        $r3 = $svc->report($this->az('employee.demo1'), ['kind' => 'injury', 'severity' => '3', 'occurred_at' => '2026-11-09T08:15', 'location' => 'ไลน์ผลิต 2',
            'description' => 'นิ้วถูกหนีบที่เครื่องอัด ต้องส่งโรงพยาบาล (ทดสอบ)']);
        $emps = EmployeeService::of($this->t->app);
        $mgrs = $emps->managerUserIds((int) ($emps->forUser($this->uid('employee.demo1'))['id'] ?? 0), '2026-11-09');
        self::assertNotSame([], $mgrs, 'the demo employee has a line manager');
        $mgr = (string) $db->scalar('SELECT username FROM users WHERE id = ?', [$mgrs[0]]);
        self::assertSame(1, $this->notes('ga.demo1', 'safety.notify.urgent'));
        self::assertSame(1, $this->notes($mgr, 'safety.notify.urgent'), 'the line manager');
        self::assertSame(1, $this->notes('hr.head1', 'safety.notify.urgent'), 'HR');
        self::assertSame(0, $this->notes('exec.demo1', 'safety.notify.urgent'), 'executives only by escalation at level 3');
        self::assertNotNull($db->scalar('SELECT alerted_at FROM sf_incidents WHERE public_id = ?', [$r3['pid']]));

        // escalation: not before 60 minutes; once after; never after triage
        $r4 = $svc->report($this->az('employee.demo1'), ['kind' => 'damage', 'severity' => '4', 'occurred_at' => '2026-11-09T08:30', 'location' => 'คลังสินค้า',
            'description' => 'ไฟไหม้ชั้นวางสินค้า ต้องอพยพอาคาร (ทดสอบ)']);
        self::assertSame(1, $this->notes('exec.demo1', 'safety.notify.urgent'), 'level 4 alerts executives at once');
        $svc->triage($this->az('ga.demo1'), $r4['pid'], ['kind' => 'damage', 'severity' => '4']);
        $this->t->clock->advance('+59 minutes');
        $svc->tick();
        self::assertSame(0, $this->notes('exec.demo1', 'safety.notify.escalated'));
        $this->t->clock->advance('+2 minutes');
        $svc->tick();
        $svc->tick();
        self::assertSame(1, $this->notes('exec.demo1', 'safety.notify.escalated'), 'level 3 escalates to executives once');
        self::assertSame(1, $this->notes('ga.demo1', 'safety.notify.escalated'), 'and reminds the team');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM sf_events e JOIN sf_incidents i ON i.id = e.incident_id WHERE i.public_id = ? AND e.event = 'escalated'", [$r3['pid']]));
        self::assertSame(0, (int) $db->scalar('SELECT escalated FROM sf_incidents WHERE public_id = ?', [$r4['pid']]), 'triaged in time: no escalation');

        // the risk band on the investigation, and the investigation sheet
        $sfo = $this->as('ga.demo1');
        $svc->triage($this->az('ga.demo1'), $r3['pid'], ['kind' => 'injury', 'severity' => '3']);
        $svc->assign($this->az('ga.demo1'), $r3['pid'], 'ga.demo1');
        $svc->investigate($this->az('ga.demo1'), $r3['pid'], ['facts' => 'ฝาครอบเครื่องอัดถูกถอดออกระหว่างกะ', 'root_cause' => 'ไม่มีอุปกรณ์ล็อกฝาครอบ', 'likelihood' => '4', 'impact' => '4']);
        $show = $sfo->get('/safety/i/' . $r3['pid'])->body;
        self::assertStringContainsString('ต้องมีมาตรการลดความเสี่ยงก่อนทำงานนั้นต่อ', $show, '16 = high band and its action');
        $sheet = $sfo->get('/safety/i/' . $r3['pid'] . '/sheet');
        self::assertSame(200, $sheet->status);
        self::assertStringContainsString('ไม่มีอุปกรณ์ล็อกฝาครอบ', $sheet->body);
        self::assertStringContainsString('paper-doc', $sheet->body);
        self::assertSame(404, $this->as('employee.demo1')->get('/safety/i/' . $r3['pid'] . '/sheet')->status, 'the reporter does not get the investigation sheet');
        self::assertSame(0, preg_match(self::UNTRANSLATED, strip_tags($sheet->body)));

        // a new draft from the policy in use, then discarded
        self::assertSame(303, $sfo->post('/safety/policy/draft', ['from' => 'current'])->status);
        self::assertSame(0, (int) $db->scalar("SELECT ai_draft FROM sf_policies WHERE status = 'draft'"));
        self::assertSame(303, $sfo->post('/safety/policy/discard', [])->status);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM sf_policies WHERE status = 'draft'"));
    }

    public function testPrintableForms(): void
    {
        $emp = $this->as('employee.demo1');
        $idx = $emp->get('/safety/forms');
        self::assertSame(200, $idx->status);
        self::assertStringContainsString('ไม่ใช่แบบฟอร์มทางราชการ', $idx->body);
        self::assertStringContainsString('มาจากร่างของ AI', $idx->body, 'no policy yet: the AI template is used and said so');
        foreach (['report' => 'ระดับ', 'near-miss' => 'อาจเกิดอะไรขึ้นได้', 'witness' => 'ลงชื่อผู้ให้คำให้การ', 'risk' => 'สูงมาก'] as $f => $text) {
            $res = $emp->get('/safety/forms/' . $f);
            self::assertSame(200, $res->status, $f);
            self::assertStringContainsString('paper-doc', $res->body);
            self::assertStringContainsString('ร่างโดย AI ต้องตรวจก่อนใช้', $res->body);
            self::assertStringContainsString($text, $res->body);
            self::assertSame(0, preg_match(self::UNTRANSLATED, strip_tags($res->body)), $f . ': no raw translation keys');
        }
        $risk = $emp->get('/safety/forms/risk')->body;
        self::assertSame(1, substr_count($risk, '>25<'), 'one 25 cell');
        self::assertSame(3, substr_count($risk, 'sf-band--very_high'), 'the template bands: 20, 20 and 25 are very high');
        self::assertSame(404, $emp->get('/safety/forms/other')->status);
    }
}
