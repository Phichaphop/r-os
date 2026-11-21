<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Files\ZipStream;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * Phase 3U-B through the HTTP kernel: M26 calculator suite (rate tables from the Config Registry, approved by a second
 * person) and M30 company fonts. Rate values below are MADE-UP TEST VALUES, not legal rates.
 */
final class UtilitiesBWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(calc|fonts|file|common|form)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';

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

    private function font(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/fonts/' . $name);
    }

    public function testPagesRenderTranslatedAndFollowRights(): void
    {
        $pages = [
            'employee.demo1' => ['/calc', '/calc?tab=age', '/calc?tab=service', '/calc?tab=unit', '/calc?tab=bmi', '/calc?tab=sso', '/calc?tab=ewf', '/calc?tab=severance', '/calc?tab=retirement', '/calc?tab=pit', '/calc?tab=prize', '/fonts'],
            TestApp::ADMIN => ['/calc/rates', '/fonts/manage', '/calc?tab=pit'],
        ];
        foreach ($pages as $user => $paths) {
            $b = $this->browser($user);
            foreach ($paths as $p) {
                [$path, $qs] = array_pad(explode('?', $p, 2), 2, '');
                parse_str($qs, $q);
                $res = $b->get($path, $q);
                self::assertSame(200, $res->status, "{$user} {$p}: " . mb_substr(strip_tags($res->body), 0, 300));
                $text = strip_tags((string) preg_replace('/<(script|style|svg)\b.*?<\/\1>/s', '', $res->body));
                self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, $text, "untranslated key on {$p}");
            }
        }
        $emp = $this->browser('employee.demo1');
        $home = $emp->get('/')->body;
        self::assertStringContainsString('href="/calc"', $home);
        self::assertStringContainsString('href="/fonts"', $home);
        self::assertSame(403, $emp->get('/calc/rates')->status);
        self::assertSame(403, $emp->get('/fonts/manage')->status);
        $bmi = $emp->get('/calc', ['tab' => 'bmi'])->body;
        self::assertStringNotContainsString('<form', (string) strstr((string) strstr($bmi, 'data-calc="bmi"'), '</section>', true), 'BMI is never submitted');
        self::assertSame(404, $emp->post('/calc/bmi', ['weight' => '70', 'height' => '175'])->status, 'no server route for BMI');
        self::assertStringContainsString('data-calc="bmi"', $emp->get('/calc', ['tab' => 'bmi'])->body);
    }

    public function testLegalCalculatorUsesOnlyAnApprovedRateTableAndSharesExpire(): void
    {
        $emp = $this->browser('employee.demo1');
        $emp->get('/calc', ['tab' => 'sso']);
        $none = $emp->post('/calc/sso', ['wage' => '20000', 'at' => '2026-11-09']);
        self::assertSame(200, $none->status);
        self::assertStringNotContainsString('class="calc-outputs"', $none->body, 'no table → no result');
        self::assertSame(0, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id WHERE c.config_key LIKE 'calc.%'"), 'nothing shipped');
        self::assertSame(403, $emp->post('/calc/rates/sso', ['employee_rate' => '3'])->status);

        $admin = $this->browser(TestApp::ADMIN);
        $admin->get('/calc/rates');
        $table = ['effective_from' => '2026-01-01', 'legal_entity_id' => '', 'employee_rate' => '3', 'employer_rate' => '3', 'wage_floor' => '1000', 'wage_ceiling' => '10000',
            'rounding' => 'none', 'reason' => 'ทดสอบ'];
        $bad = $admin->post('/calc/rates/sso', $table + ['source_reference' => '']);
        self::assertSame(422, $bad->status, 'source reference required');
        $bad2 = $admin->post('/calc/rates/sso', ['wage_ceiling' => '10'] + $table + ['source_reference' => 'TEST ONLY — made-up table']);
        self::assertSame(422, $bad2->status);
        $ok = $admin->post('/calc/rates/sso', $table + ['source_reference' => 'TEST ONLY — made-up table']);
        self::assertSame(303, $ok->status, mb_substr(strip_tags($ok->body), 0, 300));
        self::assertSame('in_review', $this->t->app->db()->scalar("SELECT v.status FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id WHERE c.config_key = 'calc.sso.rule'"));
        self::assertStringNotContainsString('class="calc-outputs"', $emp->post('/calc/sso', ['wage' => '20000'])->body, 'pending table is not used');

        $inst = (string) $this->t->app->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'config_version' ORDER BY id DESC LIMIT 1");
        self::assertStringNotContainsString('name="decision"', $admin->get('/approvals/' . $inst)->body, 'the proposer cannot approve');
        $checker = $this->browser('sysadmin2.demo');
        $checker->get('/approvals/' . $inst);
        $checker->post('/approvals/' . $inst . '/decide', ['decision' => 'approve', '_back' => '/approvals/' . $inst]);
        $checker->get('/reauth');
        $checker->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/approvals/' . $inst]);
        $checker->get('/approvals/' . $inst);
        $checker->post('/approvals/' . $inst . '/decide', ['decision' => 'approve', '_back' => '/approvals/' . $inst]);
        self::assertSame('approved', $this->t->app->db()->scalar("SELECT v.status FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id WHERE c.config_key = 'calc.sso.rule'"));

        $emp->get('/calc', ['tab' => 'sso']);
        $res = $emp->post('/calc/sso', ['wage' => '20000', 'at' => '2026-11-09']);
        self::assertStringContainsString('class="calc-outputs"', $res->body);
        self::assertStringContainsString('300.00', $res->body);
        self::assertStringContainsString('TEST ONLY', $res->body, 'the source of the table is shown');
        self::assertStringNotContainsString('class="calc-outputs"', $emp->post('/calc/sso', ['wage' => '20000', 'at' => '2025-12-31'])->body, 'before the effective date');
        self::assertSame(422, $emp->post('/calc/sso', ['wage' => 'abc'])->status);
        $sevNone = $emp->post('/calc/severance', ['start' => '2020-01-01', 'end' => '2021-01-01', 'wage' => '1']);
        self::assertSame(200, $sevNone->status);
        self::assertStringNotContainsString('class="calc-outputs"', $sevNone->body, 'severance has no approved table here');

        $share = $emp->post('/calc/sso/share', ['wage' => '20000', 'at' => '2026-11-09']);
        self::assertSame(303, $share->status);
        $loc = (string) $share->headers['Location'];
        self::assertMatchesRegularExpression('#/calc/s/[0-9A-Za-z]{12}$#', $loc);
        $token = substr($loc, -12);
        $row = $this->t->app->db()->one('SELECT * FROM calc_shares WHERE token = ?', [$token]);
        self::assertStringNotContainsString('employee', strtolower((string) $row['inputs_json']), 'numbers only — no names in the shared inputs');
        $other = $this->browser('hr.demo1');
        $seen = $other->get('/calc/s/' . $token);
        self::assertSame(200, $seen->status);
        self::assertStringContainsString('300.00', $seen->body);
        self::assertSame(302, $this->t->browser()->get('/calc/s/' . $token)->status, 'needs sign-in');
        $this->t->clock->advance('+8 days');
        $other2 = $this->browser('hr.demo1');
        self::assertSame(410, $other2->get('/calc/s/' . $token)->status, 'expired after the default 7 days');
        self::assertSame(0, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM calc_shares WHERE token = ?', [$token]), 'expired share removed');

        $emp2 = $this->browser('employee.demo1');
        $emp2->get('/calc', ['tab' => 'sso']);
        $tok2 = substr((string) $emp2->post('/calc/sso/share', ['wage' => '5000'])->headers['Location'], -12);
        $hr = $this->browser('hr.demo1');
        $hr->get('/calc');
        $hr->post('/calc/s/' . $tok2 . '/delete');
        self::assertSame(1, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM calc_shares WHERE token = ?', [$tok2]), 'only the owner can delete');
        $emp2->get('/calc/s/' . $tok2);
        $emp2->post('/calc/s/' . $tok2 . '/delete');
        self::assertSame(0, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM calc_shares WHERE token = ?', [$tok2]));
    }

    public function testSeveranceAndIncomeTaxTablesCanBeProposedFromTheForm(): void
    {
        $admin = $this->browser(TestApp::ADMIN);
        $admin->get('/calc/rates');
        $sev = $admin->post('/calc/rates/severance', ['effective_from' => '2026-01-01', 'source_reference' => 'TEST ONLY — made-up tiers', 'reason' => 'ทดสอบ', 'monthly_divisor' => '30',
            'rounding' => 'none', 'tiers' => ['min_days' => ['100', '300', ''], 'days_pay' => ['20', '60', '']]]);
        self::assertSame(303, $sev->status, mb_substr(strip_tags($sev->body), 0, 300));
        $pit = $admin->post('/calc/rates/pit', ['effective_from' => '2026-01-01', 'source_reference' => 'TEST ONLY — made-up brackets', 'reason' => 'ทดสอบ', 'expense_rate' => '40',
            'expense_cap' => '50000', 'personal_allowance' => '30000', 'brackets' => ['up_to' => ['100000', ''], 'rate' => ['0', '10']], 'allowances' => ['label' => ['ทดสอบ'], 'cap' => ['9000']]]);
        self::assertSame(303, $pit->status, mb_substr(strip_tags($pit->body), 0, 300));
        $json = (string) $this->t->app->db()->scalar("SELECT v.value_json FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id WHERE c.config_key = 'calc.pit.rule'");
        self::assertSame(['up_to' => null, 'rate' => '10.0000'], json_decode($json, true)['brackets'][1]);
        $badPit = $admin->post('/calc/rates/pit', ['effective_from' => '2026-01-01', 'source_reference' => 'TEST ONLY', 'reason' => 'x1', 'expense_rate' => '40', 'expense_cap' => '1',
            'personal_allowance' => '1', 'brackets' => ['up_to' => ['100000', '50000'], 'rate' => ['0', '10']]]);
        self::assertSame(422, $badPit->status);
        self::assertStringContainsString('field__error', $badPit->body);
    }

    public function testPrizeTaxCalculatorNeedsAnApprovedRateAndKeepsNoNames(): void
    {
        $emp = $this->browser('employee.demo1');
        $page = $emp->get('/calc', ['tab' => 'prize']);
        self::assertStringContainsString('name="mode"', $page->body);
        self::assertStringNotContainsString('class="calc-outputs"', $emp->post('/calc/prize', ['value' => '10000', 'at' => '2026-11-09'])->body, 'no rate shipped → no result');

        $admin = $this->browser(TestApp::ADMIN);
        self::assertStringContainsString('id="t-prize"', $admin->get('/calc/rates')->body);
        $base = ['effective_from' => '2026-01-01', 'legal_entity_id' => '', 'source_reference' => 'TEST ONLY — made-up prize rate', 'reason' => 'ทดสอบ', 'rounding' => 'none'];
        self::assertSame(422, $admin->post('/calc/rates/prize', $base + ['rate' => ''])->status, 'the rate has no default');
        self::assertSame(422, $admin->post('/calc/rates/prize', $base + ['rate' => '100'])->status);
        self::assertSame(303, $admin->post('/calc/rates/prize', $base + ['rate' => '5', 'threshold' => '1,000'])->status);
        $json = json_decode((string) $this->t->app->db()->scalar("SELECT v.value_json FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id WHERE c.config_key = 'calc.prize.rule'"), true);
        self::assertSame(['rate' => '5.0000', 'threshold' => '1000.00', 'rounding' => 'none'], $json);

        $inst = (string) $this->t->app->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'config_version' ORDER BY id DESC LIMIT 1");
        $checker = $this->browser('sysadmin2.demo');
        $checker->get('/reauth');
        $checker->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/approvals/' . $inst]);
        $checker->get('/approvals/' . $inst);
        $checker->post('/approvals/' . $inst . '/decide', ['decision' => 'approve', '_back' => '/approvals/' . $inst]);
        self::assertSame('approved', $this->t->app->db()->scalar("SELECT v.status FROM config_versions v JOIN config_definitions c ON c.id = v.definition_id WHERE c.config_key = 'calc.prize.rule'"));

        $emp->get('/calc', ['tab' => 'prize']);
        $w = $emp->post('/calc/prize', ['value' => '10000', 'mode' => 'withhold', 'at' => '2026-11-09']);
        self::assertStringContainsString('class="calc-outputs"', $w->body);
        self::assertStringContainsString('9,500.00', $w->body);
        self::assertStringContainsString('TEST ONLY — made-up prize rate', $w->body);
        $g = $emp->post('/calc/prize', ['value' => '9500', 'mode' => 'grossup', 'at' => '2026-11-09']);
        self::assertStringContainsString('10,000.00', $g->body);
        self::assertStringContainsString('selected', $g->body);
        $below = $emp->post('/calc/prize', ['value' => '500', 'at' => '2026-11-09']);
        self::assertStringContainsString('1,000.00', $below->body, 'the minimum value is explained in the steps');
        self::assertSame(422, $emp->post('/calc/prize', ['value' => 'abc'])->status);
        $share = $emp->post('/calc/prize/share', ['value' => '10000', 'mode' => 'withhold', 'at' => '2026-11-09']);
        self::assertSame(303, $share->status);
        $row = $this->t->app->db()->one('SELECT inputs_json FROM calc_shares WHERE token = ?', [substr((string) $share->headers['Location'], -12)]);
        self::assertSame(['at', 'value', 'mode'], array_keys(array_filter((array) json_decode((string) $row['inputs_json'], true), static fn ($v) => $v !== '' && $v !== [])), 'numbers only');
    }

    public function testFontLibraryLifecycleLicenceMakerCheckerAndDownloads(): void
    {
        $db = $this->t->app->db();
        $admin = $this->browser(TestApp::ADMIN);
        $admin->get('/fonts/manage');
        self::assertSame(422, $admin->post('/fonts/manage', ['name' => 'x', 'category' => 'mono'])->status);
        $created = $admin->post('/fonts/manage', ['name' => 'IBM Plex Mono', 'category' => 'mono', 'description' => 'ทดสอบ', 'dos' => 'ตัวเลขในตาราง']);
        self::assertSame(303, $created->status);
        $pid = (string) $db->scalar("SELECT public_id FROM font_families WHERE name = 'IBM Plex Mono'");
        $admin->get('/fonts/manage/' . $pid);
        self::assertSame(303, $admin->post('/fonts/manage/' . $pid . '/files', [], ['file' => ['IBMPlexMono-Regular.ttf', $this->font('IBMPlexMono-Regular.ttf')]])->status);
        self::assertSame(422, $admin->post('/fonts/manage/' . $pid . '/files', [], ['file' => ['copy.ttf', $this->font('IBMPlexMono-Regular.ttf')]])->status, 'duplicate by hash');
        self::assertSame(422, $admin->post('/fonts/manage/' . $pid . '/files', [], ['file' => ['fake.ttf', 'just text pretending to be a font']])->status, 'fake font');
        self::assertSame(422, $admin->post('/fonts/manage/' . $pid . '/files', [], ['file' => ['cut.ttf', substr($this->font('IBMPlexMono-Bold.ttf'), 0, 60)]])->status, 'damaged font');
        $admin->post('/fonts/manage/' . $pid . '/files', [], ['file' => ['IBMPlexMono-Bold.ttf', $this->font('IBMPlexMono-Bold.ttf')]]);
        self::assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM font_files'));
        self::assertSame(700, (int) $db->scalar("SELECT weight FROM font_files WHERE original_name = 'IBMPlexMono-Bold.ttf'"));

        $emp = $this->browser('employee.demo1');
        $file = (string) $db->scalar("SELECT public_id FROM font_files WHERE original_name = 'IBMPlexMono-Regular.ttf'");
        self::assertSame(404, $emp->get('/fonts/' . $pid)->status, 'drafts are invisible');
        self::assertSame(404, $emp->get('/fonts/file/' . $file . '/preview')->status);
        self::assertStringNotContainsString('IBM Plex Mono', $emp->get('/fonts')->body);

        $admin->get('/fonts/manage/' . $pid);
        $admin->post('/fonts/manage/' . $pid . '/publish');
        self::assertSame('draft', $db->scalar('SELECT status FROM font_families WHERE public_id = ?', [$pid]), 'no licence → not published');
        $checker = $this->browser('sysadmin2.demo');
        $checker->get('/fonts/manage/' . $pid);
        $checker->post('/fonts/manage/' . $pid, ['name' => 'IBM Plex Mono', 'category' => 'mono', 'license_name' => 'SIL Open Font License 1.1', 'license_source' => 'https://github.com/IBM/plex',
            'license_terms' => 'ตาม OFL']);
        // 0.37.0 (DEC-102): the uploader may publish their own family; the audit says it was self-published
        $admin->get('/fonts/manage/' . $pid);
        $admin->post('/fonts/manage/' . $pid . '/publish');
        self::assertSame('published', $db->scalar('SELECT status FROM font_families WHERE public_id = ?', [$pid]), 'the maker may publish');
        $aud = (string) $db->scalar("SELECT diff_json FROM audit_events WHERE action = 'fonts.published' AND target_id = ? ORDER BY id DESC LIMIT 1", [$pid]);
        self::assertStringContainsString('"self_published":true', $aud);
        $proof = $admin->post('/fonts/manage/' . $pid . '/license', [], ['license_file' => ['proof.pdf', DocumentsTest::PDF]]);
        self::assertSame(303, $proof->status);
        self::assertSame(403, $emp->get('/fonts/manage/' . $pid . '/license')->status, 'proof of licence is for administrators');

        $prev = $emp->get('/fonts/file/' . $file . '/preview');
        self::assertSame(200, $prev->status);
        self::assertSame('font/ttf', $prev->headers['Content-Type']);
        self::assertSame($this->font('IBMPlexMono-Regular.ttf'), $prev->body);
        self::assertSame(0, (int) $db->scalar('SELECT download_count FROM font_files WHERE public_id = ?', [$file]), 'preview is not a download');
        $dl = $emp->get('/fonts/file/' . $file);
        self::assertStringContainsString('attachment', $dl->headers['Content-Disposition']);
        self::assertSame(1, (int) $db->scalar('SELECT download_count FROM font_files WHERE public_id = ?', [$file]));
        $zip = $emp->get('/fonts/' . $pid . '/zip');
        self::assertSame('application/zip', $zip->headers['Content-Type']);
        $tmp = $this->t->tmp . '/fonts.zip';
        file_put_contents($tmp, $zip->body);
        $z = ZipStream::open($tmp);
        self::assertEqualsCanonicalizing(['IBMPlexMono-Regular.ttf', 'IBMPlexMono-Bold.ttf', 'LICENSE.txt'], $z->names());
        self::assertStringContainsString('SIL Open Font License 1.1', $z->read('LICENSE.txt'));
        self::assertSame(2, (int) $db->scalar('SELECT download_count FROM font_families WHERE public_id = ?', [$pid]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'fonts.bundle_downloaded'"));

        // module documents stay out of the central document list and search
        $emp->get('/documents');
        self::assertStringNotContainsString('IBM Plex Mono', $emp->get('/documents')->body);
        $doc = (string) $db->scalar('SELECT document_pid FROM font_files WHERE public_id = ?', [$file]);
        self::assertSame(404, $emp->get('/documents/' . $doc)->status);

        // new version keeps the old file; people only get the current one
        $admin->get('/fonts/manage/' . $pid);
        $admin->post('/fonts/manage/' . $pid . '/files', ['replaces' => $file], ['file' => ['IBMPlexMono-Regular-v2.ttf', $this->font('IBMPlexMono-Regular.ttf') . "\0\0\0\0"]]);
        self::assertSame('replaced', $db->scalar('SELECT status FROM font_files WHERE public_id = ?', [$file]));
        self::assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM font_files'), 'nothing deleted');
        self::assertSame(404, $emp->get('/fonts/file/' . $file)->status);
        self::assertSame(200, $admin->get('/fonts/file/' . $file)->status, 'administrators keep access to old versions');

        // restricted family: only people with fonts.restricted see it
        $admin->get('/fonts/manage');
        $admin->post('/fonts/manage', ['name' => 'Licensed Display', 'category' => 'display', 'license_name' => 'Vendor EULA', 'license_source' => 'Vendor order #TEST', 'restricted' => '1']);
        $rid = (string) $db->scalar("SELECT public_id FROM font_families WHERE name = 'Licensed Display'");
        $admin->get('/fonts/manage/' . $rid);
        $checker->get('/fonts/manage/' . $rid);
        $checker->post('/fonts/manage/' . $rid . '/files', [], ['file' => ['bold.ttf', $this->font('IBMPlexMono-Bold.ttf') . "\0\0\0\0"]]);
        // 0.37.0: a ZIP of the family — fonts from any folder are imported one by one; duplicates are skipped with the
        // reason; other files and macOS resource forks are ignored; the ZIP is not kept
        $zip = \Blue\Core\Files\Zip::build([
            'Licensed/LicensedDisplay-Regular.woff' => $this->font('IBMPlexMono-Regular.woff'),
            'Licensed/web/LicensedDisplay-Regular.woff2' => $this->font('IBMPlexMono-Regular.woff2'),
            'Licensed/LicensedDisplay-Bold.ttf' => $this->font('IBMPlexMono-Bold.ttf') . "\0\0\0\0",
            'Licensed/OFL.txt' => $this->font('IBMPlexMono-OFL.txt'),
            '__MACOSX/Licensed/._LicensedDisplay-Regular.woff' => 'resource fork',
        ]);
        $admin->get('/fonts/manage/' . $rid);
        $docs = (int) $db->scalar('SELECT COUNT(*) FROM documents');
        $res = $admin->post('/fonts/manage/' . $rid . '/files', [], ['file' => ['licensed-display.zip', $zip]]);
        self::assertSame(303, $res->status);
        self::assertSame(3, (int) $db->scalar("SELECT COUNT(*) FROM font_files WHERE family_id = (SELECT id FROM font_families WHERE public_id = ?) AND status = 'active'", [$rid]), '2 new + the earlier bold');
        self::assertSame($docs + 2, (int) $db->scalar('SELECT COUNT(*) FROM documents'), 'only the two new fonts are stored — not the ZIP, not the duplicate');
        $flash = $admin->get('/fonts/manage/' . $rid)->body;
        self::assertStringContainsString('LicensedDisplay-Bold.ttf', $flash, 'the skipped duplicate is named');
        self::assertSame(422, $admin->post('/fonts/manage/' . $rid . '/files', [], ['file' => ['empty.zip', \Blue\Core\Files\Zip::build(['readme.txt' => 'x'])]])->status, 'no fonts inside');
        self::assertSame(422, $admin->post('/fonts/manage/' . $rid . '/files', [], ['file' => ['fake.zip', 'not a zip at all']])->status);
        $admin->get('/fonts/manage/' . $rid);
        $admin->post('/fonts/manage/' . $rid . '/publish');
        self::assertSame('published', $db->scalar('SELECT status FROM font_families WHERE public_id = ?', [$rid]), 'the creator may publish (DEC-102)');
        self::assertStringNotContainsString('Licensed Display', $emp->get('/fonts')->body);

        // deprecate → still visible with a notice; archive → hidden
        $admin->get('/fonts/manage/' . $pid);
        $admin->post('/fonts/manage/' . $pid . '/status', ['to' => 'deprecated', 'reason' => 'ทดสอบเลิกใช้']);
        self::assertSame('deprecated', $db->scalar('SELECT status FROM font_families WHERE public_id = ?', [$pid]));
        self::assertSame(200, $emp->get('/fonts/' . $pid)->status);
        $admin->get('/fonts/manage/' . $pid);
        $admin->post('/fonts/manage/' . $pid . '/status', ['to' => 'archived', 'reason' => 'ทดสอบเก็บ']);
        self::assertSame(404, $emp->get('/fonts/' . $pid)->status);
        self::assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM font_files WHERE family_id = (SELECT id FROM font_families WHERE public_id = ?)', [$pid]), 'archive keeps files');
        self::assertGreaterThanOrEqual(8, (int) $db->scalar('SELECT COUNT(*) FROM font_events WHERE family_id = (SELECT id FROM font_families WHERE public_id = ?)', [$pid]));
    }
}
