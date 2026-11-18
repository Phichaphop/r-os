<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Modules\Forum\ForumService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9D-2 (DEC-98): M54 discussion board. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). Demo: DEMO1 categories
 * "ทั่วไป (DEMO)", "ถาม-ตอบการทำงาน (DEMO)" and one thread by employee.demo1 with a reply by manager.demo1. Moderators:
 * hr.demo1 (HRA, an employee) and ga.demo1 (WPA, no employee record).
 */
final class ForumTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(forum|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function fm(): ForumService
    {
        return ForumService::of($this->t->app);
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
        return (string) $this->t->app->db()->scalar('SELECT public_id FROM fm_categories WHERE legal_entity_id = ? AND name LIKE ?', [$this->le(), $like . '%']);
    }

    private function postPid(string $threadPid, int $n = 0): string
    {
        return (string) $this->t->app->db()->scalar('SELECT p.public_id FROM fm_posts p JOIN fm_threads t ON t.id = p.thread_id WHERE t.public_id = ? ORDER BY p.id LIMIT 1 OFFSET ' . $n, [$threadPid]);
    }

    private function notes(string $user, string $type): int
    {
        return (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = ?', [$this->t->user($user)['id'], $type]);
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

    public function testThreadsRepliesLikesMentionsAndLimits(): void
    {
        $f = $this->fm();
        $db = $this->t->app->db();
        $emp = $this->az('employee.demo1');
        $mgr = $this->az('manager.demo1');
        $head = $this->az('hr.head1');
        // G9D2-01: validation · ga.demo1 (no employee) cannot post · files: at most 3, images/PDF
        $e = $this->errors(fn () => $f->create($emp, ['title' => 'x', 'body' => '', 'category' => '']));
        self::assertSame(['forum.error.title', 'forum.error.body', 'forum.error.choice'], [$e['title'], $e['body'], $e['category']]);
        self::assertSame('forum.error.members_only', $this->refused(fn () => $f->create($this->az('ga.demo1'), ['title' => 'ประกาศจากผู้ดูแล', 'body' => 'ทดสอบ', 'category' => $this->cat('ทั่วไป')])));
        $png = fn (): UploadedFile => UploadedFile::fromBytes('p.png', (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/alpha.png'), $this->t->tmp);
        self::assertSame('forum.error.too_many_files', $this->errors(fn () => $f->create($emp, ['title' => 'รูปเยอะเกินไป', 'body' => 'ทดสอบ', 'category' => $this->cat('ทั่วไป')], [$png(), $png(), $png(), $png()]))['files']);
        $t1 = $f->create($emp, ['title' => 'จัดทีมวิ่งมินิมาราธอน', 'body' => "ใครสนใจบ้าง @manager.demo1 @nobody.here\n\nลงชื่อในกระทู้นี้ได้เลย https://example.com/run", 'category' => $this->cat('ทั่วไป')], [$png()]);
        self::assertSame(1, $this->notes('manager.demo1', 'forum.mention'), 'mention → manager.demo1 only');
        $th = $f->thread($mgr, $t1);
        self::assertCount(1, $th['files']);
        self::assertStringContainsString('<a href="https://example.com/run" rel="noopener noreferrer" target="_blank">', ForumService::render((string) $th['body']));
        self::assertSame("<p>&lt;b&gt;x&lt;/b&gt;<br>\ny</p>", ForumService::render("<b>x</b>\ny"));
        // G9D2-02: replies notify the author and earlier repliers (not the writer), at most once an hour per thread
        $f->reply($mgr, $t1, 'สนใจครับ');
        $f->reply($head, $t1, 'สนใจด้วยค่ะ');
        $f->reply($mgr, $t1, 'ใส่ชื่อทีมไหนดี');
        self::assertSame(1, $this->notes('employee.demo1', 'forum.reply'), 'the author: once in the hour');
        self::assertSame(1, $this->notes('manager.demo1', 'forum.reply'), 'manager: hr.head1 replied after him');
        self::assertSame(1, $this->notes('hr.head1', 'forum.reply'));
        $this->t->clock->advance('+61 minutes');
        $f->reply($head, $t1, 'ชั่วโมงถัดไป');
        self::assertSame(2, $this->notes('employee.demo1', 'forum.reply'));
        self::assertSame(4, (int) $db->scalar('SELECT reply_count FROM fm_threads WHERE public_id = ?', [$t1]));
        // G9D2-03: likes — not my own, once each, toggles; popular order
        self::assertSame('forum.error.cannot_like', $this->refused(fn () => $f->like($emp, 'thread', $t1)));
        self::assertTrue($f->like($mgr, 'thread', $t1));
        self::assertTrue($f->like($head, 'thread', $t1));
        self::assertFalse($f->like($head, 'thread', $t1));
        self::assertTrue($f->like($emp, 'post', $this->postPid($t1)));
        self::assertSame(1, $f->thread($emp, $t1)['likes']);
        $popular = $f->threads($emp, $this->le(), ['sort' => 'popular']);
        self::assertSame($t1, $popular[0]['public_id']);
        self::assertCount(2, $f->threads($emp, $this->le(), ['sort' => 'week']), 'the demo thread and the new one are both this week');
        // G9D2-04: the author edits — earlier text kept; others cannot edit; removing a reply leaves a placeholder
        self::assertSame('forum.error.cannot_edit', $this->refused(fn () => $f->edit($mgr, 'thread', $t1, ['title' => 'แก้ของคนอื่น', 'body' => 'ไม่ได้'])));
        $f->edit($emp, 'thread', $t1, ['title' => 'จัดทีมวิ่งมินิมาราธอน 10 กม.', 'body' => 'ลงชื่อในกระทู้นี้ได้เลย']);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM fm_edits WHERE target = 'thread'"));
        $f->remove($mgr, $this->postPid($t1, 2));
        $posts = $f->thread($emp, $t1)['posts'];
        self::assertSame(['', 'author'], [$posts[2]['body'], $posts[2]['hidden_reason']]);
        self::assertSame('ใส่ชื่อทีมไหนดี', $f->thread($this->az('hr.demo1'), $t1)['posts'][2]['body'], 'moderators still see it');
        // G9D2-05: rate limit — 5 threads an hour (the first thread was over an hour ago)
        for ($k = 0; $k < 5; $k++) {
            $f->create($emp, ['title' => 'กระทู้ทดสอบลำดับ ' . $k, 'body' => 'ทดสอบ', 'category' => $this->cat('ถาม-ตอบ')]);
        }
        self::assertSame('forum.error.slow_down', $this->refused(fn () => $f->create($emp, ['title' => 'กระทู้ที่หก', 'body' => 'ทดสอบ', 'category' => $this->cat('ถาม-ตอบ')])));
        // search, category, mine
        self::assertCount(1, $f->threads($mgr, $this->le(), ['q' => 'มาราธอน']));
        self::assertCount(5, $f->threads($mgr, $this->le(), ['category' => $this->cat('ถาม-ตอบ')]));
        self::assertCount(7, $f->threads($emp, $this->le(), ['mine' => '1']), 'demo thread + 6');
        $this->t->clock->advance('+8 days');
        self::assertSame([], $f->threads($emp, $this->le(), ['sort' => 'week']), 'nothing started in the last 7 days');
        // another company / no employee cannot read
        self::assertSame('http403', $this->refused(fn () => $f->threads($this->az('auditor.demo'), $this->le())));
        self::assertSame('http404', $this->refused(fn () => $f->thread($this->az('auditor.demo'), $t1)));
    }

    public function testReportsModerationAndScreens(): void
    {
        $f = $this->fm();
        $db = $this->t->app->db();
        $emp = $this->az('employee.demo1');
        $mgr = $this->az('manager.demo1');
        $mod = $this->az('hr.demo1');
        $ga = $this->az('ga.demo1');
        $t1 = $f->create($mgr, ['title' => 'ขายของส่วนตัวราคาพิเศษ', 'body' => 'ทักมาได้', 'category' => $this->cat('ทั่วไป')]);
        $f->reply($emp, $t1, 'ข้อความไม่สุภาพ');
        $p1 = $this->postPid($t1);
        // G9D2-06: report — reason, once, not my own; moderators notified
        self::assertSame('forum.error.reason', $this->errors(fn () => $f->report($emp, 'thread', $t1, 'สั้น'))['reason']);
        self::assertSame('forum.error.own_report', $this->refused(fn () => $f->report($mgr, 'thread', $t1, 'รายงานตัวเอง')));
        $f->report($emp, 'thread', $t1, 'ขายของในกระดานบริษัท');
        self::assertSame('forum.error.already_reported', $this->refused(fn () => $f->report($emp, 'thread', $t1, 'รายงานซ้ำอีกครั้ง')));
        $f->report($this->az('hr.head1'), 'thread', $t1, 'ผิดวัตถุประสงค์ของกระดาน');
        $f->report($mgr, 'post', $p1, 'ใช้คำไม่สุภาพ');
        self::assertGreaterThanOrEqual(1, $this->notes('ga.demo1', 'forum.report'));
        self::assertSame('http403', $this->refused(fn () => $f->reports($emp, $this->le())));
        $q = $f->reports($ga, $this->le());
        self::assertCount(3, $q['open']);
        // G9D2-07: uphold hides with the note (all open reports on it closed, author told) · dismiss
        $threadReport = (string) $db->scalar("SELECT public_id FROM fm_reports WHERE target = 'thread' ORDER BY id LIMIT 1");
        self::assertSame('forum.error.reason', $this->errors(fn () => $f->handle($ga, $threadReport, true, ''))['note']);
        $f->handle($ga, $threadReport, true, 'ผิดกติกาการใช้กระดาน ห้ามขายของ');
        self::assertSame(['upheld', 'upheld'], array_column($db->all("SELECT status FROM fm_reports WHERE target = 'thread'"), 'status'));
        self::assertSame(1, (int) $db->scalar('SELECT hidden FROM fm_threads WHERE public_id = ?', [$t1]));
        self::assertSame(1, $this->notes('manager.demo1', 'forum.moderation'));
        $postReport = (string) $db->scalar("SELECT public_id FROM fm_reports WHERE target = 'post'");
        $f->handle($mod, $postReport, false, 'ยังไม่ถึงขั้นซ่อน ตักเตือนแล้ว');
        self::assertSame('forum.error.handled', $this->refused(fn () => $f->handle($mod, $postReport, true, 'เปลี่ยนใจภายหลัง')));
        // hidden thread: the author and moderators see it; colleagues do not; nobody can reply
        self::assertSame('http404', $this->refused(fn () => $f->thread($emp, $t1)));
        self::assertTrue((bool) $f->thread($mgr, $t1)['mine']);
        self::assertFalse($f->thread($mgr, $t1)['can_reply']);
        self::assertCount(1, array_filter($f->threads($ga, $this->le()), static fn (array $r): bool => $r['public_id'] === $t1));
        self::assertCount(0, array_filter($f->threads($emp, $this->le()), static fn (array $r): bool => $r['public_id'] === $t1));
        // G9D2-08: lock (reason) stops replies and edits; pin; unhide; not for employees
        $t2 = (string) $db->scalar('SELECT public_id FROM fm_threads WHERE title LIKE ?', ['แนะนำร้าน%']);
        self::assertSame('http403', $this->refused(fn () => $f->moderate($emp, 'thread', $t2, 'pin', '')));
        self::assertSame('forum.error.reason', $this->errors(fn () => $f->moderate($mod, 'thread', $t2, 'lock', ''))['reason']);
        $f->moderate($mod, 'thread', $t2, 'lock', 'ได้ข้อสรุปแล้ว ปิดกระทู้');
        self::assertSame('forum.error.locked', $this->refused(fn () => $f->reply($mgr, $t2, 'ตอบหลังล็อก')));
        self::assertSame('forum.error.cannot_edit', $this->refused(fn () => $f->edit($emp, 'thread', $t2, ['title' => 'แก้หลังล็อกไม่ได้', 'body' => 'x1'])));
        $f->moderate($mod, 'thread', $t2, 'pin', '');
        self::assertSame($t2, $f->threads($emp, $this->le())[0]['public_id'], 'pinned first');
        $f->moderate($ga, 'thread', $t1, 'unhide', '');
        self::assertCount(1, array_filter($f->threads($emp, $this->le()), static fn (array $r): bool => $r['public_id'] === $t1));
        self::assertGreaterThanOrEqual(5, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'forum.%'"));
        // categories (moderators only)
        self::assertSame('forum.error.name_taken', $this->errors(fn () => $f->saveCategory($mod, $this->le(), ['name' => 'ทั่วไป (DEMO)']))['name']);
        self::assertSame('http403', $this->refused(fn () => $f->saveCategory($emp, $this->le(), ['name' => 'กีฬา'])));
        // screens
        $g = $this->t->browser();
        $g->login('employee.demo1');
        foreach ([['/forum', []], ['/forum', ['sort' => 'week', 'q' => 'ร้าน']], ['/forum/new', []], ['/forum/t/' . $t1, []], ['/forum/t/' . $t2, []]] as [$path, $query]) {
            $res = $g->get($path, $query);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        $made = $g->post('/forum/new', ['category' => $this->cat('ทั่วไป'), 'title' => 'ชวนเล่นแบดหลังเลิกงาน', 'body' => 'วันพุธ 18:00'], ['files' => [['b.png', (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/alpha.png')]]]);
        self::assertSame(303, $made->status);
        $t3 = (string) $db->scalar('SELECT public_id FROM fm_threads WHERE title = ?', ['ชวนเล่นแบดหลังเลิกงาน']);
        $fid = (int) $db->scalar('SELECT f.id FROM fm_files f JOIN fm_threads t ON t.id = f.thread_id WHERE t.public_id = ?', [$t3]);
        self::assertSame(200, $g->get('/forum/t/' . $t3 . '/f/' . $fid)->status);
        self::assertSame(422, $g->post('/forum/t/' . $t3 . '/reply', ['body' => ''])->status);
        self::assertSame(303, $g->post('/forum/t/' . $t3 . '/reply', ['body' => 'ใครว่างบ้าง'])->status);
        self::assertSame(403, $g->get('/forum/moderate')->status);
        $m = $this->t->browser();
        $m->login('hr.demo1');
        foreach (['/forum/moderate', '/forum/t/' . $t1] as $path) {
            $res = $m->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertSame(303, $m->post('/forum/t/' . $t3 . '/hide', ['reason' => 'ทดสอบการซ่อนผ่านหน้าจอ'])->status);
        self::assertSame(422, $m->post('/forum/t/' . $t3 . '/lock', ['reason' => ''])->status);
    }
}
