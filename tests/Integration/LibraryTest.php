<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Pdf\Toolkit;
use Blue\Modules\Library\LibraryService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9D-3 (DEC-99): M03 online library. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). DEMO1 employees with accounts:
 * employee.demo1, manager.demo1, hr.demo1, hr.head1 (HRA — may manage). ga.demo1 (WPA, no employee record) is the librarian.
 */
final class LibraryTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(library|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function lib(): LibraryService
    {
        return LibraryService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    private function code(string $user): string
    {
        return (string) $this->t->app->db()->scalar('SELECT e.employee_code FROM employees e JOIN users u ON u.employee_id = e.id WHERE u.username = ?', [$user]);
    }

    private function file(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fromBytes($name, $bytes, $this->t->tmp);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/library/' . $name);
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
            return 'http.' . $e->httpStatus;
        } catch (BusinessException $e) {
            return $e->messageKey;
        }
        self::fail('expected a refusal');
    }

    private function notes(string $user, string $key): int
    {
        return (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title_key = ?', [$this->t->user($user)['id'], $key]);
    }

    /** @param array<string, mixed> $over */
    private function digital(array $over = []): string
    {
        return $this->lib()->save($this->az('ga.demo1'), $this->le(), $over + ['title' => 'นโยบายการลา 2569', 'kind' => 'digital', 'language' => 'th', 'author' => 'ฝ่ายบุคคล',
            'tags' => 'ลา, สวัสดิการ', 'rights_holder' => 'บริษัท', 'license' => 'เอกสารภายในบริษัท']);
    }

    public function testDigitalItemsSearchReadingVersionsAndWithdrawal(): void
    {
        $s = $this->lib();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $emp = $this->az('employee.demo1');
        $le = $this->le();
        // G9D3-01 the form: required, choices, year; employees cannot add items
        $e = $this->errors(fn () => $s->save($ga, $le, ['title' => 'x', 'kind' => 'video', 'language' => '', 'pub_year' => '1200']));
        self::assertSame(['title', 'kind', 'language', 'pub_year'], array_keys($e));
        self::assertSame('http.403', $this->refused(fn () => $s->save($emp, $le, ['title' => 'ทดลอง', 'kind' => 'digital', 'language' => 'th'])));
        // G9D3-02 the file is checked before it is stored; PDF text is indexed per page
        $pid = $this->digital(['rights_holder' => '', 'license' => '']);
        self::assertSame(['file' => 'library.error.file_required'], $this->errors(fn () => $s->upload($ga, $pid, null, '')));
        self::assertSame(['file' => 'file.error.type_not_allowed'], $this->errors(fn () => $s->upload($ga, $pid, $this->file('a.txt', "hello\nworld"), '')));
        self::assertSame(['file' => 'library.error.broken_file'], $this->errors(fn () => $s->upload($ga, $pid, $this->file('bad.pdf', "%PDF-1.4\nthis is not a pdf at all"), '')));
        $locked = Toolkit::protect(self::fixture('rl.pdf'), '', 'secret123', 'owner12345', -4)['bytes'];
        self::assertSame(['file' => 'file.error.pdf_encrypted'], $this->errors(fn () => $s->upload($ga, $pid, $this->file('locked.pdf', (string) $locked), '')));
        self::assertSame(['text_status' => 'indexed', 'pages' => 2], $s->upload($ga, $pid, $this->file('leave.pdf', self::fixture('lo.pdf')), 'ฉบับแรก'));
        self::assertSame(2, (int) $db->scalar('SELECT COUNT(*) FROM lb_text t JOIN lb_items i ON i.id = t.item_id WHERE i.public_id = ?', [$pid]));
        // publishing needs the rights holder and the licence
        self::assertSame(['license' => 'library.error.license_needed'], $this->errors(fn () => $s->publish($ga, $pid)));
        $s->save($ga, $le, ['title' => 'นโยบายการลา 2569', 'kind' => 'digital', 'language' => 'th', 'author' => 'ฝ่ายบุคคล', 'tags' => 'ลา, สวัสดิการ', 'rights_holder' => 'บริษัท', 'license' => 'เอกสารภายในบริษัท'], $pid);
        $s->publish($ga, $pid);
        self::assertSame('library.error.not_draft', $this->refused(fn () => $s->publish($ga, $pid)));
        // G9D3-03 the same file cannot be in two items
        $other = $this->digital(['title' => 'สำเนานโยบาย']);
        self::assertSame(['file' => 'library.error.duplicate_file'], $this->errors(fn () => $s->upload($ga, $other, $this->file('copy.pdf', self::fixture('lo.pdf')), '')));
        // G9D3-04 search: words in the details, or the text inside the PDF (with the page)
        $hit = $s->search($emp, $le, ['q' => 'ใบรับรองแพทย์']);
        self::assertSame(1, $hit['total']);
        self::assertSame($pid, $hit['rows'][0]['public_id']);
        self::assertSame(2, $hit['rows'][0]['hits'][0]['page']);
        self::assertStringContainsString('ใบรับรองแพทย์', (string) preg_replace('/\s+/u', '', $hit['rows'][0]['hits'][0]['snippet']));
        self::assertSame(1, $s->search($emp, $le, ['q' => 'sick leave'])['total']);
        self::assertSame(1, $s->search($emp, $le, ['q' => 'สวัสดิการ ฝ่ายบุคคล'])['total']); // two words, both in the details
        self::assertSame(0, $s->search($emp, $le, ['q' => 'ไม่มีคำนี้แน่นอน'])['total']);
        self::assertSame(0, $s->search($emp, $le, ['q' => 'สำเนานโยบาย'])['total']); // drafts are not shown
        self::assertSame(1, $s->search($emp, $le, ['kind' => 'physical'])['total']); // the demo book
        // an item for a group the employee is not in is invisible to them
        $closed = $this->digital(['title' => 'คู่มือผู้บริหาร', 'groups' => 'NOBODY']);
        $s->upload($ga, $closed, $this->file('exec.pdf', (new \Blue\Core\Pdf\SimpleDocument())->title('คู่มือผู้บริหาร')->text('แผนกลยุทธ์ปี 2570')->output()), '');
        $s->publish($ga, $closed);
        self::assertSame(0, $s->search($emp, $le, ['q' => 'คู่มือผู้บริหาร'])['total']);
        self::assertSame('http.404', $this->refused(fn () => $s->item($emp, $closed)));
        self::assertSame('http.404', $this->refused(fn () => $s->file($emp, $closed, false)));
        // G9D3-05 reading: a view counts once a person a day; downloads are counted and audited; place and bookmarks
        $s->file($emp, $pid, false);
        $s->file($emp, $pid, false);
        self::assertSame(1, (int) $db->scalar('SELECT views FROM lb_items WHERE public_id = ?', [$pid]));
        $this->t->clock->advance('+1 day');
        $s->file($emp, $pid, false);
        self::assertSame(2, (int) $db->scalar('SELECT views FROM lb_items WHERE public_id = ?', [$pid]));
        $f = $s->file($emp, $pid, true);
        self::assertSame(self::fixture('lo.pdf'), $f['bytes']);
        self::assertSame(1, (int) $db->scalar('SELECT downloads FROM lb_items WHERE public_id = ?', [$pid]));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'library.downloaded'"));
        $s->progress($emp, $pid, 2);
        self::assertSame(['page' => 'library.error.page'], $this->errors(fn () => $s->progress($emp, $pid, 3)));
        $s->bookmark($emp, $pid, 2, 'ลาป่วย');
        $s->bookmark($emp, $pid, 2, 'ลาป่วยต้องมีใบรับรอง');
        $item = $s->item($emp, $pid, 'sick leave');
        self::assertCount(1, $item['bookmarks']);
        self::assertSame('ลาป่วยต้องมีใบรับรอง', $item['bookmarks'][0]['note']);
        self::assertSame(2, (int) $item['read']['last_page']);
        self::assertSame(2, $item['hits'][0]['page']);
        self::assertTrue($item['can_read_online']);
        self::assertTrue($item['can_download']);
        $s->unbookmark($emp, $pid, (int) $item['bookmarks'][0]['id']);
        self::assertSame([], $s->item($emp, $pid)['bookmarks']);
        self::assertSame(2, (int) $s->recent($emp)[0]['last_page']);
        self::assertStringContainsString('ใบรับรองแพทย์', (string) preg_replace('/\s+/u', '', $s->pageText($emp, $pid, 2)));
        // read online only: no download for readers (librarians still can); only for PDF
        $s->save($ga, $le, ['title' => 'นโยบายการลา 2569', 'kind' => 'digital', 'language' => 'th', 'author' => 'ฝ่ายบุคคล', 'tags' => 'ลา, สวัสดิการ', 'rights_holder' => 'บริษัท', 'license' => 'เอกสารภายในบริษัท', 'read_only' => '1'], $pid);
        self::assertSame('library.error.read_only', $this->refused(fn () => $s->file($emp, $pid, true)));
        self::assertFalse($s->item($emp, $pid)['can_download']);
        self::assertSame(self::fixture('lo.pdf'), $s->file($this->az('hr.head1'), $pid, true)['bytes']);
        self::assertSame(['file' => 'library.error.read_only_pdf'], $this->errors(fn () => $s->upload($ga, $pid, $this->file('sheet.xlsx', self::xlsx()), '')));
        // G9D3-06 a new version replaces the text index; the old one is kept for librarians only
        self::assertSame(['text_status' => 'indexed', 'pages' => 2], $s->upload($ga, $pid, $this->file('leave-v2.pdf', self::fixture('rl.pdf')), 'ฉบับปรับปรุง'));
        self::assertSame(0, $s->search($emp, $le, ['q' => 'ใบรับรองแพทย์'])['total']);
        self::assertSame(1, $s->search($emp, $le, ['q' => 'emergency stop'])['total']);
        self::assertSame(self::fixture('lo.pdf'), $s->version($ga, $pid, 1)['bytes']);
        self::assertSame('http.403', $this->refused(fn () => $s->version($emp, $pid, 1)));
        $m = $s->manage($ga, $pid);
        self::assertSame([2, 1], array_map(static fn (array $f): int => (int) $f['version_no'], $m['files']));
        self::assertSame(2, (int) $m['last30']['downloads']); // the reader's and the librarian's
        // G9D3-07 withdraw: a reason; readers lose it at once ("continue reading" shows it as withdrawn); back to draft
        self::assertSame(['reason' => 'library.error.reason'], $this->errors(fn () => $s->archive($ga, $pid, 'x')));
        $s->archive($ga, $pid, 'ประกาศนโยบายใหม่แทนแล้ว');
        self::assertSame(0, $s->search($emp, $le, ['q' => 'นโยบายการลา'])['total']);
        self::assertSame('http.404', $this->refused(fn () => $s->item($emp, $pid)));
        self::assertTrue($s->recent($emp)[0]['gone']);
        self::assertSame('library.error.archived', $this->refused(fn () => $s->upload($ga, $pid, $this->file('v3.pdf', self::fixture('lo.pdf')), '')));
        $s->restore($ga, $pid);
        self::assertSame('draft', $db->scalar('SELECT status FROM lb_items WHERE public_id = ?', [$pid]));
        // other files: an Office file is download-only; a scanned PDF has no text to search
        $sheet = $this->digital(['title' => 'แบบฟอร์มเบิกค่าใช้จ่าย']);
        self::assertSame(['text_status' => 'not_pdf', 'pages' => null], $s->upload($ga, $sheet, $this->file('claim.xlsx', self::xlsx()), ''));
        $s->publish($ga, $sheet);
        self::assertFalse($s->item($emp, $sheet)['can_read_online']);
        self::assertSame('http.404', $this->refused(fn () => $s->file($emp, $sheet, false)));
        $img = imagecreatetruecolor(60, 40);
        ob_start();
        imagepng($img);
        $scan = $this->digital(['title' => 'เอกสารสแกน']);
        self::assertSame(['text_status' => 'no_text', 'pages' => 1], $s->upload($ga, $scan, $this->file('scan.pdf', (string) Toolkit::imagesToPdf([(string) ob_get_clean()], 'A4', 10)['bytes']), ''));
        // the kind cannot change once there is a file
        self::assertSame(['kind' => 'library.error.kind_locked'], $this->errors(fn () => $s->save($ga, $le, ['title' => 'เอกสารสแกน', 'kind' => 'physical', 'language' => 'th', 'loan_days' => '7', 'pickup_days' => '2'], $scan)));
    }

    private static function xlsx(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        $z = new \ZipArchive();
        $z->open((string) $tmp, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
        $z->close();
        $b = (string) file_get_contents((string) $tmp);
        unlink((string) $tmp);
        return $b;
    }

    public function testBooksReserveQueueLendReturnLostAndScreens(): void
    {
        $s = $this->lib();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $emp = $this->az('employee.demo1');
        $mgr = $this->az('manager.demo1');
        $head = $this->az('hr.head1');
        $hr = $this->az('hr.demo1');
        $le = $this->le();
        // G9D3-08 a book needs both periods (no defaults), and a copy before it is published
        $book = ['title' => 'Atomic Habits (ฉบับแปล)', 'kind' => 'physical', 'language' => 'th', 'author' => 'James Clear', 'rights_holder' => 'สำนักพิมพ์', 'license' => 'ซื้อเล่มจริง'];
        self::assertSame(['loan_days', 'pickup_days'], array_keys($this->errors(fn () => $s->save($ga, $le, $book))));
        $pid = $s->save($ga, $le, $book + ['loan_days' => '14', 'pickup_days' => '3']);
        self::assertSame(['copies' => 'library.error.copy_needed'], $this->errors(fn () => $s->publish($ga, $pid)));
        $s->addCopy($ga, $pid, 'B-1', 'ชั้น 2');
        self::assertSame(['code' => 'library.error.duplicate_copy'], $this->errors(fn () => $s->addCopy($ga, $pid, 'B-1', '')));
        self::assertSame(['code' => 'library.error.copy_code'], $this->errors(fn () => $s->addCopy($ga, $pid, 'B 1 !', '')));
        $s->publish($ga, $pid);
        // G9D3-09 the last copy: the first person gets it held, the next ones wait in order
        self::assertSame('ready', $s->reserve($emp, $pid));
        self::assertSame('2026-11-12', $db->scalar("SELECT ready_until FROM lb_loans WHERE status = 'ready'"));
        self::assertSame('reserved', $db->scalar("SELECT status FROM lb_copies WHERE code = 'B-1'"));
        self::assertSame('waiting', $s->reserve($mgr, $pid));
        self::assertSame('waiting', $s->reserve($head, $pid));
        self::assertSame('library.error.already', $this->refused(fn () => $s->reserve($mgr, $pid)));
        self::assertSame(2, $s->item($head, $pid)['queue_pos']);
        self::assertSame('library.error.employees_only', $this->refused(fn () => $s->reserve($ga, $pid)));
        // G9D3-10 the desk: someone without a held copy cannot take one; the person it is held for gets it, due in 14 days
        self::assertSame(['who' => 'library.error.no_free_copy'], $this->errors(fn () => $s->lend($ga, $pid, $this->code('manager.demo1'), '')));
        self::assertSame(['who' => 'library.error.unknown_employee'], $this->errors(fn () => $s->lend($ga, $pid, 'NO-SUCH', '')));
        self::assertSame('http.403', $this->refused(fn () => $s->lend($emp, $pid, $this->code('employee.demo1'), '')));
        $out = $s->lend($ga, $pid, $this->code('employee.demo1') . ' — ชื่อ', '');
        self::assertSame(['due' => '2026-11-23', 'copy' => 'B-1'], ['due' => $out['due'], 'copy' => $out['copy']]);
        self::assertSame('loaned', $db->scalar("SELECT status FROM lb_copies WHERE code = 'B-1'"));
        // G9D3-11 a reminder the day before the due date, one notice once overdue (never twice)
        $this->t->clock->advance('+13 days'); // Sun 22
        $s->tick();
        $s->tick();
        self::assertSame(1, $this->notes('employee.demo1', 'library.notify.due'));
        $this->t->clock->advance('+2 days'); // Tue 24
        $s->tick();
        $s->tick();
        self::assertSame(1, $this->notes('employee.demo1', 'library.notify.overdue'));
        self::assertSame(1, count(array_filter($s->desk($ga, $le), static fn (array $r): bool => $r['status'] === 'loaned')));
        // G9D3-12 return (1 day late): the copy is held for the next in the queue, who is told
        self::assertSame(['copy' => 'library.error.unknown_copy'], $this->errors(fn () => $s->giveBack($ga, $pid, 'B-9')));
        $back = $s->giveBack($ga, $pid, 'B-1');
        self::assertSame([1, true], [$back['late_days'], $back['next']]);
        self::assertSame(['copy' => 'library.error.not_loaned'], $this->errors(fn () => $s->giveBack($ga, $pid, 'B-1')));
        self::assertSame(1, $this->notes('manager.demo1', 'library.notify.ready'));
        self::assertSame('2026-11-27', $db->scalar("SELECT ready_until FROM lb_loans WHERE status = 'ready'"));
        // nobody comes: the hold expires and passes on to the next person
        $this->t->clock->advance('+4 days'); // Sat 28
        $s->tick();
        self::assertSame(1, $this->notes('manager.demo1', 'library.notify.expired'));
        self::assertSame(1, $this->notes('hr.head1', 'library.notify.ready'));
        $mine = $s->myLoans($head);
        self::assertSame('ready', $mine[0]['status']);
        $s->cancelMine($head, (string) $mine[0]['public_id']);
        self::assertSame('available', $db->scalar("SELECT status FROM lb_copies WHERE code = 'B-1'"));
        self::assertSame('http.404', $this->refused(fn () => $s->cancelMine($emp, (string) $mine[0]['public_id'])));
        // G9D3-13 a new copy goes straight to the person waiting; a held copy cannot be lent to someone else
        self::assertSame('ready', $s->reserve($emp, $pid));
        self::assertSame('waiting', $s->reserve($hr, $pid));
        $s->addCopy($ga, $pid, 'B-2', 'ชั้น 2');
        self::assertSame(1, $this->notes('hr.demo1', 'library.notify.ready'));
        self::assertSame(['copy' => 'library.error.copy_busy'], $this->errors(fn () => $s->lend($ga, $pid, $this->code('hr.demo1'), 'B-1')));
        self::assertSame('B-2', $s->lend($ga, $pid, $this->code('hr.demo1'), '')['copy']);
        // lost: a reason; the copy is out of use (no fine is worked out)
        $loan = (string) $db->scalar("SELECT public_id FROM lb_loans WHERE status = 'loaned'");
        self::assertSame(['reason' => 'library.error.reason'], $this->errors(fn () => $s->markLost($ga, $loan, 'หาย')));
        $s->markLost($ga, $loan, 'ผู้ยืมแจ้งว่าทำหายระหว่างเดินทาง');
        self::assertSame('lost', $db->scalar("SELECT status FROM lb_copies WHERE code = 'B-2'"));
        self::assertSame(['total' => 1, 'available' => 0], $s->item($emp, $pid)['copies']);
        self::assertSame('library.error.copy_in_use', $this->refused(fn () => $s->withdrawCopy($ga, (string) $db->scalar("SELECT public_id FROM lb_copies WHERE code = 'B-1'"), 'ปกขาด ส่งซ่อม')));
        // G9D3-14 withdrawing the book cancels the hold (the person is told) and frees the copy
        $s->archive($ga, $pid, 'ย้ายไปห้องสมุดสาขา');
        self::assertSame(1, $this->notes('employee.demo1', 'library.notify.withdrawn'));
        self::assertSame('available', $db->scalar("SELECT status FROM lb_copies WHERE code = 'B-1'"));
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM lb_loans WHERE status IN ('waiting','ready','loaned')"));
        self::assertGreaterThanOrEqual(10, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'library.%'"));

        // G9D3-15 screens
        $s->restore($ga, $pid);
        $s->publish($ga, $pid);
        $doc = $this->digital(['title' => 'คู่มือพนักงาน 2569']);
        $s->upload($ga, $doc, $this->file('handbook.pdf', self::fixture('lo.pdf')), '');
        $s->publish($ga, $doc);
        $g = $this->t->browser();
        $g->login('ga.demo1');
        foreach (['/library/manage', '/library/manage/new', '/library/desk', '/library/m/' . $pid, '/library/m/' . $doc] as $path) {
            $res = $g->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertSame(422, $g->post('/library/manage/new', ['title' => ''])->status);
        self::assertSame(303, $g->post('/library/m/' . $pid . '/copy', ['code' => 'B-3'])->status);
        self::assertSame(422, $g->post('/library/m/' . $pid . '/lend', ['who' => 'NO-SUCH'])->status);
        self::assertSame(303, $g->post('/library/m/' . $pid . '/lend', ['who' => $this->code('manager.demo1')])->status);
        self::assertSame(303, $g->post('/library/m/' . $pid . '/return', ['copy' => 'B-1'])->status);
        $bad = $g->post('/library/m/' . $doc . '/upload', [], ['file' => ['x.txt', 'plain']]);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString($this->t->app->translator()->get('file.error.type_not_allowed'), $bad->body);
        $web = $this->digital(['title' => 'อัปโหลดผ่านเว็บ']);
        self::assertSame(303, $g->post('/library/m/' . $web . '/upload', ['note' => 'ฉบับเว็บ'], ['file' => ['handbook-v2.pdf', (new \Blue\Core\Pdf\SimpleDocument())->title('คู่มือพนักงาน')->text('sick leave rules')->output()]])->status);
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM lb_files f JOIN lb_items i ON i.id = f.item_id WHERE i.public_id = ?', [$web]));
        $e1 = $this->t->browser();
        $e1->login('employee.demo1');
        foreach (['/library', '/library/loans', '/library/i/' . $pid, '/library/i/' . $doc] as $path) {
            $res = $e1->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        $reader = $e1->get('/library/i/' . $doc . '/read', ['page' => '2']);
        self::assertSame(200, $reader->status);
        self::assertStringContainsString('data-start="2"', $reader->body);
        self::assertStringContainsString('vendor/pdfjs/pdf.min.js', $reader->body);
        $found = $e1->get('/library', ['q' => 'ใบรับรองแพทย์']);
        self::assertSame(200, $found->status);
        self::assertStringContainsString('/library/i/' . $doc . '/read?page=2', $found->body);
        $view = $e1->get('/library/i/' . $doc . '/view');
        self::assertSame(200, $view->status);
        self::assertStringStartsWith('%PDF', $view->body);
        self::assertStringStartsWith('inline', (string) $view->headers['Content-Disposition']);
        self::assertSame(200, $e1->get('/library/i/' . $doc . '/download')->status);
        $txt = $e1->get('/library/i/' . $doc . '/text', ['page' => '2']);
        self::assertSame(200, $txt->status);
        self::assertStringContainsString('sick leave', $txt->body);
        self::assertSame(303, $e1->post('/library/i/' . $doc . '/bookmark', ['page' => '2', 'note' => 'สำคัญ'])->status);
        self::assertSame(422, $e1->post('/library/i/' . $doc . '/bookmark', ['page' => '9'])->status);
        self::assertSame(303, $e1->post('/library/i/' . $pid . '/reserve')->status);
        self::assertSame(403, $e1->get('/library/manage')->status);
        self::assertSame(403, $e1->get('/library/desk')->status);
        self::assertSame(403, $e1->get('/library/m/' . $pid)->status);
    }
}
