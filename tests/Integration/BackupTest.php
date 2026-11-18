<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Backup\SqlDumper;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Crypto;
use Blue\Tests\Support\TestApp;

/** M48 / TC-DR: encrypted backup, verification, restore drill in a separate database, approved restore with rollback plan, retention. */
final class BackupTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function az(string $u): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($u)['id']);
    }

    private function confidentialDoc(string $title): string
    {
        $le = (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        return $this->t->app->documents()->upload($this->az('hr.demo1'), 'core.confidential', UploadedFile::fromBytes('c.pdf', DocumentsTest::PDF, $this->t->tmp), ['title' => $title, 'legal_entity_id' => $le])['document'];
    }

    public function testBackupIsEncryptedVerifiedAndDetectsTampering(): void
    {
        $app = $this->t->app;
        $this->confidentialDoc('สัญญาลับ (DEMO)');
        try {
            $app->backups()->startManual($this->az('hr.demo1'), true);
            self::fail('HR ran a backup');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        $pid = $app->backups()->start('manual', (int) $this->t->user(TestApp::ADMIN)['id'], true);
        $b = $app->backups()->runToEnd($pid);
        self::assertSame('verified', $b['status'], (string) $b['error']);
        self::assertSame('snapshot', $b['consistency']);
        self::assertSame(1, (int) $b['file_count']);
        self::assertGreaterThan(60, (int) $b['db_tables']);
        $path = $app->backups()->path($b);
        $raw = (string) file_get_contents($path);
        self::assertStringStartsWith("ROSBAK1\n", $raw);
        foreach ([TestApp::ADMIN, 'hr.demo1', 'CREATE TABLE', 'สัญญาลับ'] as $plain) {
            self::assertStringNotContainsString($plain, $raw, 'nothing readable in the backup file');
        }
        self::assertSame([], glob($this->t->tmp . '/backups/work/*/*') ?: [], 'no plaintext parts left behind');
        self::assertSame([], $app->backups()->recheck($this->az(TestApp::ADMIN), $pid));
        // tamper
        $raw[(int) (strlen($raw) / 2)] = chr(ord($raw[(int) (strlen($raw) / 2)]) ^ 1);
        file_put_contents($path, $raw);
        self::assertNotSame([], $app->backups()->recheck($this->az(TestApp::ADMIN), $pid));
        self::assertSame(1, (int) $app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'backup.rechecked' AND outcome = 'failure'"));
    }

    public function testStepwiseDumpGivesTheSameDataAsOnePass(): void
    {
        $pdo = $this->t->app->db()->pdo();
        $a = $this->t->tmp . '/a.sql.gz';
        $b = $this->t->tmp . '/b.sql.gz';
        SqlDumper::dumpTable($pdo, 'i18n_messages', $a, true, null, microtime(true) + 60);
        $res = SqlDumper::dumpTable($pdo, 'i18n_messages', $b, true, null, 0.0); // deadline already passed → stops after one batch
        self::assertNotNull($res['cursor']);
        $steps = 1;
        while ($res['cursor'] !== null) {
            $res = SqlDumper::dumpTable($pdo, 'i18n_messages', $b, true, $res['cursor'], 0.0);
            $steps++;
        }
        self::assertGreaterThan(2, $steps);
        self::assertSame(SqlDumper::dataHash($a), SqlDumper::dataHash($b), 'resuming from a cursor writes the same rows');
    }

    public function testDrillLoadsIntoASeparateDatabaseAndProvesData(): void
    {
        $app = $this->t->app;
        $this->confidentialDoc('drill doc');
        $pid = $app->backups()->start('manual', null, true);
        $app->backups()->runToEnd($pid);
        $drill = $app->restores()->drill($this->az(TestApp::ADMIN), $pid);
        $d = $app->restores()->drillRow($drill);
        self::assertSame('passed', $d['status'], json_encode($d['report'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('ok', $d['key_check'], 'encrypted documents open with the current APP_KEY');
        self::assertSame(1, (int) $d['files_checked']);
        self::assertSame((int) $app->backups()->row($pid)['db_tables'], (int) $d['tables_checked']);
        self::assertNotNull($d['duration_ms']);
        self::assertSame($app->config->string('DB_NAME') . '_drill', $d['target_database']);
        $left = (int) $app->db()->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$d['target_database']]);
        self::assertSame(0, $left, 'no copy of the data is left in the drill database');
        self::assertGreaterThan(0, (int) $app->db()->scalar('SELECT COUNT(*) FROM users'), 'live database untouched');
    }

    public function testRestoreNeedsDrillSecondAdminAndPhraseAndKeepsTheCatalogue(): void
    {
        $app = $this->t->app;
        $pid = $app->backups()->start('manual', null, true);
        $app->backups()->runToEnd($pid);
        try {
            $app->restores()->request($this->az(TestApp::ADMIN), $pid, 'INC-1', 'test');
            self::fail('restore requested without a drill');
        } catch (BusinessException $e) {
            self::assertSame('backup.error.drill_first', $e->messageKey);
        }
        $app->restores()->drill($this->az(TestApp::ADMIN), $pid);
        try {
            $app->restores()->request($this->az(TestApp::ADMIN), $pid, '', 'test');
            self::fail('no incident reference');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('incident', $e->errors);
        }
        $req = $app->restores()->request($this->az(TestApp::ADMIN), $pid, 'INC-2026-001', 'ทดสอบกู้คืน');
        $inst = (string) $app->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'restore_request' AND subject_id = ?", [$req]);
        try {
            $app->workflow()->decide($inst, (int) $this->t->user(TestApp::ADMIN)['id'], 'approve');
            self::fail('requester approved own restore');
        } catch (HttpException | BusinessException) {
            self::assertTrue(true);
        }
        $app->workflow()->decide($inst, (int) $this->t->user('sysadmin2.demo')['id'], 'approve');
        self::assertSame('approved', $app->restores()->requestRow($req)['status']);
        try {
            $app->restores()->execute($this->az('sysadmin2.demo'), $req, 'x');
            self::fail('approver executed');
        } catch (BusinessException $e) {
            self::assertSame('backup.error.executor', $e->messageKey);
        }
        try {
            $app->restores()->execute($this->az(TestApp::ADMIN), $req, 'RESTORE wrong');
            self::fail('wrong phrase');
        } catch (ValidationException $e) {
            self::assertSame('backup.error.phrase', $e->errors['phrase']);
        }
        // a change made AFTER the backup — gone after the restore
        $doc = $this->confidentialDoc('created after backup');
        $usersBefore = (int) $app->db()->scalar('SELECT COUNT(*) FROM users');
        $app->db()->run("UPDATE users SET display_name = 'CHANGED' WHERE username = 'hr.demo1'");
        $result = $app->restores()->execute($this->az(TestApp::ADMIN), $req, \Blue\Core\Backup\RestoreService::confirmPhrase($pid));
        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        self::assertSame(0, (int) $app->db()->scalar('SELECT COUNT(*) FROM documents WHERE public_id = ?', [$doc]));
        self::assertNotSame('CHANGED', $app->db()->scalar("SELECT display_name FROM users WHERE username = 'hr.demo1'"));
        self::assertSame($usersBefore, (int) $app->db()->scalar('SELECT COUNT(*) FROM users'));
        // the catalogue survived: the pre-restore backup and this request are there
        self::assertSame('done', $app->restores()->requestRow($req)['status']);
        self::assertSame(1, (int) $app->db()->scalar("SELECT COUNT(*) FROM backup_runs WHERE kind = 'pre_restore' AND status = 'verified'"));
        self::assertSame(1, (int) $app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'backup.restored'"));
        self::assertFalse($app->install->isRestoring(), 'maintenance flag removed');
        if ($app->db()->scalar("SELECT info_value FROM system_info WHERE info_key = 'history_guard'") === 'triggers') {
            self::assertGreaterThan(0, (int) $app->db()->scalar('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'), 'append-only guards restored');
        }
    }

    public function testImportRefusesAnotherKeyAndRetentionKeepsTheNewest(): void
    {
        $app = $this->t->app;
        $pid = $app->backups()->start('manual', null, false);
        $b = $app->backups()->runToEnd($pid);
        // the same file re-imported is accepted; from another APP_KEY it is refused
        $copy = $this->t->tmp . '/copy.bluebak';
        copy($app->backups()->path($b), $copy);
        $imported = $app->backups()->importFile((int) $this->t->user(TestApp::ADMIN)['id'], $copy);
        self::assertSame('verified', $app->backups()->row($imported)['status']);
        $other = new \Blue\Core\Backup\BackupCipher((new Crypto('base64:' . base64_encode(str_repeat('z', 32))))->deriveKey('blue-backup-v1'), Crypto::backends()[0]);
        file_put_contents($this->t->tmp . '/plain', 'x');
        $other->encryptFile($this->t->tmp . '/plain', $this->t->tmp . '/other.bluebak', []);
        try {
            $app->backups()->importFile(1, $this->t->tmp . '/other.bluebak');
            self::fail('foreign key accepted');
        } catch (BusinessException $e) {
            self::assertSame('backup.error.key_mismatch', $e->messageKey);
        }
        // retention: 40 days later only the newest verified backup and the monthly one stay
        $this->t->clock->advance('+40 days');
        $newest = $app->backups()->start('scheduled', null, false);
        $app->backups()->runToEnd($newest);
        $app->registry()->applyNow($this->az(TestApp::ADMIN), 'backup.keep_monthly', '0', 'test');
        $this->t->clock->advance('+1 second');
        self::assertSame(2, $app->backups()->prune());
        self::assertSame('verified', $app->backups()->row($newest)['status']);
        self::assertSame('deleted', $app->backups()->row($pid)['status']);
        self::assertFileDoesNotExist($app->backups()->path($b));
        try {
            $app->backups()->delete($this->az(TestApp::ADMIN), $newest, 'x');
            self::fail('newest verified backup deleted');
        } catch (BusinessException $e) {
            self::assertSame('backup.error.cannot_delete', $e->messageKey);
        }
    }

    public function testScheduleStartsWhenDueAndAlertsWhenOverdue(): void
    {
        $app = $this->t->app;
        self::assertSame('started', $app->backups()->tick(60000));
        $running = $app->db()->one('SELECT * FROM backup_runs ORDER BY id DESC LIMIT 1');
        self::assertSame('scheduled', $running['kind']);
        for ($i = 0; $i < 20 && $app->backups()->row((string) $running['public_id'])['status'] === 'running'; $i++) {
            $app->backups()->tick(60000);
        }
        self::assertSame('verified', $app->backups()->row((string) $running['public_id'])['status']);
        self::assertSame('idle', $app->backups()->tick(60000), 'not due again');
        self::assertFalse($app->backups()->health()['overdue']);
        $this->t->clock->advance('+30 hours');
        self::assertTrue($app->backups()->health()['overdue']);
        $app->db()->run("UPDATE scheduled_jobs SET enabled = 0");
        $app->registry()->applyNow($this->az(TestApp::ADMIN), 'backup.interval_hours', '168', 'weekly for the test');
        $this->t->clock->advance('+1 second');
        $app->backups()->tick(60000);
        self::assertGreaterThan(0, (int) $app->db()->scalar("SELECT COUNT(*) FROM notifications WHERE title_key = 'notify.backup.overdue'"));
    }
}
