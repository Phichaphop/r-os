<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Backup\BackupCipher;
use Blue\Core\Backup\RestoreService;
use Blue\Core\Console\Console;
use Blue\Core\Support\Crypto;
use Blue\Tests\Support\TestApp;

/**
 * 0.50.0 (R9, DEC-119 · Q-17/Q-19): the operator commands for the Staging notebook — `doctor` (fit for real data?),
 * `backup:run`, `backup:drill` and `restore:fresh` (disaster recovery onto a NEW installation, where only the
 * installer's account exists, so the second-administrator approval of a normal restore cannot happen).
 */
final class OpsCliTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'staging', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    /** @return array{0: int, 1: string} */
    private function cli(string ...$args): array
    {
        return $this->cliWithInput('', ...$args);
    }

    /** @return array{0: int, 1: string} */
    private function cliWithInput(string $input, string ...$args): array
    {
        $out = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($in);
        fwrite($in, $input);
        rewind($in);
        $code = (new Console($this->t->app, $out, $in))->run(['blue', ...$args]);
        rewind($out);
        return [$code, (string) stream_get_contents($out)];
    }

    private static function lineOf(string $out, string $check): string
    {
        foreach (explode("\n", $out) as $l) {
            if (preg_match('/^(PASS|WARN|FAIL)\s+' . preg_quote($check, '/') . '\s/', $l) === 1) {
                return $l;
            }
        }
        return '';
    }

    public function testDoctorListsWhatToFixAndPrintsNoSecret(): void
    {
        [$code, $out] = $this->cli('doctor');
        self::assertStringNotContainsString('ERROR:', $out);
        self::assertStringStartsWith('PASS  environment', self::lineOf($out, 'environment'));
        self::assertStringStartsWith('PASS  migrations', self::lineOf($out, 'migrations'));
        self::assertStringStartsWith('PASS  installed', self::lineOf($out, 'installed'));
        self::assertStringStartsWith('PASS  app key', self::lineOf($out, 'app key'));
        self::assertStringContainsString($this->t->app->backups()->cipher()->fingerprint(), self::lineOf($out, 'app key'));
        self::assertStringStartsWith('WARN  backup', self::lineOf($out, 'backup'), 'no backup yet');
        self::assertStringStartsWith('WARN  off-site copy', self::lineOf($out, 'off-site copy'));
        self::assertStringStartsWith('WARN  restore drill', self::lineOf($out, 'restore drill'));
        self::assertStringStartsWith('WARN  scheduler', self::lineOf($out, 'scheduler'), 'jobs never ran');
        self::assertStringStartsWith('WARN  demo data', self::lineOf($out, 'demo data'), 'staging: demo data is a warning');
        self::assertNotSame('', self::lineOf($out, 'database version'));
        self::assertStringNotContainsString('base64:', $out, 'APP_KEY never printed');
        $pass = $this->t->app->config->string('DB_PASS');
        if ($pass !== '') {
            self::assertStringNotContainsString($pass, $out, 'DB password never printed');
        }

        // after a backup, a drill and a scheduler run the warnings go away
        self::assertSame(0, $this->cli('backup:run', '--no-files')[0]);
        self::assertSame(0, $this->cli('backup:drill', '--as=' . TestApp::ADMIN)[0]);
        $this->t->app->scheduler()->runDue('cron', 20000, 15);
        [, $after] = $this->cli('doctor');
        self::assertStringStartsWith('PASS  backup', self::lineOf($after, 'backup'));
        self::assertStringStartsWith('PASS  restore drill', self::lineOf($after, 'restore drill'));
        self::assertStringStartsWith('PASS  scheduler', self::lineOf($after, 'scheduler'));
        self::assertSame(str_contains($out, "\nFAIL ") || str_starts_with($out, 'FAIL ') ? 1 : 0, $code, "exit 1 exactly when a check fails:\n" . $out);
    }

    public function testProductionWithDemoDataFails(): void
    {
        $prod = TestApp::create($this, 'production', false);
        try {
            $out = fopen('php://memory', 'w+');
            self::assertIsResource($out);
            $code = (new Console($prod->app, $out))->run(['blue', 'doctor']);
            rewind($out);
            $text = (string) stream_get_contents($out);
            self::assertSame(1, $code);
            self::assertStringStartsWith('FAIL  demo data', self::lineOf($text, 'demo data'));
            self::assertStringContainsString('Result: FAIL', $text);
        } finally {
            $prod->cleanup();
        }
    }

    public function testBackupAndDrillFromTheCommandLine(): void
    {
        [$code, $out] = $this->cli('backup:drill', '--as=' . TestApp::ADMIN);
        self::assertSame(1, $code, 'no backup yet');
        self::assertStringContainsString('no verified backup', $out);
        [$code, $out] = $this->cli('backup:run');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString(': verified', $out);
        self::assertMatchesRegularExpression('/SHA-256:  [0-9a-f]{64}/', $out);
        self::assertSame(1, $this->cli('backup:drill')[0], 'the drill names who runs it');
        self::assertSame(1, $this->cli('backup:drill', '--as=employee.demo1')[0], 'an employee cannot drill');
        [$code, $out] = $this->cli('backup:drill', '--as=' . TestApp::ADMIN);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('PASSED', $out);
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM restore_drills WHERE status = 'passed'"));
    }

    public function testFreshInstallRestoreAfterAMachineLoss(): void
    {
        $app = $this->t->app;
        $db = $app->db();
        // the old machine: its data, backed up and copied away
        self::assertSame(0, $this->cli('backup:run', '--no-files')[0]);
        $this->t->clock->advance('+1 hour');
        [$code, $out] = $this->cli('backup:run');
        self::assertSame(0, $code, $out);
        mkdir($this->t->tmp . '/offsite');
        $file = '';
        foreach ($db->all("SELECT * FROM backup_runs WHERE status = 'verified' ORDER BY id") as $i => $row) {
            $file = $this->t->tmp . '/offsite/' . ['z-older', 'a-newer'][$i] . '.bluebak'; // names say nothing — the header decides
            copy($app->backups()->path($row), $file);
            $b = $row;
        }
        $users = (int) $db->scalar('SELECT COUNT(*) FROM users');
        self::assertGreaterThan(5, $users);

        [$code, $out] = $this->cli('restore:fresh', $file, '--as=' . TestApp::ADMIN, '--confirm=' . RestoreService::freshPhrase($file));
        self::assertSame(2, $code, 'a system in use is not a new installation');
        self::assertStringContainsString('Refusing', $out);

        // the new machine: just installed — one account, the installer
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        $db->run('DELETE FROM users WHERE username <> ?', [TestApp::ADMIN]);
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        $db->run("INSERT INTO system_info (info_key, info_value, updated_at) VALUES ('new_machine_marker', '1', UTC_TIMESTAMP(6))");

        // a file made with another APP_KEY is refused before anything happens
        $other = new BackupCipher((new Crypto('base64:' . base64_encode(str_repeat('z', 32))))->deriveKey('blue-backup-v1'), Crypto::backends()[0]);
        file_put_contents($this->t->tmp . '/plain', 'x');
        $other->encryptFile($this->t->tmp . '/plain', $this->t->tmp . '/other.bluebak', []);
        [$code, $out] = $this->cli('restore:fresh', $this->t->tmp . '/other.bluebak', '--as=' . TestApp::ADMIN);
        self::assertSame(2, $code);
        self::assertStringContainsString('APP_KEY', $out);

        [$code, $out] = $this->cliWithInput("RESTORE 000000\n", 'restore:fresh', $file, '--as=' . TestApp::ADMIN);
        self::assertSame(2, $code, 'wrong phrase');
        self::assertStringContainsString(RestoreService::freshPhrase($file), $out, 'the phrase is shown');
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM users'), 'nothing changed');
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM backup_runs WHERE kind = 'uploaded'"));

        [$code, $out] = $this->cliWithInput(RestoreService::freshPhrase($file) . "\n", 'restore:fresh', $this->t->tmp . '/offsite', '--as=' . TestApp::ADMIN);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Backup ' . $b['public_id'] . ' · made', $out, 'a folder → its newest copy');
        self::assertStringContainsString('Restored:', $out);
        self::assertSame($users, (int) $db->scalar('SELECT COUNT(*) FROM users'), 'the old machine\'s accounts are back');
        self::assertNotNull($db->scalar("SELECT id FROM users WHERE username = 'hr.demo1'"));
        self::assertNull($db->scalar("SELECT info_value FROM system_info WHERE info_key = 'new_machine_marker'"), 'the new machine\'s data was replaced');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM backup_runs WHERE kind = 'uploaded' AND status = 'verified'"), 'catalogue of this machine kept');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM backup_runs WHERE kind = 'pre_restore' AND status = 'verified'"), 'rollback plan taken');
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM restore_drills WHERE status = 'passed'"), 'drilled first');
        $audit = $db->one("SELECT * FROM audit_events WHERE action = 'backup.restored_fresh'") ?? [];
        self::assertNotSame([], $audit);
        self::assertArrayHasKey('actor_id', $audit);
        self::assertNull($audit['actor_id'], 'the installer account is not part of the restored data');
        self::assertStringContainsString('@cli', (string) $audit['diff_json']);
        self::assertFalse($app->install->isRestoring());

        [$code] = $this->cli('restore:fresh', $file, '--as=' . TestApp::ADMIN, '--confirm=' . RestoreService::freshPhrase($file));
        self::assertSame(2, $code, 'once restored the system is in use — no second fresh restore');
    }

    public function testWindowsPathsAreAbsolute(): void
    {
        self::assertSame('D:\\BlueBackup', $this->t->app->path('D:\\BlueBackup'));
        self::assertSame('C:/xampp/blue-docs', $this->t->app->path('C:/xampp/blue-docs'));
        self::assertSame($this->t->app->root . '/storage/backups', $this->t->app->path('storage/backups'));
    }
}
