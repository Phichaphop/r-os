<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Pdf\Dict;
use Blue\Core\Pdf\Name;
use Blue\Core\Pdf\Stream;
use Blue\Core\Pdf\Writer;
use Blue\Modules\Appraisal\AppraisalService;
use Blue\Modules\Eid\CardService;
use Blue\Modules\Esign\EsignService;
use Blue\Modules\Forms\FormService;
use Blue\Modules\Training\TrainingService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 5 gate — cross-company sweep (P0-06 §10 Blocker: IDOR across companies; 7K.3 #1): objects of company DEMO1
 * from phase-5 modules, opened by people of DEMO2 (HR admin, employee), must never come back (404/403), and DEMO1's
 * own HR must still see them (the sweep is not a false pass).
 */
final class CrossCompanyPhase5Test extends TestCase
{
    private TestApp $t;

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

    public function testDemo2CannotOpenDemo1Objects(): void
    {
        $db = $this->t->app->db();
        $app = $this->t->app;
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $hr = $this->az('hr.demo1');
        $emp = (string) $db->scalar("SELECT public_id FROM employees WHERE employee_code = 'D1-0004'");

        // DEMO1 objects through the modules' own services
        $form = FormService::of($app)->save($hr, $le, ['code' => 'X-01', 'title' => 'แบบฟอร์มทดสอบ', 'category' => 'ทดสอบ', 'language' => 'th']);
        $course = TrainingService::of($app)->saveCourse($hr, $le, ['code' => 'SAFE-01', 'title' => 'ความปลอดภัย', 'category' => '', 'hours' => '3', 'pass_score' => '', 'valid_months' => '',
            'needs_approval' => '', 'prerequisite' => '', 'required_titles' => '', 'status' => '', 'description' => '', 'objectives' => '']);
        $aprForm = AppraisalService::of($app)->saveForm($hr, $le, ['code' => 'F-01', 'name' => 'แบบประเมิน', 'scale_max' => '5', 'bands' => '',
            'items' => [['section' => 'kpi', 'title' => 'งาน', 'weight' => '100', 'description' => '']]]);
        $card = CardService::of($app)->issue($hr, $emp);
        $w = new Writer();
        $c = $w->add(new Stream(new Dict(), 'BT ET'));
        $w->addPage(new Dict(['Type' => new Name('Page'), 'MediaBox' => [0, 0, 595, 842], 'Contents' => $c]));
        $sig = EsignService::of($app)->fromBytes($hr, $le, ['title' => 'สัญญาทดสอบ', 'expires_on' => '2026-11-20', 'sequential' => '1',
            'signers' => [['username' => 'employee.demo1', 'role' => 'พนักงาน', 'page' => 'last', 'anchor' => 'bottom-left']]], $w->output(), 'x.pdf');
        $kpi = (string) $db->scalar('SELECT public_id FROM dash_kpis WHERE legal_entity_id = ? LIMIT 1', [$le]);
        self::assertNotSame('', $kpi);
        $cardRow = $db->one('SELECT * FROM eid_cards WHERE public_id = ?', [$card]);
        self::assertNotNull($cardRow);

        $paths = [
            '/employees/' . $emp, '/eid/admin/e/' . $emp, '/forms/f/' . $form, '/forms/admin/f/' . $form, '/training/admin/courses/' . $course,
            '/appraisal/admin/forms/' . $aprForm, '/esign/r/' . $sig, '/dashboard/kpis/' . $kpi, '/dashboard/k/' . $kpi,
        ];
        $own = $this->t->browser();
        $own->login('hr.demo1');
        foreach ($paths as $p) {
            self::assertSame(200, $own->get($p)->status, 'DEMO1 HR sees ' . $p);
        }
        foreach (['hr.demo2'] as $user) {
            $b = $this->t->browser();
            $b->login($user);
            foreach ($paths as $p) {
                $res = $b->get($p);
                self::assertContains($res->status, [403, 404], $user . ' must not open ' . $p . ' (got ' . $res->status . ')');
                self::assertStringNotContainsString('พนักงานตัวอย่าง', $res->body, $user . ' sees no name on ' . $p);
            }
        }
        // a DEMO1 employee does not see HR pages of their own company either
        $e = $this->t->browser();
        $e->login('employee.demo1');
        foreach (['/employees/' . $emp, '/eid/admin/e/' . $emp, '/forms/admin/f/' . $form, '/training/admin/courses/' . $course, '/appraisal/admin/forms/' . $aprForm, '/dashboard/kpis/' . $kpi] as $p) {
            self::assertContains($e->get($p)->status, [403, 404], 'employee must not open ' . $p);
        }
    }
}
