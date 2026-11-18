<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Tests\Support\TestApp;

/**
 * 0.38.0 (R2): QR generator — content type cards (link, text, phone, e-mail, Wi-Fi, contact card), colours and
 * decoration (default = the system primary colour, contrast checked), logo size (default ×2) and the logo kept in
 * downloads; saved codes can be edited and deleted (a deleted managed code stops redirecting).
 */
final class QrStyleWebTest extends TestCase
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

    public function testTypesStylesLogoDownloadsEditAndDelete(): void
    {
        $db = $this->t->app->db();
        $b = $this->t->browser();
        $b->login('employee.demo1');
        $page = $b->get('/qr')->body;
        foreach (['url', 'text', 'tel', 'email', 'wifi', 'vcard'] as $ty) {
            self::assertMatchesRegularExpression('#<label class="choice choice--block qr-type"><input type="radio" name="content_type" value="' . $ty . '"#', $page, 'content type card ' . $ty);
        }
        $primary = $this->t->app->appearance()->primaryHex();
        self::assertStringContainsString('name="fg" value="' . $primary . '"', $page, 'default colour = the system primary colour');
        self::assertStringContainsString('<option value="xl" selected', $page, 'logo ×2 by default');
        self::assertStringContainsString('ui/js/qr.js', $page);
        self::assertStringContainsString('ใช้รูปดาวของโลโก้ Blue แทน', $page, 'no company logo uploaded → the built-in star');

        // Wi-Fi with styles: preview shows the payload and the warning, colours are in the symbol
        $wifi = ['kind' => 'static', 'content_type' => 'wifi', 'wifi_ssid' => 'Guest;5G', 'wifi_pass' => 'hello-guest', 'wifi_enc' => 'WPA', 'level' => 'M',
            'fg' => '#0b1f44', 'bg' => '#FFFFFF', 'module' => 'dot', 'eye_style' => 'rounded', 'logo' => '1', 'logo_size' => 'xl', 'name' => 'Wi-Fi แขก'];
        $prev = $b->post('/qr/preview', $wifi);
        self::assertSame(200, $prev->status, $prev->body);
        self::assertStringContainsString('WIFI:T:WPA;S:Guest\\;5G;P:hello-guest;;', html_entity_decode($prev->body));
        self::assertStringContainsString('อย่าใช้กับเครือข่ายภายในของบริษัท', $prev->body);
        self::assertStringContainsString('<g fill="#0B1F44"><circle', $prev->body);

        // downloads keep the logo: SVG with xlink:href, PNG with the star in the centre
        $svg = $b->post('/qr/download', $wifi + ['format' => 'svg']);
        self::assertSame('image/svg+xml', $svg->headers['Content-Type']);
        self::assertMatchesRegularExpression('#href="data:image/png;base64,[^"]+" xlink:href="data:image/png;base64,#', $svg->body);
        $png = $b->post('/qr/download', $wifi + ['format' => 'png', 'size' => 'm']);
        self::assertStringStartsWith("\x89PNG", $png->body);
        $img = imagecreatefromstring($png->body);
        self::assertNotFalse($img);
        $c = imagecolorsforindex($img, imagecolorat($img, intdiv(imagesx($img), 2), intdiv(imagesy($img), 2)));
        self::assertGreaterThan($c['red'] + 60, $c['blue'], 'the logo is in the downloaded PNG (0.38.0 fix)');

        // contrast and colour checks
        $pale = $b->post('/qr/preview', ['content_type' => 'url', 'content' => 'https://example.com', 'fg' => '#DDDDDD', 'bg' => '#FFFFFF']);
        self::assertSame(422, $pale->status);
        self::assertStringContainsString('ตัดกันอย่างน้อย 4:1', $pale->body);
        self::assertSame(422, $b->post('/qr/preview', ['content_type' => 'url', 'content' => 'https://example.com', 'fg' => '#FFFFFF', 'bg' => '#000000'])->status, 'inverted codes refused');
        self::assertSame(422, $b->post('/qr/preview', ['content_type' => 'url', 'content' => 'https://example.com', 'eye' => '#F0F0F0'])->status, 'pale eyes refused');
        self::assertSame(422, $b->post('/qr/preview', ['content_type' => 'tel', 'tel' => 'abc'])->status);
        self::assertSame(200, $b->post('/qr/preview', ['content_type' => 'vcard', 'vc_name' => 'สมชาย ใจดี', 'vc_tel' => '0812345678'])->status);

        // save a static phone code → edit it into an e-mail code with new colours → the history names fields only
        $saved = $b->post('/qr/save', ['kind' => 'static', 'content_type' => 'tel', 'tel' => '02-123-4567', 'name' => 'สายด่วน HR', 'module' => 'rounded']);
        self::assertSame(303, $saved->status, $saved->body);
        $pid = basename((string) $saved->headers['Location']);
        $row = $db->one('SELECT * FROM qr_codes WHERE public_id = ?', [$pid]);
        self::assertSame(['tel', 'tel:021234567'], [$row['content_type'], $row['content']]);
        self::assertSame(['tel' => '02-123-4567'], json_decode((string) $row['fields_json'], true));
        self::assertSame(['fg' => $primary, 'bg' => '#FFFFFF', 'eye' => $primary, 'module' => 'rounded', 'eye_style' => 'square', 'logo_size' => 'xl'], json_decode((string) $row['style_json'], true));
        $show = $b->get('/qr/' . $pid)->body;
        self::assertStringContainsString('href="/qr/' . $pid . '/edit"', $show);
        self::assertStringContainsString('name="do" value="delete"', $show);
        $form = $b->get('/qr/' . $pid . '/edit')->body;
        self::assertMatchesRegularExpression('#type="tel" value="02-123-4567"[^>]*name="tel"#', $form, 'edit form is pre-filled from the saved fields');
        self::assertMatchesRegularExpression('#name="content_type" value="tel" checked#', $form);
        self::assertStringContainsString('QR ที่พิมพ์ไปแล้วยังเป็นข้อมูลเดิม', $form);
        $up = $b->post('/qr/' . $pid . '/edit', ['content_type' => 'email', 'email_to' => 'hr@example.com', 'email_subject' => 'สอบถาม', 'name' => 'อีเมล HR', 'category' => 'hr',
            'fg' => '#1E3A5F', 'module' => 'square', 'eye_style' => 'circle', 'logo_size' => 'm', 'level' => 'Q']);
        self::assertSame(303, $up->status, $up->body);
        $row = $db->one('SELECT * FROM qr_codes WHERE public_id = ?', [$pid]);
        self::assertSame(
            ['email', 'mailto:hr@example.com?subject=%E0%B8%AA%E0%B8%AD%E0%B8%9A%E0%B8%96%E0%B8%B2%E0%B8%A1', 'อีเมล HR', 'hr', 'Q'],
            [$row['content_type'], $row['content'], $row['name'], $row['category'], $row['ec_level']]
        );
        self::assertSame('#1E3A5F', json_decode((string) $row['style_json'], true)['fg']);
        $ev = (string) $db->scalar("SELECT detail_json FROM qr_events WHERE qr_id = ? AND action = 'edited' ORDER BY id DESC LIMIT 1", [$row['id']]);
        self::assertStringContainsString('"content"', $ev);
        self::assertStringNotContainsString('hr@example.com', $ev, 'history names what changed, not the values');
        self::assertSame(422, $b->post('/qr/' . $pid . '/edit', ['content_type' => 'email', 'email_to' => 'x', 'name' => 'อีเมล HR'])->status);
        self::assertStringStartsWith("\x89PNG", $b->get('/qr/' . $pid . '/file', ['format' => 'png'])->body);

        // someone else can neither edit nor delete it
        $other = $this->t->browser();
        $other->login('manager.demo1');
        self::assertSame(404, $other->get('/qr/' . $pid . '/edit')->status);
        $other->get('/qr');
        self::assertSame(404, $other->post('/qr/' . $pid, ['do' => 'delete', 'confirm' => '1'])->status);

        // delete: confirmation required, then gone from the list and the page
        $b->get('/qr/' . $pid);
        $b->post('/qr/' . $pid, ['do' => 'delete']);
        self::assertNull($db->scalar('SELECT deleted_at FROM qr_codes WHERE public_id = ?', [$pid]), 'not without the confirmation');
        $del = $b->post('/qr/' . $pid, ['do' => 'delete', 'confirm' => '1']);
        self::assertSame('/qr', $del->headers['Location']);
        self::assertNotNull($db->scalar('SELECT deleted_at FROM qr_codes WHERE public_id = ?', [$pid]));
        self::assertSame(404, $b->get('/qr/' . $pid)->status);
        self::assertStringNotContainsString('/qr/' . $pid, $b->get('/qr')->body);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'qr.deleted' AND target_id = ?", [$pid]));

        // managed: edit colours/logo keeps the short link and destination; delete stops the redirect at once
        $m = $b->post('/qr/save', ['kind' => 'managed', 'content' => 'https://forms.example.com/survey', 'name' => 'แบบสำรวจ', 'expires' => '2027-01-31', 'level' => 'M']);
        self::assertSame(303, $m->status, $m->body);
        $mp = basename((string) $m->headers['Location']);
        $before = $db->one('SELECT token, content, content_type FROM qr_codes WHERE public_id = ?', [$mp]);
        self::assertSame('url', $before['content_type']);
        $mform = $b->get('/qr/' . $mp . '/edit')->body;
        self::assertStringNotContainsString('name="content"', $mform, 'a managed code changes its link on the code page, with a reason');
        self::assertSame(303, $b->post('/qr/' . $mp . '/edit', ['name' => 'แบบสำรวจ 2026', 'fg' => '#0B1F44', 'logo' => '1', 'logo_size' => 'l', 'content' => 'https://evil.example'])->status);
        $after = $db->one('SELECT token, content, name, with_logo, ec_level FROM qr_codes WHERE public_id = ?', [$mp]);
        self::assertSame([$before['token'], $before['content'], 'แบบสำรวจ 2026', 1, 'H'], [$after['token'], $after['content'], $after['name'], (int) $after['with_logo'], $after['ec_level']]);
        $guest = $this->t->browser();
        self::assertSame(302, $guest->get('/q/' . $before['token'])->status);
        $b->get('/qr/' . $mp);
        $b->post('/qr/' . $mp, ['do' => 'delete', 'confirm' => '1']);
        $gone = $guest->get('/q/' . $before['token']);
        self::assertSame(410, $gone->status);
        self::assertStringContainsString('เจ้าของได้ลบ QR นี้แล้ว', $gone->body);
    }
}
