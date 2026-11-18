<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Pdf\Reader;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/** Phase 3U-C through the HTTP kernel: S01 PDF toolkit and M29 lucky draw. */
final class UtilitiesCWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(pdf|draw|file|common|form)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';

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

    /** An event made the pre-0.11.2 way (list locked first, prizes in advance) — still opened, drawn and verified. */
    private function classic(string $user, array $in): string
    {
        $az = new \Blue\Core\Authz\Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
        return \Blue\Modules\Draw\DrawService::of($this->t->app)->create($az, $in, null);
    }

    private static function fx(string $n): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/' . $n);
    }

    private function lastJob(): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM pdf_jobs ORDER BY id DESC LIMIT 1');
    }

    public function testPagesRenderTranslated(): void
    {
        foreach (['employee.demo1' => ['/pdf', '/pdf/tool/merge', '/pdf/tool/protect', '/pdf/tool/images', '/draw'], TestApp::ADMIN => ['/pdf/admin', '/draw']] as $user => $paths) {
            $b = $this->browser($user);
            foreach ($paths as $p) {
                $res = $b->get($p);
                self::assertSame(200, $res->status, "{$user} {$p}");
                $text = strip_tags((string) preg_replace('/<(script|style|svg)\b.*?<\/\1>/s', '', $res->body));
                self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, $text, "untranslated key on {$p}");
            }
        }
        self::assertArrayHasKey('pdf.cleanup', $this->t->app->jobs()->all(), 'module job registered from module.json');
    }

    public function testPdfStagingRunDownloadPrivacyAndRecords(): void
    {
        $db = $this->t->app->db();
        $emp = $this->browser('employee.demo1');
        $emp->get('/pdf/tool/merge');
        self::assertSame(422, $emp->post('/pdf/tool/merge', [], ['files' => [['one.pdf', self::fx('a.pdf')]]])->status, 'merge needs two files');
        self::assertSame(422, $emp->post('/pdf/tool/merge', [], ['files' => [['x.pdf', 'not a pdf'], ['y.pdf', self::fx('a.pdf')]]])->status, 'fake PDF');
        self::assertSame(422, $emp->post('/pdf/tool/merge', [], ['files' => [['js.pdf', self::fx('js.pdf')], ['a.pdf', self::fx('a.pdf')]]])->status, 'JavaScript refused');
        self::assertSame(422, $emp->post('/pdf/tool/merge', [], ['files' => [['enc.pdf', self::fx('a_aes256.pdf')], ['a.pdf', self::fx('a.pdf')]]])->status, 'password needed');
        $res = $emp->post('/pdf/tool/merge', ['password' => 'user1'], ['files' => [['enc.pdf', self::fx('a_aes256.pdf')], ['b.pdf', self::fx('b.pdf')]]]);
        self::assertSame(303, $res->status);
        $pid = $this->lastJob();
        $job = $emp->get('/pdf/job/' . $pid);
        self::assertStringContainsString('AES-256', $job->body, 'shows the source was encrypted');
        self::assertStringNotContainsString('user1', (string) $db->scalar('SELECT CONCAT(inputs_json, IFNULL(params_json, \'\')) FROM pdf_jobs WHERE public_id = ?', [$pid]));
        $emp->post('/pdf/job/' . $pid . '/run', ['order' => ['2', '1']]);
        self::assertSame('done', $db->scalar('SELECT status FROM pdf_jobs WHERE public_id = ?', [$pid]));
        $file = $emp->get('/pdf/job/' . $pid . '/file/0');
        self::assertSame('application/pdf', $file->headers['Content-Type']);
        $pages = (new Reader($file->body))->pages();
        self::assertCount(5, $pages, 'b (2) then a (3)');
        $again = $emp->post('/pdf/job/' . $pid . '/run', ['order' => ['1', '2']]);
        self::assertSame(303, $again->status, 'a second run shows the same result');
        self::assertSame(1, count((array) json_decode((string) $db->scalar('SELECT outputs_json FROM pdf_jobs WHERE public_id = ?', [$pid]), true)));

        $other = $this->browser('hr.demo1');
        self::assertSame(404, $other->get('/pdf/job/' . $pid)->status, 'jobs are private');
        self::assertSame(404, $other->get('/pdf/job/' . $pid . '/file/0')->status);

        // the employee has no right to save to the register; HR does
        $emp->get('/pdf/job/' . $pid);
        $emp->post('/pdf/job/' . $pid . '/file/0/save', ['class' => 'core.general', 'legal_entity_id' => (string) $this->le(), 'title' => 'x']);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM documents WHERE related_type = 'pdf_job'"));
        $other->get('/pdf/tool/extract');
        $other->post('/pdf/tool/extract', [], ['files' => ['a.pdf', self::fx('a.pdf')]]);
        $hj = $this->lastJob();
        $other->get('/pdf/job/' . $hj);
        self::assertSame(422, $other->post('/pdf/job/' . $hj . '/run', ['pages' => '9'])->status, 'page out of range');
        $other->post('/pdf/job/' . $hj . '/run', ['pages' => '2']);
        $other->get('/pdf/job/' . $hj);
        $other->post('/pdf/job/' . $hj . '/file/0/save', ['class' => 'core.general', 'legal_entity_id' => (string) $this->le(), 'title' => 'หน้า 2']);
        $doc = $db->one("SELECT * FROM documents WHERE related_type = 'pdf_job' AND related_id = ?", [$hj]);
        self::assertNotNull($doc);
        self::assertStringContainsString('S01 extract', (string) $doc['description'], 'provenance');

        // expiry: files removed, history kept
        $this->t->clock->advance('+25 hours');
        \Blue\Modules\Pdf\PdfService::of($this->t->app)->cleanup();
        self::assertSame([], glob($this->t->app->path($this->t->app->config->string('PDF_TEMP_PATH', 'storage/pdf-temp')) . '/' . $pid . '-*') ?: []);
        self::assertNotNull($db->scalar('SELECT cleaned_at FROM pdf_jobs WHERE public_id = ?', [$pid]));
        $emp2 = $this->browser('employee.demo1');
        self::assertSame(303, $emp2->get('/pdf/job/' . $pid . '/file/0')->status, 'gone → back to the job page');
    }

    public function testPasswordsAreNeverStoredAndToolsCanBeSwitchedOff(): void
    {
        $db = $this->t->app->db();
        $emp = $this->browser('employee.demo1');
        $emp->get('/pdf/tool/protect');
        $emp->post('/pdf/tool/protect', [], ['files' => ['a.pdf', self::fx('a.pdf')]]);
        $pid = $this->lastJob();
        $emp->get('/pdf/job/' . $pid);
        $bad = $emp->post('/pdf/job/' . $pid . '/run', ['user_password' => 'Sup3r-Secret-99', 'user_password_confirm' => 'nope', 'owner_password' => 'Owner-Secret-77']);
        self::assertSame(422, $bad->status);
        self::assertStringNotContainsString('Sup3r-Secret-99', $bad->body, 'never echoed back');
        $emp->post('/pdf/job/' . $pid . '/run', ['user_password' => 'Sup3r-Secret-99', 'user_password_confirm' => 'Sup3r-Secret-99', 'owner_password' => 'Owner-Secret-77', 'allow_print' => '1']);
        $out = $emp->get('/pdf/job/' . $pid . '/file/0')->body;
        self::assertSame('AES-256', (new Reader($out, 'Sup3r-Secret-99'))->security?->method());
        self::assertSame('AES-256', (new Reader($out, 'Owner-Secret-77'))->security?->method());
        $everything = (string) $db->scalar('SELECT CONCAT(inputs_json, params_json, IFNULL(notes_json, \'\')) FROM pdf_jobs WHERE public_id = ?', [$pid])
            . implode('', array_column($db->all('SELECT CONCAT(IFNULL(reason, \'\'), IFNULL(diff_json, \'\')) AS x FROM audit_events'), 'x'));
        foreach (glob($this->t->app->path('storage/logs') . '/*') ?: [] as $log) {
            $everything .= (string) file_get_contents($log);
        }
        self::assertStringNotContainsString('Sup3r-Secret-99', $everything);
        self::assertStringNotContainsString('Owner-Secret-77', $everything);

        $admin = $this->browser(TestApp::ADMIN);
        $admin->get('/pdf/admin');
        $on = [];
        foreach (\Blue\Modules\Pdf\PdfFeatures::switchable() as $c) {
            if ($c !== 'merge') {
                $on[$c] = '1';
            }
        }
        $admin->post('/pdf/admin', ['enabled' => $on, 'reason' => 'ทดสอบปิด', 'max_mb' => '5']);
        self::assertSame(['merge'], $this->t->app->registry()->resolveOrNull('pdf.disabled_features')?->value);
        $emp->get('/pdf/tool/merge');
        self::assertSame(422, $emp->post('/pdf/tool/merge', [], ['files' => [['a.pdf', self::fx('a.pdf')], ['b.pdf', self::fx('b.pdf')]]])->status, 'switched off');
        self::assertSame(403, $emp->get('/pdf/admin')->status);
    }

    public function testLuckyDrawFromListToVerifiedPrivateResults(): void
    {
        $db = $this->t->app->db();
        $emp = $this->browser('employee.demo1');
        self::assertSame(200, $emp->get('/draw')->status);
        try {
            $this->classic('employee.demo1', ['name' => 'x']);
            self::fail('name too short');
        } catch (\Blue\Core\Error\ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors);
        }

        $hr = $this->browser('hr.demo1');
        $hr->get('/draw');
        $pid = $this->classic('hr.demo1', ['name' => 'จับรางวัลทดสอบ', 'mode' => 'wheel', 'no_repeat' => '1']);
        $hr->get('/draw/' . $pid);
        $names = "รหัส,ชื่อ,ฝ่าย\n" . implode("\n", array_map(static fn (int $i): string => "E{$i},คนที่ {$i},ฝ่าย " . ($i % 3), range(1, 12))) . "\nE3,ซ้ำ,x\n,,\n";
        $rep = $hr->post('/draw/' . $pid . '/participants', ['text' => $names, 'mode' => 'replace']);
        self::assertStringContainsString('12', $rep->body);
        self::assertSame(12, (int) $db->scalar('SELECT COUNT(*) FROM draw_participants'));
        $hr->post('/draw/' . $pid . '/prizes', ['prize_name' => ['ทีวี', 'ร่ม', ''], 'prize_qty' => ['1', '20', '']]);
        $hr->get('/draw/' . $pid);
        self::assertSame(422, $hr->post('/draw/' . $pid . '/lock', ['law_ack' => '1'])->status, '21 prizes for 12 people (no repeats)');
        $hr->post('/draw/' . $pid . '/prizes', ['prize_name' => ['ทีวี', 'ร่ม'], 'prize_qty' => ['1', '3']]);
        $hr->get('/draw/' . $pid);
        self::assertSame(422, $hr->post('/draw/' . $pid . '/lock', [])->status, 'legal confirmation required');
        $hr->post('/draw/' . $pid . '/lock', ['law_ack' => '1']);
        $ev = $db->one('SELECT * FROM draw_events WHERE public_id = ?', [$pid]);
        self::assertSame('ready', $ev['status']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $ev['commitment']);
        self::assertSame(303, $hr->post('/draw/' . $pid . '/participants', ['text' => 'x'])->status, 'refused with a message');
        self::assertSame(12, (int) $db->scalar('SELECT COUNT(*) FROM draw_participants'), 'locked list cannot change');

        $prizes = $db->all('SELECT id FROM draw_prizes ORDER BY sort');
        $hr->get('/draw/' . $pid . '/show');
        $token = str_repeat('a', 32);
        $r1 = $hr->post('/draw/' . $pid . '/round', ['prize' => (string) $prizes[1]['id'], 'count' => '3', 'token' => $token]);
        self::assertSame(200, $r1->status, $r1->body);
        $j1 = json_decode($r1->body, true);
        self::assertCount(3, $j1['winners']);
        self::assertSame(0, $j1['prize']['remaining']);
        $r2 = $hr->post('/draw/' . $pid . '/round', ['prize' => (string) $prizes[1]['id'], 'count' => '3', 'token' => $token]);
        self::assertSame($j1['winners'], json_decode($r2->body, true)['winners'], 'double click / refresh → same round');
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM draw_rounds'));
        self::assertSame(422, $hr->post('/draw/' . $pid . '/round', ['prize' => (string) $prizes[1]['id'], 'count' => '1', 'token' => str_repeat('b', 32)])->status, 'no more of that prize');
        $hr->post('/draw/' . $pid . '/round', ['prize' => (string) $prizes[0]['id'], 'count' => '1', 'token' => str_repeat('c', 32)]);
        $winnerIds = array_map('intval', array_column($db->all("SELECT participant_id FROM draw_winners WHERE status = 'active'"), 'participant_id'));
        self::assertCount(4, array_unique($winnerIds), 'no one wins twice');
        self::assertSame(303, $hr->post('/draw/' . $pid . '/unlock', ['reason' => 'ลืมชื่อ'])->status);
        self::assertSame('drawing', $db->scalar('SELECT status FROM draw_events WHERE public_id = ?', [$pid]), 'no unlock after drawing');

        // redraw keeps the voided winner
        $w = $db->one("SELECT id FROM draw_winners WHERE prize_id = ? AND status = 'active' LIMIT 1", [$prizes[0]['id']]);
        $hr->get('/draw/' . $pid);
        $hr->post('/draw/' . $pid . '/redraw', ['prize' => (string) $prizes[0]['id'], 'winner' => (string) $w['id'], 'reason' => 'ไม่อยู่ในงาน', 'token' => str_repeat('d', 32)]);
        self::assertSame('void', $db->scalar('SELECT status FROM draw_winners WHERE id = ?', [$w['id']]));
        self::assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM draw_rounds'));
        self::assertSame('ไม่อยู่ในงาน', $db->scalar('SELECT reason FROM draw_rounds WHERE round_no = 3'));

        self::assertSame(404, $emp->get('/draw/' . $pid . '/results')->status, 'someone else\'s draw');
        $hr->get('/draw/' . $pid);
        $hr->post('/draw/' . $pid . '/confirm', ['witness' => 'พยาน']);
        $hr->post('/draw/' . $pid . '/publish', ['on' => '1']);
        $svc = \Blue\Modules\Draw\DrawService::of($this->t->app);
        $ev = $db->one('SELECT * FROM draw_events WHERE public_id = ?', [$pid]);
        $v = $svc->verify($ev);
        self::assertTrue($v['ok'], 'every round recomputes from the revealed seed');
        self::assertCount(3, $v['rounds']);
        // 0.6.1: results are private to the organizer — even "published" ones, and even for draw.manage (SYS)
        self::assertSame(404, $emp->get('/draw/' . $pid . '/results')->status);
        self::assertStringNotContainsString('จับรางวัลทดสอบ', $emp->get('/draw')->body, 'not listed for others');
        $sys = $this->browser(TestApp::ADMIN);
        self::assertSame(404, $sys->get('/draw/' . $pid)->status, 'administrators do not see other people\'s draws');
        self::assertStringNotContainsString('จับรางวัลทดสอบ', $sys->get('/draw')->body);
        $res = $hr->get('/draw/' . $pid . '/results');
        self::assertSame(200, $res->status);
        self::assertStringContainsString((string) $svc->revealedSeed($ev), $res->body);
        self::assertSame(404, $emp->get('/draw/' . $pid)->status, 'organizer pages stay private');
        self::assertSame(409, $hr->post('/draw/' . $pid . '/round', ['prize' => (string) $prizes[0]['id'], 'count' => '1', 'token' => str_repeat('e', 32)])->status, 'no draws after confirming');
        $xlsx = $hr->get('/draw/' . $pid . '/export');
        self::assertStringStartsWith('PK', $xlsx->body);
    }

    /** 0.6.1: Excel template, "lucky order" mode (no prizes), and reset with a new generation. */
    public function testDrawTemplateSequenceModeAndReset(): void
    {
        $db = $this->t->app->db();
        $emp = $this->browser('employee.demo1');
        $tpl = $emp->get('/draw/template');
        self::assertSame(200, $tpl->status);
        self::assertStringStartsWith('PK', $tpl->body);
        self::assertStringContainsString('filename*=UTF-8', $tpl->headers['Content-Disposition'] ?? '');
        $rows = \Blue\Core\Files\Xlsx::read($tpl->body);
        self::assertSame(['รหัส', 'ชื่อ', 'กลุ่ม/ฝ่าย'], $rows[0], 'header only — no example rows in the data sheet');
        self::assertCount(1, array_filter($rows, static fn (array $r): bool => implode('', $r) !== ''));

        $emp->get('/draw');
        $this->classic('employee.demo1', ['name' => 'จับฉลากลำดับ', 'mode' => 'wheel', 'prize_mode' => 'sequence', 'seq_count' => '4', 'no_repeat' => '1']);
        $ev = $db->one('SELECT * FROM draw_events ORDER BY id DESC LIMIT 1');
        self::assertSame('sequence', $ev['prize_mode']);
        self::assertSame(1, (int) $ev['no_repeat'], 'the organizer chose to take drawn names out (0.11.1)');
        self::assertSame(4, (int) $ev['seq_count']);
        $pid = (string) $ev['public_id'];
        // the filled template imports as a file (header row skipped)
        $file = \Blue\Core\Files\Xlsx::write(['รายชื่อ' => ['headers' => ['รหัส', 'ชื่อ', 'กลุ่ม'], 'rows' => array_map(static fn (int $i): array => ['S' . $i, 'ลำดับ ' . $i, ''], range(1, 6))]]);
        $emp->get('/draw/' . $pid);
        $emp->post('/draw/' . $pid . '/participants', ['mode' => 'replace'], ['file' => ['list.xlsx', $file]]);
        self::assertSame(6, (int) $db->scalar('SELECT COUNT(*) FROM draw_participants WHERE event_id = ?', [$ev['id']]));
        $emp->get('/draw/' . $pid);
        $emp->post('/draw/' . $pid . '/lock', ['law_ack' => '1']);
        $prize = $db->one('SELECT * FROM draw_prizes WHERE event_id = ? AND retired = 0', [$ev['id']]);
        self::assertSame(4, (int) $prize['quantity'], 'one implicit "lucky order" prize, as long as asked');
        $j = json_decode($emp->post('/draw/' . $pid . '/round', ['prize' => (string) $prize['id'], 'count' => '3', 'token' => str_repeat('1', 32)])->body, true);
        self::assertSame([1, 2, 3], array_column($j['winners'], 'seq'), 'places 1, 2, 3 in draw order');
        $j2 = json_decode($emp->post('/draw/' . $pid . '/round', ['prize' => (string) $prize['id'], 'count' => '1', 'token' => str_repeat('2', 32)])->body, true);
        self::assertSame([4], array_column($j2['winners'], 'seq'));
        // a redraw takes over the place of the winner it replaces
        $second = $db->one('SELECT * FROM draw_winners WHERE event_id = ? AND seq_no = 2', [$ev['id']]);
        $emp->get('/draw/' . $pid);
        $emp->post('/draw/' . $pid . '/redraw', ['prize' => (string) $prize['id'], 'winner' => (string) $second['id'], 'reason' => 'ไม่อยู่', 'token' => str_repeat('3', 32)]);
        self::assertSame(2, (int) $db->scalar("SELECT seq_no FROM draw_winners WHERE event_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$ev['id']]));
        $page = $emp->get('/draw/' . $pid)->body;
        self::assertStringContainsString('class="draw-seq"', $page);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page), 'no raw keys');
        $before = (string) $db->scalar('SELECT commitment FROM draw_events WHERE id = ?', [$ev['id']]);
        $seedBefore = bin2hex($this->t->app->crypto()->decrypt((string) base64_decode((string) $db->scalar('SELECT seed_enc FROM draw_events WHERE id = ?', [$ev['id']]), true)));

        // reset: reason required; back to draft, new generation, earlier rounds kept but no longer shown
        self::assertSame(422, $emp->post('/draw/' . $pid . '/reset', ['reason' => ''])->status);
        $emp->get('/draw/' . $pid);
        self::assertSame(303, $emp->post('/draw/' . $pid . '/reset', ['reason' => 'ซ้อมก่อนงานจริง'])->status);
        $after = $db->one('SELECT * FROM draw_events WHERE id = ?', [$ev['id']]);
        self::assertSame('draft', $after['status']);
        self::assertSame(2, (int) $after['generation']);
        self::assertNull($after['commitment']);
        self::assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM draw_rounds WHERE event_id = ?', [$ev['id']]), 'nothing deleted');
        $log = json_decode((string) $db->scalar("SELECT detail_json FROM draw_log WHERE event_id = ? AND action = 'reset'", [$ev['id']]), true);
        self::assertSame($before, $log['old_commitment']);
        self::assertSame($seedBefore, $log['old_seed'], 'old seed revealed in the log so the earlier draws stay checkable');
        self::assertSame(\Blue\Modules\Draw\DrawEngine::commitment((string) hex2bin($seedBefore)), $before);
        self::assertSame([], \Blue\Modules\Draw\DrawService::of($this->t->app)->winners((int) $ev['id']), 'earlier generation hidden');
        // draw again in the new generation: places start at 1 again, verification only covers the new rounds
        $emp->get('/draw/' . $pid);
        $emp->post('/draw/' . $pid . '/lock', ['law_ack' => '1']);
        $prize2 = $db->one('SELECT * FROM draw_prizes WHERE event_id = ? AND retired = 0', [$ev['id']]);
        $j3 = json_decode($emp->post('/draw/' . $pid . '/round', ['prize' => (string) $prize2['id'], 'count' => '2', 'token' => str_repeat('4', 32)])->body, true);
        self::assertSame([1, 2], array_column($j3['winners'], 'seq'));
        self::assertSame(1, (int) $db->scalar('SELECT round_no FROM draw_rounds WHERE event_id = ? AND generation = 2', [$ev['id']]));
        $emp->get('/draw/' . $pid);
        $emp->post('/draw/' . $pid . '/confirm', []);
        $v = \Blue\Modules\Draw\DrawService::of($this->t->app)->verify($db->one('SELECT * FROM draw_events WHERE id = ?', [$ev['id']]));
        self::assertTrue($v['ok']);
        self::assertCount(1, $v['rounds']);
        // other people cannot reset someone else's draw
        $hr = $this->browser('hr.demo1');
        $hr->get('/draw');
        self::assertSame(404, $hr->post('/draw/' . $pid . '/reset', ['reason' => 'ลองดู'])->status);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    /** 0.11.1 classic events: sequence draws that keep drawn names in the pool; start goes straight to the draw screen. */
    public function testClassicSequenceThatKeepsDrawnNames(): void
    {
        $db = $this->t->app->db();
        $emp = $this->browser('employee.demo1');
        $pid = $this->classic('employee.demo1', ['name' => 'ใครตอบคำถาม', 'prize_mode' => 'sequence', 'seq_count' => '7', 'no_repeat' => '0']);
        $ev = $db->one('SELECT * FROM draw_events WHERE public_id = ?', [$pid]);
        self::assertSame(0, (int) $ev['no_repeat'], 'kept as chosen — names stay in the pool');
        $emp->get('/draw/' . $pid);
        $emp->post('/draw/' . $pid . '/participants', ['text' => "เอ\nบี\nซี\nบี", 'mode' => 'replace']);
        self::assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM draw_participants WHERE event_id = ?', [$ev['id']]), 'repeat skipped');
        $edit = $emp->get('/draw/' . $pid)->body;
        self::assertStringContainsString('draw-start is-ready', $edit, 'sequence + names = ready to start');
        $emp->get('/draw/' . $pid);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($edit), 'no raw keys');

        $lock = $emp->post('/draw/' . $pid . '/lock', ['law_ack' => '1']);
        self::assertSame(303, $lock->status);
        self::assertStringEndsWith('/draw/' . $pid . '/show', (string) ($lock->headers['Location'] ?? ''), 'start opens the draw screen');
        $prize = $db->one('SELECT * FROM draw_prizes WHERE event_id = ? AND retired = 0', [$ev['id']]);
        self::assertSame(7, (int) $prize['quantity'], 'more places than names is fine when names stay in the pool');
        $show = $emp->get('/draw/' . $pid . '/show')->body;
        self::assertStringContainsString('data-names="', $show, 'the wheel opens with the names on it');
        self::assertStringNotContainsString('🎁', $show);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($show), 'no raw keys');

        $seqs = [];
        $names = [];
        foreach ([['3', 'a'], ['3', 'b'], ['1', 'c']] as [$n, $c]) {
            $j = json_decode($emp->post('/draw/' . $pid . '/round', ['prize' => (string) $prize['id'], 'count' => $n, 'token' => str_repeat($c, 32)])->body, true);
            $round = array_column($j['winners'], 'name');
            self::assertSame(count($round), count(array_unique($round)), 'never twice in one draw');
            $names = array_merge($names, $round);
            $seqs = array_merge($seqs, array_column($j['winners'], 'seq'));
        }
        self::assertSame([1, 2, 3, 4, 5, 6, 7], $seqs);
        self::assertLessThan(7, count(array_unique($names)), 'names came up again');
        self::assertSame(422, $emp->post('/draw/' . $pid . '/round', ['prize' => (string) $prize['id'], 'count' => '1', 'token' => str_repeat('d', 32)])->status, 'all places drawn');

        // a redraw never gives the place back to the person it replaces
        $w = $db->one('SELECT * FROM draw_winners WHERE event_id = ? AND seq_no = 5', [$ev['id']]);
        $emp->get('/draw/' . $pid);
        $emp->post('/draw/' . $pid . '/redraw', ['prize' => (string) $prize['id'], 'winner' => (string) $w['id'], 'reason' => 'ไม่อยู่', 'token' => str_repeat('e', 32)]);
        $new = $db->one("SELECT * FROM draw_winners WHERE event_id = ? AND seq_no = 5 AND status = 'active'", [$ev['id']]);
        self::assertNotSame((int) $w['participant_id'], (int) $new['participant_id']);

        $emp->get('/draw/' . $pid . '/show');
        $conf = $emp->post('/draw/' . $pid . '/confirm', ['witness' => '']);
        self::assertStringEndsWith('/draw/' . $pid . '/results', (string) ($conf->headers['Location'] ?? ''), 'confirm opens the results');
        $v = \Blue\Modules\Draw\DrawService::of($this->t->app)->verify($db->one('SELECT * FROM draw_events WHERE id = ?', [$ev['id']]));
        self::assertTrue($v['ok'], 'verification replays the keep-in-pool rule and the redraw exclusion');
        self::assertCount(4, $v['rounds']);
    }

    public function testQuickDrawOpensOnTheWheel(): void
    {
        $emp = $this->browser('employee.demo1');
        $page = $emp->get('/draw/quick')->body;
        foreach (['data-quick-draw', 'data-qd-canvas', 'data-qd-dialog', 'data-qd-mode="race"', 'data-qd-remove', 'ui/js/draw.js'] as $needle) {
            self::assertStringContainsString($needle, $page, $needle);
        }
        self::assertStringNotContainsString('🎁', $page);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page), 'no raw keys');
        self::assertSame(200, $emp->post('/draw/quick', ['names' => "x\ny", 'count' => '1'])->status, 'still works without JavaScript');
    }

    /** 0.11.2 (DEC-74): the lucky draw is the quick-draw screen; every spin is drawn on the server, saved and verifiable. */
    public function testSavedDrawWorksLikeTheQuickDraw(): void
    {
        $db = $this->t->app->db();
        $emp = $this->browser('employee.demo1');
        $page = $emp->get('/draw')->body;
        foreach (['data-quick-draw', 'data-endpoint="/draw/spin"', 'name="title"', 'name="prize"', 'data-qd-canvas'] as $needle) {
            self::assertStringContainsString($needle, $page, $needle);
        }
        self::assertStringNotContainsString('🎁', $page);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($page), 'no raw keys');

        $spin = fn (array $in) => $emp->post('/draw/spin', $in + ['mode' => 'wheel', 'take_out' => '1']);
        $r1 = $spin(['names' => "เอ\nบี\nซี\nดี\nอี\nบี", 'count' => '2', 'prize' => 'ร่ม', 'token' => str_repeat('1', 32)]);
        self::assertSame(200, $r1->status, $r1->body);
        $j1 = json_decode($r1->body, true);
        $pid = (string) $j1['event'];
        self::assertSame([1, 2], array_column($j1['winners'], 'seq'));
        self::assertSame('ร่ม', $j1['prize']);
        self::assertSame(5, $j1['candidates'], 'repeat dropped');
        $ev = $db->one('SELECT * FROM draw_events WHERE public_id = ?', [$pid]);
        self::assertSame(['simple', 'drawing'], [$ev['style'], $ev['status']]);
        self::assertSame(64, strlen((string) $ev['commitment']), 'commitment recorded with the first spin');
        self::assertSame($j1['winners'], json_decode($spin(['names' => "เอ\nบี", 'count' => '1', 'token' => str_repeat('1', 32), 'event' => $pid])->body, true)['winners'], 'same token = same spin');
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM draw_rounds WHERE event_id = ?', [$ev['id']]));

        // the winners were taken out on screen: the next spin draws from the rest, with the default prize name
        $rest = array_values(array_diff(['เอ', 'บี', 'ซี', 'ดี', 'อี'], array_column($j1['winners'], 'name')));
        $j2 = json_decode($spin(['names' => implode("\n", $rest), 'count' => '1', 'prize' => '', 'token' => str_repeat('2', 32), 'event' => $pid])->body, true);
        self::assertSame([3], array_column($j2['winners'], 'seq'));
        self::assertContains($j2['winners'][0]['name'], $rest);
        self::assertSame('ผู้โชคดี', $j2['prize']);
        self::assertSame(422, $spin(['names' => 'เอ', 'count' => '2', 'token' => str_repeat('3', 32), 'event' => $pid])->status, 'more winners than names');
        self::assertSame(422, $spin(['names' => '', 'count' => '1', 'token' => str_repeat('4', 32), 'event' => $pid])->status);
        $other = $this->browser('manager.demo1');
        $other->get('/draw');
        self::assertSame(404, $other->post('/draw/spin', ['names' => 'x', 'count' => '1', 'token' => str_repeat('5', 32), 'event' => $pid])->status, 'someone else\'s draw');

        // opening it again continues where it was: last names on screen, saved results listed
        $again = $emp->get('/draw/' . $pid)->body;
        self::assertStringContainsString('data-event="' . $pid . '"', $again);
        self::assertStringContainsString('ลำดับที่ 3', $again);
        $left = array_values(array_diff($rest, [$j2['winners'][0]['name']]));
        self::assertStringContainsString(implode("\n", $left), html_entity_decode($again), 'the last list is back in the box, last winner taken out');

        $conf = $emp->post('/draw/' . $pid . '/confirm', ['name' => 'งานเลี้ยงปีใหม่ (ทดสอบ)', 'witness' => '']);
        self::assertStringEndsWith('/draw/' . $pid . '/results', (string) ($conf->headers['Location'] ?? ''));
        $ev = $db->one('SELECT * FROM draw_events WHERE public_id = ?', [$pid]);
        self::assertSame(['งานเลี้ยงปีใหม่ (ทดสอบ)', 'confirmed'], [$ev['name'], $ev['status']]);
        $v = \Blue\Modules\Draw\DrawService::of($this->t->app)->verify($ev);
        self::assertTrue($v['ok'], 'every spin replays from the revealed seed');
        self::assertCount(2, $v['rounds']);
        self::assertSame(409, $spin(['names' => 'เอ', 'count' => '1', 'token' => str_repeat('6', 32), 'event' => $pid])->status, 'no spins after finishing');
        $res = $emp->get('/draw/' . $pid . '/results');
        self::assertSame(200, $res->status);
        self::assertStringContainsString('ร่ม', $res->body);
        self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags($res->body), 'no raw keys');
        self::assertSame(303, $emp->get('/draw/' . $pid)->status, 'a finished draw opens its results');

        // tampering with a saved spin is caught
        $db->run('UPDATE draw_rounds SET candidate_list = ? WHERE event_id = ? AND round_no = 2', ['[1]', $ev['id']]);
        self::assertFalse(\Blue\Modules\Draw\DrawService::of($this->t->app)->verify($ev)['ok']);
    }
}
