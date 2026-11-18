<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Files\Csv;
use Blue\Core\Files\ZipStream;
use Blue\Tests\Support\TestApp;

/** Phase 3U-A through the HTTP kernel: module registry, M31 links, M28 crypto tools, M27 QR codes. */
final class UtilitiesWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(links|qr|crypto|module|url|nav|common|form)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    public function testPagesRenderTranslatedAndMenusFollowRights(): void
    {
        foreach (['employee.demo1' => ['/links', '/qr', '/qr/batch', '/crypto', '/crypto?tool=random', '/crypto?tool=encode'], TestApp::ADMIN => ['/admin/modules', '/admin/links', '/admin/links/new', '/admin/roles']] as $user => $paths) {
            $b = $this->t->browser();
            $b->login($user);
            foreach ($paths as $p) {
                [$path, $qs] = array_pad(explode('?', $p, 2), 2, '');
                parse_str($qs, $q);
                $res = $b->get($path, $q);
                self::assertSame(200, $res->status, "{$user} {$p}: " . mb_substr(strip_tags($res->body), 0, 300));
                $text = strip_tags((string) preg_replace('/<(script|style|svg)\b.*?<\/\1>/s', '', $res->body));
                $text = str_replace(['links.use', 'links.manage', 'qr.use', 'qr.manage', 'crypto.use', 'core.module.manage'], '', $text); // permission codes on the roles page
                self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, $text, "untranslated key on {$p}");
            }
        }
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        $home = $emp->get('/')->body;
        foreach (['href="/qr"', 'href="/crypto"'] as $h) {
            self::assertStringContainsString($h, $home);
        }
        self::assertStringNotContainsString('href="/admin/links"', $home, '0.6.1: links are managed under Administration, shown on Home');
        self::assertStringNotContainsString('href="/admin/modules"', $home);
        self::assertSame(403, $emp->get('/admin/links')->status);
        self::assertSame(403, $emp->get('/admin/modules')->status);
    }

    public function testSwitchingAModuleOffRemovesRoutesAndMenu(): void
    {
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $sys->get('/admin/modules');
        $first = $sys->post('/admin/modules/crypto/toggle', ['enabled' => '0', 'reason' => 'ทดสอบปิด']);
        self::assertStringContainsString('/reauth', (string) ($first->headers['Location'] ?? ''), 'needs the password again');
        $sys->get('/reauth');
        $sys->post('/reauth', ['password' => TestApp::ADMIN_PASSWORD, 'next' => '/admin/modules']);
        $sys->get('/admin/modules');
        $short = $sys->post('/admin/modules/crypto/toggle', ['enabled' => '0', 'reason' => 'x']);
        self::assertSame(303, $short->status);
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT enabled FROM modules WHERE code = 'crypto'"), 'a reason is required');
        $sys->post('/admin/modules/crypto/toggle', ['enabled' => '0', 'reason' => 'ทดสอบปิดโมดูล']);
        self::assertSame(0, (int) $this->t->app->db()->scalar("SELECT enabled FROM modules WHERE code = 'crypto'"));
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'module.disabled' AND target_id = 'crypto'"));
        $this->t->app->modules()->forget();
        $emp = $this->t->browser(); // new kernel → routes registered from the current switches
        $emp->login('employee.demo1');
        self::assertSame(404, $emp->get('/crypto')->status, 'route gone');
        self::assertStringNotContainsString('href="/crypto"', $emp->get('/')->body, 'menu gone');
        self::assertSame(200, $emp->get('/qr')->status, 'other modules unaffected');
        $sys->post('/admin/modules/crypto/toggle', ['enabled' => '1', 'reason' => 'เปิดกลับ']);
        $this->t->app->modules()->forget();
        $emp2 = $this->t->browser();
        $emp2->login('employee.demo1');
        self::assertSame(200, $emp2->get('/crypto')->status);
    }

    public function testLinksDraftReviewPublishAudienceAndRedirect(): void
    {
        $db = $this->t->app->db();
        self::assertGreaterThanOrEqual(6, (int) $db->scalar("SELECT COUNT(*) FROM link_items WHERE status = 'draft'"), 'suggestions arrive as drafts');
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $sys->get('/admin/links/new');
        foreach (['javascript:alert(1)' => 'url', 'https://evil.example/?token=abc' => 'url', 'https://u:p@a.example/' => 'url'] as $bad => $field) {
            $res = $sys->post('/admin/links/new', ['name_th' => 'ลิงก์ร้าย', 'url' => $bad, 'category' => 'other']);
            self::assertSame(422, $res->status, $bad);
            self::assertStringContainsString('id="f-' . $field . '-err"', $res->body);
        }
        $res = $sys->post('/admin/links/new', ['name_th' => 'ระบบลางาน (DEMO)', 'url' => 'https://leave.example.co.th/', 'category' => 'hr', 'open_new_tab' => '1', 'audience_roles' => ['HRA']]);
        self::assertSame(303, $res->status, $res->body);
        $pid = basename((string) $res->headers['Location']);

        $hr = $this->t->browser();
        $hr->login('hr.demo1');
        self::assertStringNotContainsString('ระบบลางาน', $hr->get('/links')->body, 'a draft is invisible');
        // publish needs purpose + confirmation
        $sys->get('/admin/links/' . $pid);
        $sys->post('/admin/links/' . $pid . '/publish', ['purpose' => 'x']);
        self::assertSame('draft', $db->scalar('SELECT status FROM link_items WHERE public_id = ?', [$pid]));
        $sys->post('/admin/links/' . $pid . '/publish', ['purpose' => 'ให้ HR เข้าระบบลางานเดิม', 'risk_note' => 'บริการภายนอก', 'confirm' => '1']);
        self::assertSame(['published', 1], array_values($db->one('SELECT status, revision FROM link_items WHERE public_id = ?', [$pid]) ?? []));

        $page = $hr->get('/links')->body;
        self::assertStringContainsString('ระบบลางาน', $page);
        self::assertStringContainsString('rel="noopener noreferrer"', $page);
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        self::assertStringNotContainsString('ระบบลางาน', $emp->get('/links')->body, 'audience = HR only');
        self::assertSame(404, $emp->get('/links/go/' . $pid)->status);
        $go = $hr->get('/links/go/' . $pid);
        self::assertSame(303, $go->status);
        self::assertSame('https://leave.example.co.th/', $go->headers['Location']);
        self::assertSame('no-referrer', $go->headers['Referrer-Policy']);
        self::assertSame(1, (int) $db->scalar('SELECT SUM(clicks) FROM link_clicks'));
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'link_clicks' AND column_name LIKE '%user%'"), 'no user ids in click counts');

        // editing a published link keeps the live URL until the new revision is published
        $sys->get('/admin/links/' . $pid);
        $edit = $sys->post('/admin/links/' . $pid, ['name_th' => 'ระบบลางาน (DEMO)', 'url' => 'https://leave2.example.co.th/', 'category' => 'hr', 'audience_roles' => ['HRA']]);
        self::assertSame(303, $edit->status);
        self::assertSame('https://leave.example.co.th/', $hr->get('/links/go/' . $pid)->headers['Location']);
        $sys->post('/admin/links/' . $pid . '/publish', ['purpose' => 'ย้ายระบบลางาน', 'confirm' => '1']);
        self::assertSame('https://leave2.example.co.th/', $hr->get('/links/go/' . $pid)->headers['Location']);
        self::assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM link_revisions r JOIN link_items l ON l.id = r.link_id WHERE l.public_id = ?', [$pid]));
        // disable hides it; archive is final
        $sys->post('/admin/links/' . $pid . '/status', ['to' => 'disabled', 'reason' => 'ปิดปรับปรุง']);
        self::assertSame(404, $hr->get('/links/go/' . $pid)->status);
        $sys->post('/admin/links/' . $pid . '/status', ['to' => 'archived', 'reason' => 'เลิกใช้']);
        $sys->post('/admin/links/' . $pid . '/status', ['to' => 'published', 'reason' => 'กลับมา']);
        self::assertSame('archived', $db->scalar('SELECT status FROM link_items WHERE public_id = ?', [$pid]));
    }

    public function testCryptoToolsKeepNothing(): void
    {
        $db = $this->t->app->db();
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $b->get('/crypto');
        $audit = (int) $db->scalar('SELECT COUNT(*) FROM audit_events');
        $secret = 'Pa55-ลับมาก-' . bin2hex(random_bytes(4));
        $res = $b->post('/crypto', ['tool' => 'hash', 'password' => $secret, 'algo' => 'bcrypt', 'cost' => '10']);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('no-store', $res->headers['Cache-Control']);
        self::assertMatchesRegularExpression('/\$2y\$10\$[.\/A-Za-z0-9]{53}/', $res->body);
        self::assertStringNotContainsString($secret, $res->body, 'the password is never echoed back');
        preg_match('/(\$2y\$10\$[.\/A-Za-z0-9]{53})/', $res->body, $m);
        $ok = $b->post('/crypto', ['tool' => 'verify', 'password' => $secret, 'hash' => $m[1]]);
        self::assertStringContainsString('ตรงกัน', $ok->body);
        $dig = $b->post('/crypto', ['tool' => 'digest', 'text' => 'abc', 'algo' => 'sha256']);
        self::assertStringContainsString('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $dig->body);
        $file = $b->post('/crypto', ['tool' => 'digest', 'algo' => 'sha256'], ['file' => ['a.txt', 'abc']]);
        self::assertStringContainsString('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $file->body);
        self::assertSame(422, $b->post('/crypto', ['tool' => 'encode', 'text' => '%%%', 'codec' => 'base64', 'direction' => 'decode'])->status);
        self::assertSame($audit, (int) $db->scalar('SELECT COUNT(*) FROM audit_events'), 'tool use is not audited (inputs are not kept anywhere)');
        $logs = (string) implode('', array_map('file_get_contents', glob($this->t->app->root === '' ? '' : (string) $this->logDir() . '/*') ?: []));
        self::assertStringNotContainsString($secret, $logs);
    }

    public function testQrPreviewDownloadSaveManagedRedirectRevokeAndBatch(): void
    {
        $db = $this->t->app->db();
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $b->get('/qr');
        $form = ['kind' => 'static', 'content_type' => 'url', 'content' => 'https://example.com/menu?table=12', 'open_external' => '1', 'level' => 'M', 'name' => 'โต๊ะ 12', 'category' => 'menu'];
        $prev = $b->post('/qr/preview', $form);
        self::assertSame(200, $prev->status);
        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $prev->body);
        self::assertStringContainsString('https://example.com/menu?table=12&amp;openExternalBrowser=1', $prev->body, 'the exact encoded link is shown');
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM qr_codes'), 'preview saves nothing');
        $png = $b->post('/qr/download', $form + ['format' => 'png', 'size' => 's']);
        self::assertStringStartsWith("\x89PNG", $png->body);
        self::assertStringContainsString("filename*=UTF-8''", $png->headers['Content-Disposition']);
        $svg = $b->post('/qr/download', $form + ['format' => 'svg']);
        self::assertSame('image/svg+xml', $svg->headers['Content-Type']);
        $bad = $b->post('/qr/preview', ['content_type' => 'text', 'content' => 'เลขบัตร 1103702071811', 'level' => 'M']);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('ห้ามใส่ใน QR', $bad->body);

        $saved = $b->post('/qr/save', $form);
        self::assertSame(303, $saved->status, $saved->body);
        $static = basename((string) $saved->headers['Location']);
        self::assertSame(200, $b->get('/qr/' . $static)->status);
        self::assertStringStartsWith("\x89PNG", $b->get('/qr/' . $static . '/file', ['format' => 'png'])->body);

        // managed: short link that works without signing in, then revoked
        $m = $b->post('/qr/save', ['kind' => 'managed', 'content' => 'https://forms.example.com/survey', 'level' => 'Q', 'name' => 'แบบสำรวจ', 'expires' => '2027-01-31']);
        self::assertSame(303, $m->status, $m->body);
        $managed = basename((string) $m->headers['Location']);
        $token = (string) $db->scalar('SELECT token FROM qr_codes WHERE public_id = ?', [$managed]);
        self::assertMatchesRegularExpression('/^[0-9A-Za-z]{10}$/', $token);
        self::assertStringContainsString('/q/' . $token, $b->get('/qr/' . $managed)->body);
        $guest = $this->t->browser();
        $go = $guest->get('/q/' . $token);
        self::assertSame(302, $go->status);
        self::assertSame('https://forms.example.com/survey', $go->headers['Location']);
        self::assertSame(1, (int) $db->scalar('SELECT scan_count FROM qr_codes WHERE public_id = ?', [$managed]));
        $b->post('/qr/' . $managed, ['do' => 'destination', 'url' => 'https://forms.example.com/survey-v2', 'reason' => 'แบบสำรวจรอบใหม่']);
        self::assertSame('https://forms.example.com/survey-v2', $guest->get('/q/' . $token)->headers['Location']);
        self::assertSame(404, $guest->get('/q/AAAAAAAAAA')->status);

        // someone else cannot see it; qr.manage can
        $other = $this->t->browser();
        $other->login('hr.demo1');
        self::assertSame(404, $other->get('/qr/' . $managed)->status);
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        self::assertSame(200, $sys->get('/qr/' . $managed)->status);

        $b->post('/qr/' . $managed, ['do' => 'revoke', 'reason' => 'จบกิจกรรม']);
        self::assertSame(410, $guest->get('/q/' . $token)->status);
        self::assertStringContainsString('ยกเลิก', $guest->get('/q/' . $token)->body);
        // expiry
        $e = $b->post('/qr/save', ['kind' => 'managed', 'content' => 'https://a.example/x', 'level' => 'M', 'name' => 'หมดอายุ', 'expires' => '2026-11-10']);
        $tok2 = (string) $db->scalar('SELECT token FROM qr_codes WHERE public_id = ?', [basename((string) $e->headers['Location'])]);
        self::assertSame(302, $guest->get('/q/' . $tok2)->status);
        $this->t->clock->advance('+3 days');
        self::assertSame(410, $guest->get('/q/' . $tok2)->status);

        // batch → ZIP with images + report; the row with an ID number is skipped (fresh sign-in: the clock moved 3 days)
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $b->get('/qr/batch');
        $csv = Csv::write(['ชื่อ', 'ลิงก์หรือข้อความ', 'หมวด'], [['ห้อง 1', 'https://example.com/rooms/1', 'rooms'], ['ห้อง 2', 'ห้องประชุม 2', ''], ['ผิด', 'เลข 1103702071811', '']]);
        $zip = $b->post('/qr/batch', ['level' => 'M', 'format' => 'both', 'size' => 's'], ['file' => ['rooms.csv', $csv]]);
        self::assertSame(200, $zip->status, mb_substr($zip->body, 0, 300));
        self::assertSame('application/zip', $zip->headers['Content-Type']);
        $tmp = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents((string) $tmp, $zip->body);
        $z = ZipStream::open((string) $tmp);
        $names = $z->names();
        self::assertContains('report.csv', $names);
        self::assertCount(5, $names, 'two rows × PNG+SVG + report');
        self::assertStringContainsString('ห้ามใส่ใน QR', $z->read('report.csv'));
        @unlink((string) $tmp);
        self::assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM qr_codes'), 'batch saves nothing (3 saved above)');
    }

    private function logDir(): string
    {
        $r = new \ReflectionProperty($this->t, 'tmp');
        $r->setAccessible(true);
        return (string) $r->getValue($this->t) . '/logs';
    }
}
