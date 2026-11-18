<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Modules\Contractors\ContractorService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9C-3 (DEC-96): M44 contractor management. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). Demo: three document
 * types (company contract — no expiry; liability insurance — expires; worker safety induction — expires), company
 * "บริษัท ช่างดีเซอร์วิส (DEMO)" owned by ga.demo1 with two workers and no documents. ga.demo1 requests; safety.demo1
 * (a second WPA) approves.
 */
final class ContractorsTest extends TestCase
{
    private TestApp $t;

    private const COMPANY = 'บริษัท ช่างดีเซอร์วิส (DEMO)';

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(contractors|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function svc(): ContractorService
    {
        return ContractorService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function company(string $name = self::COMPANY): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM ct_companies WHERE name = ? AND legal_entity_id = ?', [$name, $this->le()]);
    }

    private function worker(string $name): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM ct_workers WHERE name = ?', [$name]);
    }

    private function type(string $like): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM ct_doc_types WHERE legal_entity_id = ? AND name LIKE ?', [$this->le(), $like . '%']);
    }

    private function site(string $code): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM sites WHERE code = ? AND legal_entity_id = ?', [$code, $this->le()]);
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->t->app->db()->one('SELECT * FROM ct_permits WHERE public_id = ?', [$pid]) ?? [];
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fromBytes('doc.pdf', (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/a.pdf'), $this->t->tmp);
    }

    private function doc(string $type, ?string $worker, ?string $until, string $company = self::COMPANY): void
    {
        $this->svc()->addDocument($this->az('ga.demo1'), $this->company($company), ['doc_type' => $this->type($type), 'worker' => $worker === null ? '' : $this->worker($worker), 'valid_from' => '2026-01-01', 'valid_until' => $until ?? ''], $this->pdf());
    }

    /** Company contract + insurance, and the induction for both workers, valid until $until */
    private function qualify(string $until = '2027-06-30'): void
    {
        $this->doc('สัญญา', null, null);
        $this->doc('กรมธรรม์', null, $until);
        $this->doc('อบรม', 'ช่างไฟฟ้า ก (DEMO)', $until);
        $this->doc('อบรม', 'ผู้ช่วยช่าง ข (DEMO)', $until);
    }

    /**
     * @param list<string> $workers
     * @param array<string, mixed> $extra
     */
    private function draft(array $workers, string $from = '2026-11-09T10:00', string $to = '2026-11-10T17:00', array $extra = []): string
    {
        return $this->svc()->savePermit($this->az('ga.demo1'), $this->le(), $extra + [
            'company' => $this->company(), 'site' => $this->site('BR1'), 'work' => 'เดินสายไฟห้องประชุม', 'area' => 'ห้องประชุม ชั้น 2',
            'tools' => 'บันได สว่าน', 'hazards' => ['electrical', 'height'], 'controls' => 'ตัดไฟก่อนทำงาน ใช้เข็มขัดนิรภัย',
            'starts_at' => $from, 'ends_at' => $to, 'supervisor' => 'employee.demo1', 'workers' => array_map(fn (string $w): string => $this->worker($w), $workers),
        ]);
    }

    private function decide(string $pid, string $user, string $decision): void
    {
        $this->t->app->workflow()->decide((string) $this->row($pid)['workflow_id'], (int) $this->t->user($user)['id'], $decision, 'ok');
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

    public function testDocumentsPermitApprovalGateAndCompletion(): void
    {
        $s = $this->svc();
        $ga = $this->az('ga.demo1');
        $db = $this->t->app->db();
        $A = 'ช่างไฟฟ้า ก (DEMO)';
        $B = 'ผู้ช่วยช่าง ข (DEMO)';
        // G9C3-01: no documents → the company shows pending and a permit cannot be sent
        $list = $s->companyList($ga, $this->le());
        self::assertNotSame([], $list[0]['missing']);
        $p1 = $this->draft([$A, $B]);
        self::assertSame('draft', $this->row($p1)['status']);
        self::assertNotSame([], $s->problems($s->permit($ga, $p1)));
        self::assertSame('contractors.error.problems', $this->refused(fn () => $s->submit($ga, $p1)));
        // document rules: expiry needed, worker vs company
        self::assertSame('contractors.error.expiry_needed', $this->errors(fn () => $this->doc('กรมธรรม์', null, null))['valid_until']);
        self::assertSame('contractors.error.worker_needed', $this->errors(fn () => $this->doc('อบรม', null, '2027-01-01'))['worker']);
        self::assertSame('contractors.error.company_doc', $this->errors(fn () => $this->doc('สัญญา', $A, null))['worker']);
        // a document ending inside the window does not qualify the window
        $this->doc('สัญญา', null, null);
        $this->doc('กรมธรรม์', null, '2026-11-09');
        $this->doc('อบรม', $A, '2027-06-30');
        $this->doc('อบรม', $B, '2027-06-30');
        $probs = $s->problems($s->permit($ga, $p1));
        self::assertCount(1, $probs);
        $this->doc('กรมธรรม์', null, '2027-06-30');
        self::assertSame([], $s->problems($s->permit($ga, $p1)));
        self::assertSame([], $s->companyList($ga, $this->le())[0]['missing']);
        // validation: hazards need controls, past start, more than 31 days, no workers
        $e = $this->errors(fn () => $this->draft([$A], '2026-11-09T08:00', '2026-12-30T17:00', ['controls' => '']));
        self::assertSame(['contractors.error.controls', 'contractors.error.past'], [$e['controls'], $e['starts_at']]);
        self::assertSame('contractors.error.too_long', $this->errors(fn () => $this->draft([$A], '2026-11-10T08:00', '2026-12-30T17:00'))['starts_at']);
        self::assertSame('contractors.error.workers', $this->errors(fn () => $this->draft([]))['workers']);
        // G9C3-02: send → review with a number; the requester cannot approve their own permit
        $s->submit($ga, $p1);
        $r = $this->row($p1);
        self::assertSame(['review', 'CP2611-0001'], [$r['status'], $r['ref']]);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM workflow_tasks t JOIN users u ON u.id = t.assignee_id WHERE t.instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) AND u.username = 'ga.demo1' AND t.status = 'pending'", [$r['workflow_id']]));
        // G9C3-03: the same worker on another permit at the same time → overlap problem
        $p2 = $this->draft([$A], '2026-11-10T08:00', '2026-11-10T12:00');
        self::assertStringContainsString('CP2611-0001', implode(' ', $s->problems($s->permit($ga, $p2))));
        $p3 = $this->draft([$B], '2026-11-11T08:00', '2026-11-11T12:00');
        self::assertSame([], $s->problems($s->permit($ga, $p3)));
        $this->decide($p1, 'safety.demo1', 'approve');
        self::assertSame('approved', $this->row($p1)['status']);
        self::assertGreaterThanOrEqual(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'contractors.permit'", [$this->t->user('employee.demo1')['id']]));
        // G9C3-04: the gate refuses before the window, admits inside it, the first check-in → on site
        $gate = $this->az('ga.demo1');
        self::assertSame('contractors.error.outside_window', $this->refused(fn () => $s->gate($gate, $p1, $this->worker($A), true)));
        $this->t->clock->advance('+90 minutes');
        $s->gate($gate, $p1, $this->worker($A), true);
        self::assertSame('on_site', $this->row($p1)['status']);
        self::assertSame('contractors.error.already_inside', $this->refused(fn () => $s->gate($gate, $p1, $this->worker($A), true)));
        self::assertSame('contractors.error.not_inside', $this->refused(fn () => $s->gate($gate, $p1, $this->worker($B), false)));
        self::assertCount(1, $s->atGate($gate));
        // G9C3-05: complete needs everyone out, the checks and a rating (≤ 2 needs a note); the supervisor can close
        $sup = $this->az('employee.demo1');
        self::assertSame('contractors.error.still_inside', $this->refused(fn () => $s->complete($sup, $p1, ['area_checked' => '1', 'equipment_returned' => '1', 'rating' => '4'])));
        $s->gate($gate, $p1, $this->worker($A), false);
        $e = $this->errors(fn () => $s->complete($sup, $p1, ['rating' => '2']));
        self::assertSame(['contractors.error.area_checked', 'contractors.error.equipment_returned', 'contractors.error.low_rating_note'], [$e['area_checked'], $e['equipment_returned'], $e['review_note']]);
        $s->complete($sup, $p1, ['area_checked' => '1', 'equipment_returned' => '1', 'rating' => '4', 'review_note' => '']);
        $r = $this->row($p1);
        self::assertSame(['completed', 4], [$r['status'], (int) $r['rating']]);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM ct_checkins WHERE permit_id = ? AND out_at IS NOT NULL', [$r['id']]));
        self::assertEqualsWithDelta(4.0, (float) $s->companyList($ga, $this->le())[0]['avg_rating'], 0.01);
        // another user without contractor rights cannot see the permit
        self::assertSame('http404', $this->refused(fn () => $s->permit($this->az('hr.demo1'), $p1)));
        // the supervisor sees it
        self::assertTrue($s->permit($sup, $p1)['is_supervisor']);
    }

    public function testSuspensionTickAlertsAndScreens(): void
    {
        $s = $this->svc();
        $ga = $this->az('ga.demo1');
        $db = $this->t->app->db();
        $A = 'ช่างไฟฟ้า ก (DEMO)';
        $B = 'ผู้ช่วยช่าง ข (DEMO)';
        $this->qualify('2026-11-30');
        $s->saveSettings($ga, $this->le(), '30');
        $p1 = $this->draft([$A], '2026-11-09T10:00', '2026-11-09T17:00');
        $s->submit($ga, $p1);
        $this->decide($p1, 'safety.demo1', 'approve');
        $p2 = $this->draft([$B], '2026-11-12T08:00', '2026-11-12T17:00');
        $s->submit($ga, $p2);
        // G9C3-06: suspension → live permits suspended (review voided), the gate refuses, sending is blocked
        self::assertSame('contractors.error.reason', $this->errors(fn () => $s->suspend($ga, $this->company(), true, ''))['suspend_reason']);
        $s->suspend($ga, $this->company(), true, 'ไม่ปฏิบัติตามกฎความปลอดภัย');
        self::assertSame(['suspended', 'suspended'], [$this->row($p1)['status'], $this->row($p2)['status']]);
        self::assertSame('cancelled', (string) $db->scalar('SELECT status FROM workflow_instances WHERE public_id = ?', [$this->row($p2)['workflow_id']]));
        $this->t->clock->advance('+90 minutes');
        self::assertSame('contractors.error.not_approved', $this->refused(fn () => $s->gate($ga, $p1, $this->worker($A), true)));
        $p3 = $this->draft([$B], '2026-11-13T08:00', '2026-11-13T17:00');
        self::assertSame('contractors.error.problems', $this->refused(fn () => $s->submit($ga, $p3)));
        $s->suspend($ga, $this->company(), false, '');
        self::assertSame(0, (int) $db->scalar('SELECT suspended FROM ct_companies WHERE public_id = ?', [$this->company()]));
        // G9C3-07: an approved permit nobody used → expired after its end; documents expiring within 30 days → one alert
        $s->submit($ga, $p3);
        $this->decide($p3, 'safety.demo1', 'approve');
        $n = $s->tick();
        self::assertGreaterThanOrEqual(3, $n, 'insurance + 2 inductions expire 30 Nov');
        self::assertSame(3, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'contractors.expiry'", [$this->t->user('ga.demo1')['id']]));
        $s->tick();
        self::assertSame(3, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'contractors.expiry'", [$this->t->user('ga.demo1')['id']]), 'once per document');
        $this->t->clock->advance('+5 days');
        $s->tick();
        self::assertSame('expired', $this->row($p3)['status']);
        // screens: no untranslated keys; gate check-in through the web; employee has no access
        $g = $this->t->browser();
        $g->login('ga.demo1');
        $cpid = $this->company();
        foreach (['/contractors', '/contractors/c/' . $cpid, '/contractors/permits', '/contractors/permits/new', '/contractors/p/' . $p1, '/contractors/p/' . $p3, '/contractors/gate', '/contractors/settings'] as $path) {
            $res = $g->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        $form = $g->get('/contractors/permits/new', ['company' => $cpid]);
        self::assertSame(200, $form->status);
        self::assertSame(303, $g->post('/contractors', ['name' => 'บริษัท ทดสอบ จำกัด', 'contact_name' => 'คุณเอ', 'contact_email' => 'a@example.invalid', 'owner' => 'ga.demo1'])->status);
        $new = $this->company('บริษัท ทดสอบ จำกัด');
        self::assertSame(303, $g->post('/contractors/c/' . $new . '/worker', ['name' => 'ช่างทดสอบ'])->status);
        self::assertSame(303, $g->post('/contractors/c/' . $new . '/document', ['doc_type' => $this->type('สัญญา'), 'worker' => '', 'valid_from' => '', 'valid_until' => ''], ['file' => ['c.pdf', (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/a.pdf')]])->status);
        self::assertSame(422, $g->post('/contractors/c/' . $new . '/document', ['doc_type' => $this->type('กรมธรรม์'), 'worker' => ''])->status);
        $fid = (int) $db->scalar('SELECT d.id FROM ct_documents d JOIN ct_companies c ON c.id = d.company_id WHERE c.public_id = ?', [$new]);
        self::assertSame(200, $g->get('/contractors/c/' . $new . '/f/' . $fid)->status);
        self::assertSame(303, $g->post('/contractors/settings/type', ['name' => 'ใบอนุญาตทำงานในที่อับอากาศ', 'applies_to' => 'worker', 'required' => '', 'needs_expiry' => '1'])->status);
        $emp = $this->t->browser();
        $emp->login('hr.demo1');
        self::assertSame(403, $emp->get('/contractors')->status);
        self::assertContains($emp->get('/contractors/c/' . $cpid)->status, [403, 404]);
    }
}
