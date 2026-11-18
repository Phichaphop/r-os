<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Modules\Kb\KbService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9D-1 (DEC-97): M55 Q&A / knowledge base. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). Demo: DEMO1 categories
 * "การใช้ระบบ (DEMO)" and "สำนักงาน (DEMO)", two published answers by hr.demo1 reviewed by hr.head1 (review in a year),
 * synonym group "รหัสผ่าน, password, พาสเวิร์ด". hr.demo1 / hr.head1 (HRA) write and review; ga.demo1 (WPA) too.
 */
final class KnowledgeBaseTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(kb|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function kb(): KbService
    {
        return KbService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function cat(string $like): string
    {
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM kb_categories WHERE legal_entity_id = ? AND name_th LIKE ?', [$this->le(), $like . '%']);
    }

    /** @return array<string, mixed> */
    private function art(string $pid): array
    {
        return $this->t->app->db()->one('SELECT * FROM kb_articles WHERE public_id = ?', [$pid]) ?? [];
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

    public function testRevisionsReviewSearchFeedbackAndQuestions(): void
    {
        $k = $this->kb();
        $db = $this->t->app->db();
        $hr = $this->az('hr.demo1');
        $head = $this->az('hr.head1');
        $emp = $this->az('employee.demo1');
        // G9D1-13: search uses synonyms — "password" finds the Thai answer; nothing for junk; category filter
        self::assertCount(1, $k->search($this->le(), 'password'));
        self::assertSame([], $k->search($this->le(), 'zzzz-nothing'));
        self::assertCount(1, $k->search($this->le(), '', $this->cat('สำนักงาน')));
        self::assertSame(['password', 'รหัสผ่าน', 'พาสเวิร์ด'], $k->expand($this->le(), 'Password'));
        // G9D1-14: write → validation; employees cannot write; draft is not visible to readers
        $e = $this->errors(fn () => $k->create($hr, $this->le(), ['category' => '', 'question_th' => 'สั้น', 'answer_th' => 'สั้น', 'question_en' => 'Only question']));
        self::assertSame(['kb.error.choice', 'kb.error.question', 'kb.error.answer', 'kb.error.english_pair'], [$e['category'], $e['question_th'], $e['answer_th'], $e['question_en']]);
        self::assertSame('http403', $this->refused(fn () => $k->create($emp, $this->le(), [])));
        $pid = $k->create($hr, $this->le(), ['category' => $this->cat('การใช้ระบบ'), 'question_th' => 'เปลี่ยนภาษาหน้าจออย่างไร', 'answer_th' => "กดปุ่ม EN มุมขวาบน\n\n- ไทย ↔ อังกฤษ\n- จำไว้ในเบราว์เซอร์", 'tags' => 'ภาษา, English, ภาษา']);
        self::assertSame('ภาษา, english', (string) $db->scalar('SELECT tags FROM kb_revisions r JOIN kb_articles a ON a.id = r.article_id WHERE a.public_id = ?', [$pid]));
        self::assertSame('http404', $this->refused(fn () => $k->read($emp, $pid)));
        // G9D1-15: send needs a future review date; the author cannot approve; another reviewer publishes
        self::assertSame('kb.error.review_due', $this->errors(fn () => $k->submit($hr, $pid, '2026-11-09'))['review_due']);
        self::assertSame('kb.error.review_due', $this->errors(fn () => $k->submit($hr, $pid, '2030-01-01'))['review_due']);
        $k->submit($hr, $pid, '2027-05-01');
        self::assertSame('kb.error.in_review', $this->refused(fn () => $k->edit($hr, $pid, ['category' => $this->cat('การใช้ระบบ'), 'question_th' => 'เปลี่ยนภาษาหน้าจออย่างไร', 'answer_th' => 'แก้ระหว่างตรวจไม่ได้นะ'])));
        self::assertSame('kb.error.own_revision', $this->refused(fn () => $k->decide($hr, $pid, true, '')));
        self::assertSame('http403', $this->refused(fn () => $k->decide($emp, $pid, true, '')));
        self::assertSame('kb.error.reason', $this->errors(fn () => $k->decide($head, $pid, false, ''))['note']);
        $k->decide($head, $pid, false, 'เพิ่มภาพประกอบตำแหน่งปุ่ม');
        self::assertSame('returned', (string) $db->scalar('SELECT r.status FROM kb_revisions r JOIN kb_articles a ON a.id = r.article_id WHERE a.public_id = ?', [$pid]));
        $k->submit($hr, $pid, '2027-05-01');
        $k->decide($head, $pid, true, 'ถูกต้อง');
        self::assertSame(['published', '2027-05-01'], [$this->art($pid)['status'], (string) $this->art($pid)['review_due']]);
        // the reader sees it, rendered safely (list, no raw HTML)
        $read = $k->read($emp, $pid);
        self::assertSame(1, (int) $read['rev']['version']);
        self::assertStringContainsString('<ul><li>ไทย ↔ อังกฤษ</li>', KbService::render((string) $read['rev']['answer_th']));
        self::assertSame('<p>&lt;script&gt;x&lt;/script&gt; <a href="https://example.com/a?b=1&amp;c=2" rel="noopener noreferrer" target="_blank">https://example.com/a?b=1&amp;c=2</a>.</p>', KbService::render('<script>x</script> https://example.com/a?b=1&c=2.'));
        // one view per person per day
        $k->read($emp, $pid);
        self::assertSame(1, (int) $this->art($pid)['views']);
        $this->t->clock->advance('+1 day');
        $k->read($emp, $pid);
        $k->read($this->az('manager.demo1'), $pid);
        self::assertSame(3, (int) $this->art($pid)['views']);
        // G9D1-16: a new version keeps the published one until approved; needs a reason
        self::assertSame('kb.error.change_reason', $this->errors(fn () => $k->edit($hr, $pid, ['category' => $this->cat('การใช้ระบบ'), 'question_th' => 'เปลี่ยนภาษาหน้าจออย่างไร', 'answer_th' => 'กดปุ่ม EN หรือ ไทย มุมขวาบน']))['change_reason']);
        $k->edit($hr, $pid, ['category' => $this->cat('การใช้ระบบ'), 'question_th' => 'เปลี่ยนภาษาหน้าจออย่างไร', 'answer_th' => 'กดปุ่ม EN หรือ ไทย มุมขวาบน', 'change_reason' => 'ปุ่มเปลี่ยนชื่อ', 'question_en' => 'How do I change the language?', 'answer_en' => 'Press EN / ไทย at the top right.']);
        self::assertSame(1, (int) $k->read($emp, $pid)['rev']['version'], 'readers still see v1');
        $k->submit($hr, $pid, '2027-06-01');
        $k->decide($this->az('ga.demo1'), $pid, true, '');
        $m = $k->manage($hr, $pid);
        self::assertSame(['published', 'superseded'], array_column($m['revisions'], 'status'));
        self::assertSame(2, (int) $k->read($emp, $pid)['rev']['version']);
        self::assertCount(1, $k->search($this->le(), 'language'), 'English text is searched too');
        // G9D1-17: feedback — one per person, changeable
        $k->feedback($emp, $pid, false, 'ไม่เห็นปุ่ม');
        $k->feedback($emp, $pid, true, 'ไม่ควรเก็บ');
        $fb = $db->all('SELECT helpful, comment FROM kb_feedback f JOIN kb_articles a ON a.id = f.article_id WHERE a.public_id = ?', [$pid]);
        self::assertSame([['helpful' => 1, 'comment' => null]], array_map(static fn (array $r): array => ['helpful' => (int) $r['helpful'], 'comment' => $r['comment']], $fb));
        // G9D1-18: questions — ask, answer by an article (asker told on publish), decline with a reply
        self::assertSame('kb.error.question_text', $this->errors(fn () => $k->ask($emp, ['text' => 'สั้น']))['text']);
        $q1 = $k->ask($emp, ['text' => 'ขอใบรับรองเงินเดือนได้ที่ไหน', 'category' => $this->cat('สำนักงาน')]);
        $q2 = $k->ask($emp, ['text' => 'ทำไมแอร์ชั้น 3 ไม่เย็น']);
        self::assertCount(2, $k->questions($hr, $this->le()));
        self::assertSame('http403', $this->refused(fn () => $k->questions($emp, $this->le())));
        $a2 = $k->create($hr, $this->le(), ['category' => $this->cat('สำนักงาน'), 'question_th' => 'ขอใบรับรองเงินเดือนได้ที่ไหน', 'answer_th' => 'เมนูหนังสือรับรอง เลือกประเภทแล้วส่งคำขอ'], $q1);
        self::assertSame('open', (string) $db->scalar('SELECT status FROM kb_questions WHERE public_id = ?', [$q1]));
        $k->submit($hr, $a2, '2027-01-31');
        $k->decide($head, $a2, true, '');
        self::assertSame('answered', (string) $db->scalar('SELECT status FROM kb_questions WHERE public_id = ?', [$q1]));
        self::assertSame('kb.error.reply', $this->errors(fn () => $k->decline($hr, $q2, ''))['reply']);
        $k->decline($hr, $q2, 'แจ้งผ่านระบบแจ้งซ่อม');
        $mine = array_column($k->myQuestions($emp), 'status', 'public_id');
        self::assertSame(['answered', 'declined'], [$mine[$q1], $mine[$q2]]);
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'kb.question'", [$this->t->user('employee.demo1')['id']]));
        // G9D1-19: withdraw (reviewer, reason) → readers told, hidden from readers, revisions kept
        self::assertSame('http403', $this->refused(fn () => $k->withdraw($this->az('employee.demo1'), $pid, 'ไม่ใช้แล้ว', true)));
        self::assertSame(2, $k->withdraw($head, $pid, 'หน้าจอเปลี่ยนแล้ว เขียนใหม่', true));
        self::assertSame('http404', $this->refused(fn () => $k->read($emp, $pid)));
        self::assertSame(['withdrawn', 'superseded'], array_column($k->manage($hr, $pid)['revisions'], 'status'));
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'kb.withdrawn'"));
        // G9D1-20: past the review date → the owner is reminded once, readers see it is under review
        $this->t->clock->advance('+90 days');
        $k->tick();
        $k->tick();
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE dedupe_key LIKE ?", ['kb.due:' . $a2 . ':%']));
        self::assertTrue($k->read($emp, $a2)['review_overdue']);
    }

    public function testScreensSettingsAndHelpdeskSuggestions(): void
    {
        $k = $this->kb();
        $hr = $this->az('hr.demo1');
        $pid = (string) $this->t->app->db()->scalar('SELECT public_id FROM kb_articles ORDER BY id LIMIT 1');
        // categories and synonyms (reviewers only)
        self::assertSame('kb.error.name_taken', $this->errors(fn () => $k->saveCategory($hr, $this->le(), ['name_th' => 'การใช้ระบบ (DEMO)']))['name_th']);
        self::assertSame('http403', $this->refused(fn () => $k->saveSynonyms($this->az('employee.demo1'), $this->le(), 'a, b')));
        $k->saveSynonyms($hr, $this->le(), "ลา, หยุดงาน, Leave\nคำเดียว\n");
        self::assertSame('ลา, หยุดงาน, leave', $k->synonyms($this->le()));
        $g = $this->t->browser();
        $g->login('hr.demo1');
        $draft = $k->create($hr, $this->le(), ['category' => $this->cat('สำนักงาน'), 'question_th' => 'ปริ้นเตอร์ชั้น 2 ใช้ยังไง', 'answer_th' => 'เลือกเครื่องพิมพ์ PRN-2F จากรายการ']);
        $q = $k->ask($this->az('employee.demo1'), ['text' => 'ขอรหัส Wi-Fi สำหรับแขกได้ที่ไหน']);
        foreach ([['/kb', []], ['/kb', ['q' => 'password']], ['/kb/a/' . $pid, []], ['/kb/manage', []], ['/kb/manage', ['view' => 'questions']], ['/kb/manage/new', []], ['/kb/manage/new', ['question' => $q]], ['/kb/manage/a/' . $draft, []], ['/kb/manage/a/' . $pid, []], ['/kb/settings', []]] as [$path, $query]) {
            $res = $g->get($path, $query);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertSame(303, $g->post('/kb/manage/a/' . $draft . '/attach', [], ['file' => ['howto.pdf', (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/a.pdf')]])->status);
        self::assertSame(422, $g->post('/kb/manage/a/' . $draft . '/submit', ['review_due' => ''])->status);
        self::assertSame(303, $g->post('/kb/settings/category', ['name_th' => 'ไอที', 'name_en' => 'IT', 'sort' => '3'])->status);
        $fid = (int) $this->t->app->db()->scalar('SELECT f.id FROM kb_files f JOIN kb_articles a ON a.id = f.article_id WHERE a.public_id = ?', [$draft]);
        self::assertSame(200, $g->get('/kb/a/' . $draft . '/f/' . $fid)->status, 'authors open files of drafts');
        // an employee: read, feedback, ask, suggestions on the help desk form; no authoring
        $e1 = $this->t->browser();
        $e1->login('employee.demo1');
        self::assertSame(200, $e1->get('/kb/a/' . $pid)->status);
        self::assertSame(404, $e1->get('/kb/a/' . $draft)->status);
        self::assertSame(404, $e1->get('/kb/a/' . $draft . '/f/' . $fid)->status);
        self::assertSame(303, $e1->post('/kb/a/' . $pid . '/feedback', ['helpful' => '0', 'comment' => 'อยากได้รูปประกอบ'])->status);
        self::assertSame(303, $e1->post('/kb/ask', ['text' => 'ลืมบัตรพนักงานต้องทำอย่างไร'])->status);
        self::assertSame(422, $e1->post('/kb/ask', ['text' => 'สั้น'])->status);
        $s = $e1->get('/kb/suggest', ['q' => 'ลืมรหัสผ่าน']);
        self::assertSame(200, $s->status);
        self::assertStringContainsString('/kb/a/', $s->body);
        self::assertStringNotContainsString('<html', $s->body);
        self::assertStringNotContainsString('/kb/a/', $e1->get('/kb/suggest', ['q' => 'xy'])->body);
        self::assertStringContainsString('data-kb-suggest=', $e1->get('/helpdesk/new')->body);
        self::assertSame(403, $e1->get('/kb/manage')->status);
        self::assertSame(403, $e1->get('/kb/settings')->status);
    }
}
