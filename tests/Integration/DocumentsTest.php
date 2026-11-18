<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Zip;
use Blue\Tests\Support\TestApp;

/** TC-REC part 1 (04 §6.17, 6.30.12): content checks, hashes, private + encrypted storage, versions, final = immutable, access. */
final class DocumentsTest extends TestCase
{
    private TestApp $t;

    public const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R 4 0 R]/Count 2>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n4 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

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

    private function file(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fromBytes($name, $bytes, $this->t->tmp);
    }

    /** @return array{document: string, version: int, duplicates: list<array{public_id: string, title: string}>} */
    private function upload(string $user, string $class, string $name, string $bytes, string $le = 'DEMO1'): array
    {
        return $this->t->app->documents()->upload($this->az($user), $class, $this->file($name, $bytes), ['title' => 'Test ' . $name, 'legal_entity_id' => $this->le($le)]);
    }

    public function testUploadStoresHashPageCountAndEncryptsConfidentialFiles(): void
    {
        $res = $this->upload('hr.demo1', 'core.confidential', 'contract.pdf', self::PDF);
        $v = $this->t->app->db()->one('SELECT v.* FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.public_id = ?', [$res['document']]) ?? [];
        self::assertSame(hash('sha256', self::PDF), $v['sha256']);
        self::assertSame(2, (int) $v['page_count']);
        self::assertSame('application/pdf', $v['mime_type']);
        self::assertSame(1, (int) $v['encrypted']);
        $path = $this->t->tmp . '/documents/' . substr((string) $v['storage_key'], 0, 2) . '/' . $v['storage_key'];
        self::assertFileExists($path);
        self::assertStringNotContainsString('%PDF', (string) file_get_contents($path), 'confidential files are encrypted at rest');
        self::assertSame('draft', $this->t->app->db()->scalar('SELECT lifecycle_status FROM documents WHERE public_id = ?', [$res['document']]));
        $dl = $this->t->app->documents()->download($this->az('hr.demo1'), $res['document']);
        self::assertSame(self::PDF, $dl['bytes']);
        self::assertSame(2, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action IN ('document.uploaded','document.downloaded') AND target_id = ?", [$res['document']]));
    }

    public function testFileTypeIsDecidedFromTheBytesAndDangerousContentIsRefused(): void
    {
        $cases = [
            ['invoice.pdf', "MZ\x90\x00 fake exe", 'file.error.type_not_allowed'],
            ['script.pdf', str_replace('/Type/Catalog', '/Type/Catalog/OpenAction<</S/JavaScript/JS(app.alert(1))>>', self::PDF), 'file.error.pdf_active'],
            ['obfuscated.pdf', str_replace('/Type/Catalog', '/Type/Catalog/AA<</O<</S/J#61vaScript>>>>', self::PDF), 'file.error.pdf_active'],
            ['logo.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'file.error.svg'],
            ['old.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 50), 'file.error.legacy_office'],
            ['macro.xlsx', Zip::build(['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>', 'xl/vbaProject.bin' => 'x']), 'file.error.macro'],
            ['locked.pdf', str_replace('trailer<<', 'trailer<</Encrypt 9 0 R', self::PDF), 'file.error.pdf_encrypted'],
            ['empty.pdf', '', 'file.error.empty'],
        ];
        foreach ($cases as [$name, $bytes, $expected]) {
            try {
                $this->upload('hr.demo1', 'core.general', $name, $bytes);
                self::fail("{$name} should be refused");
            } catch (ValidationException $e) {
                self::assertSame($expected, $e->errors['file'] ?? null, $name);
            }
        }
        self::assertSame(0, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM documents'), 'nothing stored');
        self::assertSame([], glob($this->t->tmp . '/documents/*/*') ?: [], 'no orphan files left behind');
    }

    public function testOversizedFilesAreRefusedUsingTheConfiguredLimit(): void
    {
        $this->t->app->registry()->applyNow($this->az(TestApp::ADMIN), 'documents.max_upload_mb', '1', 'test');
        $big = self::PDF . str_repeat('%', 1024 * 1024 + 10);
        $this->expectException(ValidationException::class);
        $this->upload('hr.demo1', 'core.general', 'big.pdf', $big);
    }

    public function testDraftVersionsFinalLockSupersedeAndDuplicates(): void
    {
        $docs = $this->t->app->documents();
        $az = $this->az('hr.demo1');
        $a = $this->upload('hr.demo1', 'core.general', 'policy.pdf', self::PDF);
        $v2 = str_replace('Count 2', 'Count 2 ', self::PDF);
        $docs->upload($az, '', $this->file('policy-v2.pdf', $v2), [], $a['document'], 'typo fixed');
        try {
            $docs->upload($az, '', $this->file('same.pdf', $v2), [], $a['document']);
            self::fail('identical file accepted as a new version');
        } catch (ValidationException $e) {
            self::assertSame('document.error.same_as_current', $e->errors['file']);
        }
        $docs->finalize($az, $a['document']);
        try {
            $docs->upload($az, '', $this->file('v3.pdf', self::PDF . ' '), [], $a['document']);
            self::fail('a final document accepted a new version');
        } catch (BusinessException $e) {
            self::assertSame('document.error.final_immutable', $e->messageKey);
        }
        $new = $docs->supersede($az, $a['document'], $this->file('policy-2027.pdf', self::PDF . '%2027'), 'new year');
        self::assertSame('superseded', $this->t->app->db()->scalar('SELECT lifecycle_status FROM documents WHERE public_id = ?', [$a['document']]));
        self::assertSame('final', $this->t->app->db()->scalar('SELECT lifecycle_status FROM documents WHERE public_id = ?', [$new['document']]));
        self::assertSame(2, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.public_id = ?', [$a['document']]), 'old versions kept');
        $dup = $this->upload('hr.demo1', 'core.general', 'again.pdf', self::PDF);
        self::assertSame([$a['document']], array_column($dup['duplicates'], 'public_id'), 'same bytes found (only documents the user can see)');
        self::assertSame([], $this->upload('hr.demo2', 'core.general', 'other.pdf', self::PDF, 'DEMO2')['duplicates'], 'another company\'s copy is not revealed');
    }

    public function testAccessFollowsClassPermissionAndCompanyScope(): void
    {
        $docs = $this->t->app->documents();
        $conf = $this->upload('hr.demo1', 'core.confidential', 'salary.pdf', self::PDF)['document'];
        $gen = $this->upload('hr.demo1', 'core.general', 'notice.pdf', self::PDF . ' ')['document'];
        foreach ([['hr.demo2', $conf], ['hr.demo2', $gen], ['employee.demo1', $gen], [TestApp::ADMIN, $conf]] as [$who, $pid]) {
            try {
                $docs->download($this->az($who), $pid);
                self::fail("{$who} downloaded {$pid}");
            } catch (HttpException $e) {
                self::assertSame(404, $e->httpStatus, 'refused without revealing that it exists');
            }
        }
        self::assertSame('notice.pdf', $docs->download($this->az(TestApp::ADMIN), $gen)['filename'], 'system admin sees general documents only');
        self::assertSame('salary.pdf', $docs->download($this->az('auditor.demo'), $conf)['filename'], 'auditors may read confidential (organization scope)');
        $list = $docs->list($this->az('hr.demo2'), [], 1, 50);
        self::assertSame(0, $list['total']);
        try {
            $this->upload('hr.demo1', 'core.general', 'x.pdf', self::PDF . 'x', 'DEMO2');
            self::fail('uploaded into another company');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testTamperedFilesAreRefusedAndAudited(): void
    {
        $docs = $this->t->app->documents();
        $pid = $this->upload('hr.demo1', 'core.general', 'a.pdf', self::PDF)['document'];
        $key = (string) $this->t->app->db()->scalar('SELECT storage_key FROM document_versions');
        file_put_contents($this->t->tmp . '/documents/' . substr($key, 0, 2) . '/' . $key, self::PDF . 'changed');
        self::assertSame([['version' => 1, 'result' => 'mismatch']], $docs->verify($this->az('hr.demo1'), $pid));
        try {
            $docs->download($this->az('hr.demo1'), $pid);
            self::fail('tampered file served');
        } catch (BusinessException $e) {
            self::assertSame('document.error.integrity', $e->messageKey);
        }
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'document.integrity_failed'"));
    }

    public function testFileNamesCannotEscapeAndStorageKeysAreNotPaths(): void
    {
        $res = $this->upload('hr.demo1', 'core.general', '../../etc/passwd<script>.pdf', self::PDF);
        $name = (string) $this->t->app->db()->scalar('SELECT original_filename FROM document_versions');
        self::assertSame('passwd_script_.pdf', $name);
        self::assertNotEmpty($res['document']);
        $storage = new \Blue\Core\Records\DocumentStorage($this->t->tmp . '/documents', $this->t->app->crypto());
        $this->expectException(\RuntimeException::class);
        $storage->get('../../config/env', false);
    }
}
