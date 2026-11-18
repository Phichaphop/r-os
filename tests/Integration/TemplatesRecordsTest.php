<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Tests\Support\TestApp;

/** TC-REC part 2: template freeze (6.15), retention rules, legal holds, disposition with certificate (6.17.7). */
final class TemplatesRecordsTest extends TestCase
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

    private function tpl(): string
    {
        return (string) $this->t->app->db()->scalar("SELECT public_id FROM document_templates WHERE code = 'DEMO_EMPLOYMENT_CERT'");
    }

    /** @return array<string, string> */
    private function values(string $name = 'นายทดสอบ ระบบ'): array
    {
        return ['employee_name' => $name, 'position' => 'เจ้าหน้าที่', 'start_date' => '2024-01-15', 'purpose' => 'ยื่นธนาคาร', 'signer_name' => 'ผู้จัดการ', 'signer_title' => 'HR'];
    }

    public function testIssuedDocumentsAreFrozenWhenTheTemplateChangesLater(): void
    {
        $tpls = $this->t->app->templates();
        $hr = $this->az('hr.demo1');
        $first = $tpls->issue($hr, $this->tpl(), $this->values('<script>alert(1)</script>'), $this->le('DEMO1'), null, 'หนังสือรับรอง 1');
        self::assertSame('HRC-2026-0001', $first['number']);
        $html1 = $this->t->app->documents()->download($hr, $first['document'])['bytes'];
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html1, 'values are escaped');
        self::assertStringNotContainsString('<script>', $html1);
        self::assertStringContainsString('15 มกราคม 2567', $html1, 'date fields in Thai long form, Buddhist Era');
        self::assertStringContainsString('บริษัทตัวอย่าง จำกัด (DEMO)', $html1, 'company header');
        self::assertStringContainsString("'TH Sarabun New'", $html1, 'Country Pack TH default document font');
        self::assertSame('final', $this->t->app->db()->scalar('SELECT lifecycle_status FROM documents WHERE public_id = ?', [$first['document']]));
        $hash1 = (string) $this->t->app->db()->scalar('SELECT v.sha256 FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.public_id = ?', [$first['document']]);

        // the system admin edits the template: draft → publish v2 (scripts stripped, unknown placeholders refused)
        $sys = $this->az(TestApp::ADMIN);
        try {
            $tpls->saveDraft($sys, $this->tpl(), ['title' => 'x', 'body' => '<p>{{nope}}</p>', 'fields' => '', 'font_family' => '', 'show_header' => true, 'show_footer' => true, 'reason' => '']);
            self::fail('unknown placeholder accepted');
        } catch (ValidationException $e) {
            self::assertSame('template.error.unknown_placeholders', $e->errors['body']);
        }
        $tpls->saveDraft($sys, $this->tpl(), [
            'title' => 'หนังสือรับรองการทำงาน', 'body' => '<h1 onclick="x()">หนังสือรับรอง (ฉบับปรับปรุง)</h1><script>alert(2)</script><p>{{employee_name}} {{position}} {{start_date}} {{purpose}} {{signer_name}} {{signer_title}}</p>',
            'fields' => "employee_name | ชื่อ | text | required\nposition | ตำแหน่ง | text\nstart_date | เริ่มงาน | date\npurpose | เพื่อ | text\nsigner_name | ผู้ลงนาม | text\nsigner_title | ตำแหน่งผู้ลงนาม | text",
            'font_family' => '', 'show_header' => true, 'show_footer' => true, 'reason' => 'wording',
        ]);
        $draft = $tpls->get($this->tpl())['draft'];
        self::assertSame('<h1>หนังสือรับรอง (ฉบับปรับปรุง)</h1><p>{{employee_name}} {{position}} {{start_date}} {{purpose}} {{signer_name}} {{signer_title}}</p>', $draft['body_html']);
        $second = $tpls->issue($hr, $this->tpl(), $this->values(), $this->le('DEMO1'), null, 'หนังสือรับรอง 2');
        self::assertStringNotContainsString('ฉบับปรับปรุง', $this->t->app->documents()->download($hr, $second['document'])['bytes'], 'a draft is not used until published');
        self::assertSame(2, $tpls->publish($sys, $this->tpl(), 'new wording'));
        $third = $tpls->issue($hr, $this->tpl(), $this->values(), $this->le('DEMO1'), null, 'หนังสือรับรอง 3');
        self::assertStringContainsString('ฉบับปรับปรุง', $this->t->app->documents()->download($hr, $third['document'])['bytes']);
        self::assertSame('HRC-2026-0003', $third['number']);

        $after = $this->t->app->documents()->download($hr, $first['document'])['bytes'];
        self::assertSame($html1, $after, 'the issued document did not change');
        self::assertSame($hash1, hash('sha256', $after));
        $diff = $tpls->compare((string) $tpls->get($this->tpl())['versions'][1]['public_id'], (string) $tpls->get($this->tpl())['versions'][0]['public_id']);
        self::assertContains('+', array_column($diff, 0));
        self::assertContains('-', array_column($diff, 0));
    }

    public function testIssuingChecksRightsAndRequiredFields(): void
    {
        $tpls = $this->t->app->templates();
        try {
            $tpls->issue($this->az('hr.demo2'), $this->tpl(), $this->values(), $this->le('DEMO1'), null, 'x');
            self::fail('issued for another company');
        } catch (\Blue\Core\Error\HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        try {
            $tpls->issue($this->az('hr.demo1'), $this->tpl(), ['employee_name' => 'A'], $this->le('DEMO1'), null, 'x');
            self::fail('missing fields accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('f_position', $e->errors);
        }
        self::assertSame(0, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM number_issues'), 'no number used by a failed issue');
    }

    public function testRetentionRuleNeedsSourceAndSecondPersonThenSchedulesDisposalBlockedByHold(): void
    {
        $db = $this->t->app->db();
        self::assertGreaterThan(5, (int) $db->scalar("SELECT COUNT(*) FROM retention_rules WHERE is_ai_draft = 1 AND status = 'draft'"), 'AI drafts seeded');
        $hr = $this->az('hr.demo1');
        $pdf = DocumentsTest::PDF;
        $docs = $this->t->app->documents();
        $a = $docs->upload($hr, 'core.general', UploadedFile::fromBytes('a.pdf', $pdf, $this->t->tmp), ['title' => 'A', 'legal_entity_id' => $this->le('DEMO1')])['document'];
        $aKey = (string) $db->scalar('SELECT storage_key FROM document_versions ORDER BY id DESC LIMIT 1');
        $b = $docs->upload($hr, 'core.general', UploadedFile::fromBytes('b.pdf', $pdf . '%b', $this->t->tmp), ['title' => 'B', 'legal_entity_id' => $this->le('DEMO1')])['document'];
        $docs->finalize($hr, $a);
        $docs->finalize($hr, $b);
        self::assertNull($db->scalar('SELECT scheduled_disposition_date FROM documents WHERE public_id = ?', [$a]), 'no approved rule → no date');

        $sys = $this->az(TestApp::ADMIN);
        $rules = $this->t->app->retention();
        $pid = $rules->propose($sys, 'general_document', ['trigger' => 'finalized', 'months' => '12', 'action' => 'destroy', 'note' => '', 'source' => '', 'effective_from' => '2026-11-01', 'reason' => 'policy']);
        try {
            $rules->submit($sys, $pid);
            self::fail('submitted without a source reference');
        } catch (ValidationException $e) {
            self::assertSame('records.error.source_required', $e->errors['source']);
        }
        $pid = $rules->propose($sys, 'general_document', ['trigger' => 'finalized', 'months' => '12', 'action' => 'destroy', 'note' => '', 'source' => 'Legal memo TEST-1', 'effective_from' => '2026-11-01', 'reason' => 'policy']);
        $inst = $rules->submit($sys, $pid);
        $wf = $this->t->app->workflow();
        try {
            $wf->decide($inst, (int) $this->t->user(TestApp::ADMIN)['id'], 'approve');
            self::fail('the proposer approved their own rule');
        } catch (\Blue\Core\Error\HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        self::assertSame('in_review', $db->scalar('SELECT status FROM retention_rules WHERE public_id = ?', [$pid]));
        $wf->decide($inst, (int) $this->t->user('sysadmin2.demo')['id'], 'approve');
        self::assertSame('approved', $db->scalar('SELECT status FROM retention_rules WHERE public_id = ?', [$pid]));
        self::assertSame('2027-11-09', $db->scalar('SELECT scheduled_disposition_date FROM documents WHERE public_id = ?', [$a]));

        $this->t->clock->advance('+400 days');
        self::assertCount(2, $this->t->app->disposition()->due($sys));
        $hold = $this->t->app->holds()->place($sys, ['scope_type' => 'document', 'document' => $b, 'reference' => 'CASE-1', 'reason' => 'dispute']);
        try {
            $this->t->app->disposition()->request($sys, [$a, $b], 'yearly clean-up');
            self::fail('held document requested for disposal');
        } catch (BusinessException $e) {
            self::assertSame('records.error.on_hold', $e->messageKey);
        }
        $batch = $this->t->app->disposition()->request($sys, [$a], 'yearly clean-up');
        $dinst = (string) $db->scalar('SELECT i.public_id FROM workflow_instances i WHERE i.subject_type = ? AND i.subject_id = ?', ['disposition', $batch]);
        // a hold placed after the request still wins at execution time
        $this->t->app->holds()->place($sys, ['scope_type' => 'record_class', 'record_class' => 'general_document', 'reference' => 'AUDIT-2027', 'reason' => 'audit']);
        $wf->decide($dinst, (int) $this->t->user('sysadmin2.demo')['id'], 'approve');
        self::assertSame('skipped_hold', $db->scalar('SELECT result FROM disposition_items'));
        self::assertSame('final', $db->scalar('SELECT lifecycle_status FROM documents WHERE public_id = ?', [$a]));

        // release the class hold (needs the second person), then dispose for real
        $classHold = (string) $db->scalar("SELECT public_id FROM legal_holds WHERE scope_type = 'record_class'");
        $rinst = $this->t->app->holds()->requestRelease($sys, $classHold, 'audit closed');
        $wf->decide($rinst, (int) $this->t->user('sysadmin2.demo')['id'], 'approve');
        self::assertSame('released', $db->scalar('SELECT status FROM legal_holds WHERE public_id = ?', [$classHold]));
        self::assertSame('active', $db->scalar('SELECT status FROM legal_holds WHERE public_id = ?', [$hold]), 'the document hold is untouched');
        $batch2 = $this->t->app->disposition()->request($sys, [$a], 'second try');
        $dinst2 = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE subject_type = ? AND subject_id = ?', ['disposition', $batch2]);
        $wf->decide($dinst2, (int) $this->t->user('sysadmin2.demo')['id'], 'approve');
        $doc = $db->one('SELECT * FROM documents WHERE public_id = ?', [$a]) ?? [];
        self::assertSame('disposed', $doc['lifecycle_status']);
        self::assertSame('—', $doc['title'], 'no personal data kept in the stub');
        $key = (string) $db->scalar('SELECT storage_key FROM document_versions WHERE document_id = ?', [$doc['id']]);
        self::assertSame('', $key);
        self::assertFileDoesNotExist($this->t->tmp . '/documents/' . substr($aKey, 0, 2) . '/' . $aKey, 'the file itself is deleted');
        $cert = json_decode((string) $db->scalar('SELECT certificate_json FROM disposition_batches WHERE public_id = ?', [$batch2]), true);
        self::assertSame(1, $cert['disposed']);
        self::assertSame(['general_document' => 1], $cert['record_classes']);
        self::assertStringNotContainsString('A', implode('', array_keys($cert['record_classes'])));
        try {
            $docs->download($sys, $a);
            self::fail('disposed document served');
        } catch (BusinessException $e) {
            self::assertSame('document.error.disposed', $e->messageKey);
        }
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM documents WHERE lifecycle_status <> 'disposed' AND public_id = ?", [$b]), 'held document kept');
    }

    public function testRequesterCannotApproveTheirOwnDisposal(): void
    {
        $db = $this->t->app->db();
        $sys = $this->az(TestApp::ADMIN);
        $docs = $this->t->app->documents();
        $hr = $this->az('hr.demo1');
        $a = $docs->upload($hr, 'core.general', UploadedFile::fromBytes('a.pdf', DocumentsTest::PDF, $this->t->tmp), ['title' => 'A', 'legal_entity_id' => $this->le('DEMO1')])['document'];
        $docs->finalize($hr, $a);
        $db->run("INSERT INTO retention_rules (public_id, created_at, updated_at, record_class, version_no, trigger_event, duration_months, disposition_action, status, effective_from, proposed_by, decided_by)
                  VALUES ('01JTESTRULE000000000000000', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), 'general_document', 9, 'finalized', 1, 'destroy', 'approved', '2026-01-01', 1, 2)");
        $docs->recomputeSchedules();
        $this->t->clock->advance('+60 days');
        $batch = $this->t->app->disposition()->request($sys, [$a], 'x');
        $inst = (string) $db->scalar('SELECT public_id FROM workflow_instances WHERE subject_id = ?', [$batch]);
        $tasks = $db->all('SELECT assignee_id FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id WHERE i.public_id = ?', [$inst]);
        self::assertNotContains((int) $this->t->user(TestApp::ADMIN)['id'], array_map('intval', array_column($tasks, 'assignee_id')));
        self::assertSame('requested', $db->scalar('SELECT disposition_status FROM documents WHERE public_id = ?', [$a]));
    }
}
