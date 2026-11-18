<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Modules\Talent\TalentService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 5E (DEC-76): M49 talent pool — consent, repeats, contacts, putting people forward, matches, erase, retention, resume builder. */
final class TalentWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(recruit|talent|employees|common|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

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

    /** A published posting of DEMO1 (requisition approved). */
    private function posting(string $title): string
    {
        $db = $this->t->app->db();
        $now = $this->t->clock->now()->format('Y-m-d H:i:s.u');
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $n = (int) $db->scalar('SELECT COUNT(*) FROM rec_requisitions') + 1;
        $req = sprintf('01J00000000000000000REQ%03d', $n);
        $pid = sprintf('01J00000000000000000POS%03d', $n);
        $db->run("INSERT INTO rec_requisitions (public_id, created_at, updated_at, legal_entity_id, position_title, headcount, reason, employment_type, justification, requester_user_id, status)
                  VALUES (?, ?, ?, ?, ?, 1, 'new', 'permanent', 'xxxxxxxxxxxx', ?, 'approved')", [$req, $now, $now, $le, $title, (int) $this->t->user('manager.demo1')['id']]);
        $db->run(
            "INSERT INTO rec_postings (public_id, created_at, updated_at, requisition_id, legal_entity_id, title, description, status) VALUES (?, ?, ?, LAST_INSERT_ID(), ?, ?, 'xxxxxxxxxxxxxxxxxxxxxx', 'published')",
            [$pid, $now, $now, $le, $title]
        );
        return $pid;
    }

    /** @return array<string, string> */
    private function person(string $name, string $phone): array
    {
        return ['source' => 'walk_in', 'name' => $name, 'phone' => $phone, 'email' => '', 'positions' => 'บัญชี, การเงิน', 'skills' => 'Excel, SAP', 'experience_years' => '4',
            'education_level' => 'bachelor', 'consent_at' => '2026-11-05', 'consent_basis' => 'เซ็นแบบฟอร์มยินยอม walk-in', 'notes' => ''];
    }

    public function testPoolFlow(): void
    {
        $db = $this->t->app->db();
        $post = $this->posting('เจ้าหน้าที่บัญชี');
        $hr = $this->browser('hr.demo1');
        $emp = $this->browser('employee.demo1');
        self::assertSame(403, $emp->get('/talent')->status, 'HR only');

        $idx = $hr->get('/talent');
        self::assertSame(200, $idx->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($idx->body), 'no raw keys');
        self::assertStringContainsString('applicant_unsuccessful', $idx->body, 'says there is no approved retention rule');
        $form = $hr->get('/talent/new');
        self::assertSame(200, $form->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($form->body), 'no raw keys');

        // consent basis is required; with a CV it is stored encrypted
        self::assertSame(422, $hr->post('/talent/new', ['consent_basis' => ''] + $this->person('วิไล ใจดี', '0811111111'))->status);
        $hr->get('/talent/new');
        $pdf = (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/a.pdf');
        $res = $hr->post('/talent/new', $this->person('วิไล ใจดี', '081-111-1111'), ['cv' => ['cv-wilai.pdf', $pdf]]);
        self::assertSame(303, $res->status, $this->errorsLogged());
        $p = $db->one('SELECT * FROM tal_profiles ORDER BY id DESC LIMIT 1');
        self::assertSame(['วิไล ใจดี', '0811111111', 'walk_in', null], [$p['name'], $p['phone'], $p['source'], $p['retention_until']]);
        self::assertNotNull($p['cv_document_pid']);
        self::assertSame(32, strlen((string) $p['dup_phone']));

        // the same phone again = the same person (CV added, no second profile)
        $hr->get('/talent/new');
        self::assertSame(303, $hr->post('/talent/new', ['source' => 'upload', 'name' => 'วิไล  ใจดี'] + $this->person('x', '0811111111'))->status);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM tal_profiles'));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM tal_contacts WHERE profile_id = ? AND kind = 'merged'", [$p['id']]));

        // search, profile, CV download
        self::assertStringContainsString('วิไล ใจดี', $hr->get('/talent', ['q' => 'SAP'])->body);
        self::assertStringNotContainsString('วิไล ใจดี', $hr->get('/talent', ['q' => 'ช่างไฟ'])->body);
        self::assertStringNotContainsString('วิไล ใจดี', $hr->get('/talent', ['years' => '10'])->body);
        $view = $hr->get('/talent/p/' . $p['public_id']);
        self::assertSame(200, $view->status, $this->errorsLogged());
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($view->body), 'no raw keys');
        self::assertStringContainsString('เจ้าหน้าที่บัญชี', $view->body, 'matching posting shown');
        self::assertSame(200, $hr->get('/talent/p/' . $p['public_id'] . '/cv')->status);
        self::assertSame(404, $emp->get('/talent/p/' . $p['public_id'])->status);

        // contact log moves the status; star
        $hr->get('/talent/p/' . $p['public_id']);
        self::assertSame(422, $hr->post('/talent/p/' . $p['public_id'] . '/contact', ['channel' => 'phone', 'note' => ''])->status);
        $hr->get('/talent/p/' . $p['public_id']);
        $hr->post('/talent/p/' . $p['public_id'] . '/contact', ['channel' => 'phone', 'note' => 'สนใจตำแหน่งบัญชี พร้อมเริ่มเดือนหน้า']);
        $hr->get('/talent/p/' . $p['public_id']);
        $hr->post('/talent/p/' . $p['public_id'] . '/star', ['on' => '1']);
        $row = $db->one('SELECT status, starred FROM tal_profiles WHERE id = ?', [$p['id']]);
        self::assertSame(['contacted', 1], [$row['status'], (int) $row['starred']]);

        // match notifications, once per posting and recruiter
        $svc = TalentService::of($this->t->app);
        self::assertSame(1, $svc->matchJob());
        $svc->matchJob();
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'talent.match' AND user_id = ?", [(int) $this->t->user('hr.demo1')['id']]));

        // put forward → an application in M08 at screening; never twice
        $hr->get('/talent/p/' . $p['public_id']);
        $go = $hr->post('/talent/p/' . $p['public_id'] . '/link', ['posting' => $post]);
        self::assertSame(303, $go->status, $this->errorsLogged());
        $cand = $db->one('SELECT * FROM rec_candidates ORDER BY id DESC LIMIT 1');
        self::assertSame(['talent_pool', 'screening', (int) $p['id']], [$cand['source'], $cand['stage'], (int) $cand['talent_id']]);
        self::assertStringContainsString('/recruit/c/' . $cand['public_id'], (string) $go->headers['Location']);
        self::assertSame('linked', $db->scalar('SELECT status FROM tal_profiles WHERE id = ?', [$p['id']]));
        $hr->get('/talent/p/' . $p['public_id']);
        $hr->post('/talent/p/' . $p['public_id'] . '/link', ['posting' => $post]);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM rec_candidates WHERE talent_id = ?', [$p['id']]), 'never twice');
        self::assertStringContainsString('เจ้าหน้าที่บัญชี', $hr->get('/talent/p/' . $p['public_id'])->body, 'application listed');

        // from an application: only with the applicant's talent-pool consent
        $le = (int) $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $now = $this->t->clock->now()->format('Y-m-d H:i:s.u');
        $postId = (int) $db->scalar('SELECT id FROM rec_postings WHERE public_id = ?', [$post]);
        $enc = base64_encode($this->t->app->crypto()->encrypt((string) json_encode(['skills' => 'งานเอกสาร', 'education' => [['level' => 'vocational'], ['level' => 'bachelor']],
            'work' => [['from' => '2022-01', 'to' => '2023-12']]], JSON_UNESCAPED_UNICODE)));
        foreach ([['01J0000000000000000000APP1', 1, '0822222222'], ['01J0000000000000000000APP2', 0, '0833333333']] as [$cp, $consent, $phone]) {
            $db->run("INSERT INTO rec_candidates (public_id, created_at, updated_at, posting_id, legal_entity_id, app_code, name, phone, stage, stage_at, profile_enc, consent_at, notice_version, talent_consent)
                      VALUES (?, ?, ?, ?, ?, ?, 'มานี รักงาน', ?, 'rejected', ?, ?, ?, 1, ?)", [$cp, $now, $now, $postId, $le, 'CODE' . substr($cp, -4) . '00', $phone, $now, $enc, $now, $consent]);
        }
        $hr->get('/recruit/c/01J0000000000000000000APP2');
        $hr->post('/talent/from/01J0000000000000000000APP2', []);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM tal_profiles WHERE phone = '0833333333'"), 'no consent, not kept');
        $hr->get('/recruit/c/01J0000000000000000000APP1');
        self::assertSame(303, $hr->post('/talent/from/01J0000000000000000000APP1', [])->status, $this->errorsLogged());
        $fromApp = $db->one("SELECT * FROM tal_profiles WHERE phone = '0822222222'");
        self::assertNotNull($fromApp);
        self::assertSame(['applicant', 'bachelor', '2.0'], [$fromApp['source'], $fromApp['education_level'], (string) $fromApp['experience_years']]);

        // erase on request
        $hr->get('/talent/p/' . $fromApp['public_id']);
        self::assertSame(422, $hr->post('/talent/p/' . $fromApp['public_id'] . '/erase', ['reason' => ''])->status);
        $hr->get('/talent/p/' . $fromApp['public_id']);
        $hr->post('/talent/p/' . $fromApp['public_id'] . '/erase', ['reason' => 'เจ้าตัวขอถอนความยินยอมทางอีเมล']);
        $gone = $db->one('SELECT * FROM tal_profiles WHERE id = ?', [$fromApp['id']]);
        self::assertSame(['—', null, null, null], [$gone['name'], $gone['phone'], $gone['dup_phone'], $gone['cv_document_pid']]);
        self::assertNotNull($gone['anonymized_at']);
        self::assertSame(200, $hr->get('/talent/p/' . $fromApp['public_id'])->status, 'shows the anonymised record');
    }

    public function testRetentionAndBuilder(): void
    {
        $db = $this->t->app->db();
        $now = $this->t->clock->now()->format('Y-m-d H:i:s.u');
        $svc = TalentService::of($this->t->app);
        self::assertNull($svc->retentionUntil('2026-01-01'), 'no approved rule');
        $db->run("INSERT INTO retention_rules (public_id, created_at, updated_at, record_class, version_no, trigger_event, duration_months, disposition_action, status, effective_from)
                  VALUES ('01J0000000000000000000RET1', ?, ?, 'applicant_unsuccessful', 99, 'related_closed', 6, 'anonymize', 'approved', '2026-01-01')", [$now, $now]);
        self::assertSame('2026-07-01', $svc->retentionUntil('2026-01-01'));
        $hr = $this->browser('hr.demo1');
        foreach ([['เก่า มาก', '0844444444', '2026-02-01'], ['ใหม่ อยู่', '0855555555', '2026-11-01']] as [$name, $phone, $consent]) {
            $hr->get('/talent/new');
            $hr->post('/talent/new', ['consent_at' => $consent] + $this->person($name, $phone));
        }
        self::assertSame('2026-08-01', $db->scalar("SELECT retention_until FROM tal_profiles WHERE phone = '0844444444'"));
        self::assertSame(1, $svc->retentionJob());
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM tal_profiles WHERE anonymized_at IS NOT NULL AND phone IS NULL"));
        self::assertSame('ใหม่ อยู่', $db->scalar("SELECT name FROM tal_profiles WHERE retention_until = '2027-05-01'"));

        // resume builder: anyone signed in; nothing stored
        $before = (int) $db->scalar('SELECT COUNT(*) FROM tal_profiles');
        $emp = $this->browser('employee.demo1');
        $page = $emp->get('/talent/builder');
        self::assertSame(200, $page->status);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page->body), 'no raw keys');
        $out = $emp->post('/talent/builder', ['name' => 'ทดสอบ สร้างเรซูเม่', 'headline' => 'ช่างซ่อมบำรุง', 'skills' => "ไฟฟ้า, PLC\nงานเชื่อม", 'work_title_0' => 'ช่างไฟฟ้า', 'work_org_0' => 'บริษัท ข',
            'work_from_0' => '2019', 'edu_degree_0' => 'ปวส. ไฟฟ้า', 'summary' => '<script>alert(1)</script>']);
        self::assertSame(200, $out->status, $this->errorsLogged());
        self::assertStringContainsString('resume-sheet', $out->body);
        self::assertStringContainsString('ทดสอบ สร้างเรซูเม่', $out->body);
        self::assertStringContainsString('<li>งานเชื่อม</li>', $out->body);
        self::assertStringNotContainsString('<script>alert(1)', $out->body, 'escaped');
        self::assertSame($before, (int) $db->scalar('SELECT COUNT(*) FROM tal_profiles'), 'nothing stored');
        self::assertSame(403, $emp->get('/talent')->status);
        // visitors only once the organization opens it
        $g = $this->t->browser();
        self::assertSame(302, $g->get('/talent/builder')->status);
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $sys->get('/admin/settings/guest');
        $sys->post('/admin/settings/guest', ['home' => ['talent'], 'tools' => ['talent']]);
        self::assertSame(200, $g->get('/talent/builder')->status);
        self::assertSame(302, $g->get('/talent')->status, 'the pool needs an account');
    }
}
