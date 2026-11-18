<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use Blue\Modules\Chat\ChatService;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;
use PHPUnit\Framework\TestCase;

/**
 * 0.41.0 (R5): M17 chat with the owner's answers Q-01 – Q-06 — polling endpoint for an open conversation only, one-to-one
 * and user-made groups among colleagues of the same company, up to three pictures / PDF per message, retention (default
 * three years), nobody reads chats except one reported message, edit / delete within 15 minutes with the original kept.
 */
final class ChatWebTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function as(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    private function uid(string $u): int
    {
        return (int) $this->t->user($u)['id'];
    }

    private static function pdf(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/a.pdf');
    }

    public function testDirectChatSendPollReadEditDeleteFilesAndPrivacy(): void
    {
        $db = $this->t->app->db();
        $emp = $this->as('employee.demo1');
        $page = $emp->get('/chat');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('ไม่มีหน้าจอให้ผู้ดูแลอ่านแชท', $page->body);
        self::assertStringContainsString('1095 วัน', $page->body, 'retention 3 years by default (Q-04)');
        $new = $emp->get('/chat/new')->body;
        self::assertStringContainsString('หัวหน้าฝ่ายขาย (DEMO)', $new, 'colleagues of the same company');
        self::assertStringNotContainsString('HR Admin บริษัทตัวอย่างสอง', $new, 'not people of another company');

        // one-to-one: the same conversation every time
        $mgr = $this->uid('manager.demo1');
        $res = $emp->post('/chat/new', ['people' => [(string) $mgr]]);
        self::assertSame(303, $res->status, $res->body);
        $cid = basename((string) $res->headers['Location']);
        self::assertSame($cid, basename((string) $emp->post('/chat/new', ['people' => [(string) $mgr]])->headers['Location']));
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM ch_conversations'));
        $other = $this->uid('hr.demo2');
        self::assertSame(422, $emp->post('/chat/new', ['people' => [(string) $other]])->status, 'someone of another company');

        // send: text + a picture + a PDF; a retry with the same client id is stored once
        $emp->get('/chat/c/' . $cid);
        $client = str_repeat('a1', 16);
        $sent = $emp->post(
            '/chat/c/' . $cid . '/send',
            ['body' => "สวัสดีครับ\nhttps://example.com/x <b>ตัวหนา</b>", 'client_id' => $client],
            ['files' => [['p.png', base64_decode(self::PNG)], ['a.pdf', self::pdf()]]]
        );
        self::assertSame(303, $sent->status, $sent->body);
        $emp->post('/chat/c/' . $cid . '/send', ['body' => 'สวัสดีครับ', 'client_id' => $client]);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM ch_messages WHERE kind = 'text'"), 'idempotent send');
        self::assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM ch_files'));
        self::assertSame(422, $emp->post('/chat/c/' . $cid . '/send', ['body' => '', 'client_id' => str_repeat('b2', 16)])->status, 'empty');
        self::assertSame(422, $emp->post(
            '/chat/c/' . $cid . '/send',
            ['body' => 'x', 'client_id' => str_repeat('c3', 16)],
            ['files' => [['1.png', base64_decode(self::PNG)], ['2.png', base64_decode(self::PNG)], ['3.png', base64_decode(self::PNG)], ['4.png', base64_decode(self::PNG)]]]
        )->status, 'max 3 files (Q-03)');
        self::assertSame(422, $emp->post('/chat/c/' . $cid . '/send', ['body' => 'x', 'client_id' => str_repeat('d4', 16)], ['files' => [['x.exe', 'MZ fake program']]])->status, 'pictures or PDF only');
        $doc = (string) $db->scalar('SELECT document_pid FROM ch_files ORDER BY id LIMIT 1');
        self::assertSame('chat.file', $db->scalar('SELECT document_class FROM documents WHERE public_id = ?', [$doc]), 'stored through the document service (encrypted)');
        self::assertStringNotContainsString('สวัสดี', (string) $db->scalar("SELECT GROUP_CONCAT(params_json) FROM notifications"), 'notices carry no text');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'chat.message'", [$mgr]));

        // the other person: page shows the message escaped with a safe link; polling returns news and read receipts
        $m = $this->as('manager.demo1');
        $view = $m->get('/chat/c/' . $cid)->body;
        self::assertStringContainsString('&lt;b&gt;ตัวหนา&lt;/b&gt;', $view, 'no HTML from people');
        self::assertStringContainsString('<a href="https://example.com/x" rel="noopener noreferrer" target="_blank">', $view);
        self::assertMatchesRegularExpression('#<img class="msg__img" src="/chat/c/' . $cid . '/f/\d+"#', $view);
        self::assertStringContainsString('data-seconds="6"', $view, 'polls every 6 s while open (Q-01: 5–10)');
        self::assertStringContainsString('ui/js/chat.js', $view);
        $fid = (int) $db->scalar("SELECT id FROM ch_files WHERE mime = 'application/pdf'");
        $file = $m->get('/chat/c/' . $cid . '/f/' . $fid);
        self::assertSame(200, $file->status);
        self::assertStringStartsWith('%PDF', $file->body);
        $last = (int) $db->scalar('SELECT MAX(id) FROM ch_messages');
        self::assertSame($last, (int) $db->scalar('SELECT last_read_id FROM ch_members WHERE user_id = ?', [$mgr]), 'opening marks read');
        $reply = $m->post('/chat/c/' . $cid . '/send', ['body' => 'ได้รับแล้ว', 'client_id' => str_repeat('e5', 16)]);
        self::assertSame(303, $reply->status);
        $poll = $emp->get('/chat/c/' . $cid . '/poll', ['after' => (string) $last, 'since' => '2026-01-01 00:00:00']);
        self::assertSame('application/json; charset=utf-8', $poll->headers['Content-Type']);
        $j = json_decode($poll->body, true);
        self::assertCount(1, $j['messages']);
        self::assertStringContainsString('ได้รับแล้ว', $j['messages'][0]['html']);
        self::assertSame(1, $j['reads'][$last] ?? null, 'my message was read');
        self::assertSame([], json_decode($emp->get('/chat/c/' . $cid . '/poll', ['after' => (string) $j['last'], 'since' => $j['now']])->body, true)['messages'], 'nothing new');

        // outsiders: 404 for the page, the poll, the file — even an HR admin
        $hr = $this->as('hr.demo1');
        foreach (['/chat/c/' . $cid, '/chat/c/' . $cid . '/poll', '/chat/c/' . $cid . '/f/' . $fid] as $p) {
            self::assertSame(404, $hr->get($p)->status, $p . ' is private (Q-05)');
        }

        // edit / delete within 15 minutes, the earlier text kept, members never see removed text
        $mine = (string) $db->scalar("SELECT public_id FROM ch_messages WHERE sender_id = ? AND kind = 'text'", [$this->uid('employee.demo1')]);
        $emp->get('/chat/c/' . $cid);
        $emp->post('/chat/c/' . $cid . '/m/' . $mine . '/edit', ['body' => 'สวัสดีครับ (แก้)']);
        self::assertSame('สวัสดีครับ (แก้)', $db->scalar('SELECT body FROM ch_messages WHERE public_id = ?', [$mine]));
        self::assertStringContainsString('ตัวหนา', (string) $db->scalar("SELECT old_body FROM ch_message_edits WHERE action = 'edit'"), 'original kept (Q-06)');
        self::assertStringContainsString('แก้ไขแล้ว', $m->get('/chat/c/' . $cid)->body);
        $mreply = (string) $db->scalar('SELECT public_id FROM ch_messages WHERE sender_id = ?', [$mgr]);
        $emp->get('/chat/c/' . $cid);
        self::assertSame(403, $emp->post('/chat/c/' . $cid . '/m/' . $mreply . '/delete')->status, 'not someone else\'s message');
        $this->t->clock->advance('+16 minutes');
        $emp->get('/chat/c/' . $cid);
        $emp->post('/chat/c/' . $cid . '/m/' . $mine . '/delete');
        self::assertNull($db->scalar('SELECT deleted_at FROM ch_messages WHERE public_id = ?', [$mine]), 'too late after 15 minutes');
        $this->t->clock->advance('-16 minutes');
        $emp->get('/chat/c/' . $cid);
        $emp->post('/chat/c/' . $cid . '/m/' . $mine . '/delete');
        self::assertNotNull($db->scalar('SELECT deleted_at FROM ch_messages WHERE public_id = ?', [$mine]));
        self::assertNull($db->scalar('SELECT body FROM ch_messages WHERE public_id = ?', [$mine]));
        self::assertSame('สวัสดีครับ (แก้)', $db->scalar("SELECT old_body FROM ch_message_edits WHERE action = 'delete'"));
        $after = $m->get('/chat/c/' . $cid)->body;
        self::assertStringContainsString('ข้อความนี้ถูกลบโดยผู้ส่ง', $after);
        self::assertStringNotContainsString('(แก้)', $after);
        self::assertSame(404, $m->get('/chat/c/' . $cid . '/f/' . $fid)->status, 'files of a deleted message are not served');

        // search finds my own conversations only, not deleted text
        self::assertStringContainsString('ได้รับแล้ว', $emp->get('/chat', ['q' => 'ได้รับ'])->body);
        self::assertStringNotContainsString('/chat/c/' . $cid . '#', $emp->get('/chat', ['q' => 'สวัสดี'])->body);
    }

    public function testGroupsMembersReportsAndRetention(): void
    {
        $db = $this->t->app->db();
        $hr = $this->as('hr.demo1');
        $people = [(string) $this->uid('employee.demo1'), (string) $this->uid('manager.demo1')];
        self::assertSame(422, $hr->post('/chat/new', ['people' => $people, 'title' => 'x'])->status, 'name 2–80');
        $res = $hr->post('/chat/new', ['people' => $people, 'title' => 'ทีมงานปีใหม่']);
        self::assertSame(303, $res->status, $res->body);
        $gid = basename((string) $res->headers['Location']);
        $hr->get('/chat/c/' . $gid);
        $hr->post('/chat/c/' . $gid . '/send', ['body' => 'ข้อความก่อนเพิ่มสมาชิก', 'client_id' => str_repeat('f6', 16)]);

        // owner adds a colleague — the newcomer sees messages from now on only
        $hr->get('/chat/c/' . $gid);
        $hr->post('/chat/c/' . $gid . '/members/add', ['user' => (string) $this->uid('hr.head1')]);
        $head = $this->as('hr.head1');
        $hv = $head->get('/chat/c/' . $gid)->body;
        self::assertStringContainsString('ทีมงานปีใหม่', $hv);
        self::assertStringNotContainsString('ข้อความก่อนเพิ่มสมาชิก', $hv);
        self::assertStringContainsString('เพิ่ม ผู้จัดการฝ่ายบุคคล (DEMO) เข้ากลุ่ม', $hv);

        // a member cannot remove others; the owner removes one — that person loses access to everything
        $emp = $this->as('employee.demo1');
        $emp->get('/chat/c/' . $gid);
        self::assertSame(403, $emp->post('/chat/c/' . $gid . '/members/remove', ['user' => (string) $this->uid('manager.demo1')])->status);
        $emp->post('/chat/c/' . $gid . '/send', ['body' => 'คุณมันแย่มาก ข้อความไม่เหมาะสม', 'client_id' => str_repeat('a7', 16)]);
        $bad = (string) $db->scalar("SELECT public_id FROM ch_messages WHERE body LIKE 'คุณมันแย่%'");

        // report: once per person, never one's own; the moderator sees ONLY that message (Q-05)
        $mgr = $this->as('manager.demo1');
        $mgr->get('/chat/c/' . $gid);
        self::assertSame(303, $mgr->post('/chat/c/' . $gid . '/m/' . $bad . '/report', ['reason' => 'ใช้คำไม่สุภาพ'])->status);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM ch_reports'));
        $mgr->get('/chat/c/' . $gid);
        $mgr->post('/chat/c/' . $gid . '/m/' . $bad . '/report', ['reason' => 'ใช้คำไม่สุภาพ']);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM ch_reports'), 'once per person');
        $emp->get('/chat/c/' . $gid);
        $emp->post('/chat/c/' . $gid . '/m/' . $bad . '/report', ['reason' => 'รายงานตัวเอง']);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM ch_reports'), 'not one\'s own');
        self::assertStringNotContainsString('คุณมันแย่', (string) $db->scalar("SELECT GROUP_CONCAT(IFNULL(diff_json, ''), IFNULL(reason, '')) FROM audit_events WHERE action LIKE 'chat.%'"), 'no chat text in the audit');
        self::assertSame(2, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'chat.report'"), 'both DEMO1 HR admins (chat.moderate) are told — without the text');

        $mod = $this->as('hr.head1'); // HRA: chat.moderate — and also a member here, but the queue shows the one message only
        $q = $mod->get('/chat/moderate')->body;
        self::assertStringContainsString('คุณมันแย่มาก', $q);
        self::assertStringContainsString('ใช้คำไม่สุภาพ', $q);
        self::assertStringNotContainsString('ข้อความก่อนเพิ่มสมาชิก', $q, 'nothing else of the conversation');
        self::assertSame(403, $emp->get('/chat/moderate')->status);
        $rid = (string) $db->scalar('SELECT public_id FROM ch_reports');
        $mod->post('/chat/moderate/r/' . $rid . '/uphold', ['note' => 'x']);
        self::assertSame('open', $db->scalar('SELECT status FROM ch_reports'), 'a reason is required to hide');
        $mod->get('/chat/moderate');
        $mod->post('/chat/moderate/r/' . $rid . '/uphold', ['note' => 'ขัดนโยบายการใช้งาน']);
        self::assertSame('upheld', $db->scalar('SELECT status FROM ch_reports'));
        self::assertNotNull($db->scalar('SELECT hidden_at FROM ch_messages WHERE public_id = ?', [$bad]));
        self::assertStringContainsString('ข้อความนี้ถูกซ่อนโดยผู้ดูแลระบบ', $mgr->get('/chat/c/' . $gid)->body);
        self::assertStringNotContainsString('คุณมันแย่มาก', $mgr->get('/chat/c/' . $gid)->body);
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'chat.moderation' AND user_id = ?", [$this->uid('employee.demo1')]));

        // owner removes the manager → 404 afterwards; owner leaves → the next member owns the group
        $hr->get('/chat/c/' . $gid);
        $hr->post('/chat/c/' . $gid . '/members/remove', ['user' => (string) $this->uid('manager.demo1')]);
        self::assertSame(404, $mgr->get('/chat/c/' . $gid)->status, 'removed members lose access');
        $hr->get('/chat/c/' . $gid);
        $hr->post('/chat/c/' . $gid . '/members/leave');
        self::assertSame('owner', $db->scalar('SELECT m.role FROM ch_members m JOIN users u ON u.id = m.user_id WHERE u.username = ?', ['employee.demo1']));

        // retention: after chat.retention_days text, earlier versions and files go; an open report keeps its message
        $emp->get('/chat/c/' . $gid);
        $emp->post('/chat/c/' . $gid . '/send', ['body' => 'มีไฟล์', 'client_id' => str_repeat('b8', 16)], ['files' => [['p.png', base64_decode(self::PNG)]]]);
        $emp->get('/chat/c/' . $gid);
        $emp->post('/chat/c/' . $gid . '/send', ['body' => 'ไฟล์ที่จะหมดอายุ', 'client_id' => str_repeat('c9', 16)], ['files' => [['q.png', base64_decode(self::PNG)]]]);
        $head->get('/chat/c/' . $gid);
        $kept = (string) $db->scalar("SELECT public_id FROM ch_messages WHERE body = 'มีไฟล์'");
        $head->post('/chat/c/' . $gid . '/m/' . $kept . '/report', ['reason' => 'ทดสอบรายงานค้าง']);
        $this->t->clock->advance('+1096 days');
        $n = ChatService::of($this->t->app)->retention();
        self::assertGreaterThan(0, $n);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM ch_messages WHERE purged_at IS NULL AND id <> (SELECT id FROM (SELECT id FROM ch_messages WHERE public_id = ?) x)", [$kept]), 'everything else removed');
        self::assertSame('มีไฟล์', $db->scalar('SELECT body FROM ch_messages WHERE public_id = ?', [$kept]), 'open report: kept until handled');
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM ch_message_edits'));
        self::assertSame(
            ['final', 'disposed'],
            array_column($db->all("SELECT lifecycle_status FROM documents WHERE document_class = 'chat.file' ORDER BY id"), 'lifecycle_status'),
            'the expired file is disposed; the reported message\'s file waits'
        );
        self::assertArrayHasKey('chat.retention', $this->t->app->jobs()->all());
    }
}
