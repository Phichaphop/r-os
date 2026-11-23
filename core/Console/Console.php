<?php

declare(strict_types=1);

namespace Blue\Core\Console;

use Blue\Core\App;
use Blue\Core\Arch\AiOutboundCheck;
use Blue\Core\Arch\ArchitectureCheck;
use Blue\Core\Arch\SecretScan;
use Blue\Core\Arch\TokenLint;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Backup\RestoreService;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\ValidationException;
use Blue\Core\System\Doctor;
use Blue\Core\I18n\MessageSync;
use Blue\Core\Database\MigrationRunner;
use Blue\Core\Ui\AssetPublisher;
use Throwable;

/** `bin/blue <command>` — CLI for developers, CI and production cron (P0-02 §12). */
final class Console
{
    /** @var array<string, string> */
    private const COMMANDS = [
        'migrate' => 'Run pending migrations  [--approve-destructive] [--backup-confirmed]',
        'migrate:status' => 'List migrations and their state',
        'i18n:sync' => 'Copy seed translations into the database',
        'jobs:run' => 'Run due scheduled jobs (cron trigger, every minute)',
        'assets:publish' => 'Copy ui/ assets into public/assets/ui',
        'routes' => 'List HTTP routes',
        'check:ai' => 'TC-AI-00 / AIP-06: no AI provider calls outside core/AI',
        'check:secrets' => 'Scan the repository for committed secrets',
        'check:tokens' => 'No raw colours outside ui/tokens (P0-05 §8)',
        'check:arch' => 'Layering rules (P0-02 §4)',
        'check:all' => 'All static checks above',
        'doctor' => 'Is this installation fit for real data? (staging/production checklist, read-only)',
        'backup:run' => 'Make a backup now and verify it  [--no-files]',
        'backup:drill' => 'Restore drill of a backup into the separate database  [<backup id>] --as=<admin username>',
        'restore:fresh' => 'Disaster recovery onto a NEW installation  <file.bluebak> --as=<installer username> [--confirm="RESTORE XXXXXX"]',
    ];

    /** @var resource */
    private $out;

    /** @var resource */
    private $in;

    /**
     * @param resource|null $out
     * @param resource|null $in
     */
    public function __construct(private readonly App $app, $out = null, $in = null)
    {
        $this->out = $out ?? STDOUT;
        $this->in = $in ?? STDIN;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $cmd = $argv[1] ?? 'help';
        $flags = array_slice($argv, 2);
        try {
            return match ($cmd) {
                'migrate' => $this->migrate($flags),
                'migrate:status' => $this->migrateStatus(),
                'i18n:sync' => $this->i18nSync(),
                'jobs:run' => $this->jobsRun(),
                'assets:publish' => $this->assetsPublish(),
                'routes' => $this->routes(),
                'check:ai' => $this->report('TC-AI-00 AI outbound', (new AiOutboundCheck($this->app->root))->run()),
                'check:secrets' => $this->report('Secret scan', (new SecretScan($this->app->root))->run()),
                'check:tokens' => $this->report('Token lint', (new TokenLint($this->app->root))->run()),
                'check:arch' => $this->report('Architecture', (new ArchitectureCheck($this->app->root))->run()),
                'check:all' => $this->checkAll(),
                'doctor' => $this->doctor(),
                'backup:run' => $this->backupRun($flags),
                'backup:drill' => $this->backupDrill($flags),
                'restore:fresh' => $this->restoreFresh($flags),
                default => $this->help(),
            };
        } catch (ValidationException $e) {
            $this->line('ERROR: ' . implode('; ', array_map(fn (string $k): string => $this->app->translator()->get($k), $e->errors)));
            return 1;
        } catch (BusinessException $e) {
            $this->line('ERROR: ' . $this->app->translator()->get($e->messageKey, $e->params));
            return 1;
        } catch (Throwable $e) {
            $this->line('ERROR: ' . $e->getMessage());
            $this->app->logger->error('cli_failed', ['cmd' => $cmd, 'error' => $e->getMessage()]);
            return 1;
        }
    }

    /** @param list<string> $flags */
    private function migrate(array $flags): int
    {
        // DEC-16: outside dev/CI a migration requires a confirmed backup.
        if (!in_array($this->app->config->env(), ['local', 'ci'], true) && !in_array('--backup-confirmed', $flags, true)) {
            $this->line('Refusing: confirm a fresh backup first and re-run with --backup-confirmed (DEC-16).');
            return 2;
        }
        $runner = new MigrationRunner($this->app->db(), $this->app->migrationPaths(), $this->app->clock, $this->app->logger);
        $result = $runner->migrate(get_current_user() . '@cli', in_array('--approve-destructive', $flags, true));
        foreach ($result['applied'] as $v) {
            $this->line("  applied  {$v}");
        }
        $this->line(sprintf('Run %s: %d applied, %d already applied.', $result['run_id'], count($result['applied']), $result['skipped']));
        $sync = \Blue\Core\Install\PlatformSync::run($this->app->db(), $this->app->clock);
        $this->line(sprintf('Roles and permissions synced; %d config and %d workflow definitions added.', $sync['config'], $sync['workflow']));
        return $this->i18nSync();
    }

    private function migrateStatus(): int
    {
        $runner = new MigrationRunner($this->app->db(), $this->app->migrationPaths(), $this->app->clock, $this->app->logger);
        $drift = false;
        foreach ($runner->status() as $m) {
            $this->line(sprintf('  %-8s %-6s %s', $m['state'], $m['module'], $m['version']));
            $drift = $drift || $m['state'] === 'DRIFT';
        }
        return $drift ? 1 : 0;
    }

    private function i18nSync(): int
    {
        $n = (new MessageSync($this->app->db(), $this->app->clock))->sync($this->app->seedDirs(), ['en', 'th']);
        $this->line("Translations synced ({$n} changed).");
        return 0;
    }

    private function jobsRun(): int
    {
        $result = $this->app->scheduler()->runDue(
            'cron',
            $this->app->config->int('SCHEDULER_TIME_BUDGET_MS', 2000) * 10,
            $this->app->config->int('SCHEDULER_MAX_JOBS', 3) * 5
        );
        if ($result['skipped'] !== null) {
            $this->line('Skipped: ' . $result['skipped']);
        }
        foreach ($result['ran'] as $j) {
            $this->line(sprintf('  %-10s %s (%d ms)', $j['status'], $j['type'], $j['ms']));
        }
        return 0;
    }

    private function assetsPublish(): int
    {
        $n = (new AssetPublisher($this->app->root))->publish();
        $this->line("Published {$n} asset files to public/assets/ui.");
        return 0;
    }

    private function routes(): int
    {
        $router = (new \Blue\Core\Http\Kernel($this->app))->router;
        foreach ($router->list() as $r) {
            $this->line(sprintf('  %-5s %-40s %s', $r['method'], $r['pattern'], $r['name'] ?? ''));
        }
        return 0;
    }

    private function checkAll(): int
    {
        $code = 0;
        $code |= $this->report('TC-AI-00 AI outbound', (new AiOutboundCheck($this->app->root))->run());
        $code |= $this->report('Secret scan', (new SecretScan($this->app->root))->run());
        $code |= $this->report('Token lint', (new TokenLint($this->app->root))->run());
        $code |= $this->report('Architecture', (new ArchitectureCheck($this->app->root))->run());
        return $code;
    }

    /** @param list<string> $problems */
    private function report(string $name, array $problems): int
    {
        if ($problems === []) {
            $this->line("PASS  {$name}");
            return 0;
        }
        $this->line("FAIL  {$name}");
        foreach ($problems as $p) {
            $this->line("      - {$p}");
        }
        return 1;
    }

    private function doctor(): int
    {
        $fail = false;
        foreach ((new Doctor($this->app))->run() as $c) {
            $this->line(sprintf('%-5s %-18s %s', $c['status'], $c['check'], $c['detail']));
            $fail = $fail || $c['status'] === Doctor::FAIL;
        }
        $this->line($fail ? 'Result: FAIL — fix the FAIL lines before putting real data here.' : 'Result: no FAIL (read the WARN lines).');
        return $fail ? 1 : 0;
    }

    /** @param list<string> $flags */
    private function backupRun(array $flags): int
    {
        $b = $this->app->backups()->runToEnd($this->app->backups()->start('manual', null, !in_array('--no-files', $flags, true)));
        $this->line(sprintf('Backup %s: %s · %d tables, %d rows, %d files · %s bytes', $b['public_id'], $b['status'], (int) $b['db_tables'], (int) $b['db_rows'], (int) $b['file_count'], number_format((int) $b['size_bytes'])));
        if ($b['status'] !== 'verified') {
            $this->line('ERROR: ' . (string) ($b['error'] ?? 'not verified'));
            return 1;
        }
        $this->line('File:     ' . $this->app->backups()->path($b));
        $this->line('SHA-256:  ' . $b['sha256']);
        $this->line('Off-site: ' . ($b['offsite_status'] ?? 'not configured'));
        return 0;
    }

    /** @param list<string> $flags */
    private function backupDrill(array $flags): int
    {
        $actor = $this->actor($flags);
        $args = array_values(array_filter($flags, static fn (string $f): bool => !str_starts_with($f, '--')));
        $pid = $args[0] ?? (string) $this->app->db()->scalar("SELECT public_id FROM backup_runs WHERE status IN ('verified','completed') ORDER BY finished_at DESC LIMIT 1");
        if ($pid === '') {
            $this->line('ERROR: no verified backup — run php bin/blue backup:run first');
            return 1;
        }
        $d = $this->app->restores()->drillRow($this->app->restores()->drill(new Authorizer($this->app->db(), $this->app->clock, $actor), $pid));
        $this->line(sprintf('Drill %s of backup %s: %s · %d tables, %d rows, %d files, key %s · %d ms', $d['public_id'], $pid, strtoupper((string) $d['status']), (int) $d['tables_checked'], (int) $d['rows_checked'], (int) $d['files_checked'], (string) $d['key_check'], (int) $d['duration_ms']));
        foreach ($d['report'] as $c) {
            if (!$c['ok']) {
                $this->line('  failed: ' . $c['check'] . ' — ' . $c['detail']);
            }
        }
        return $d['status'] === 'passed' ? 0 : 1;
    }

    /** @param list<string> $flags */
    private function restoreFresh(array $flags): int
    {
        $actor = $this->actor($flags);
        $args = array_values(array_filter($flags, static fn (string $f): bool => !str_starts_with($f, '--')));
        $file = $args[0] ?? '';
        if ($file !== '' && is_dir($file)) {
            $file = $this->newestBackupIn($file);
        }
        if ($file === '' || !is_file($file)) {
            $this->line('Usage: php bin/blue restore:fresh <file.bluebak | folder of copies> --as=<installer username> [--confirm="RESTORE XXXXXX"]');
            return 2;
        }
        $restores = $this->app->restores();
        $blocker = $restores->freshBlocker();
        if ($blocker !== null) {
            $this->line('Refusing: ' . $this->app->translator()->get($blocker));
            return 2;
        }
        $header = \Blue\Core\Backup\BackupCipher::header($file);
        $mine = $this->app->backups()->cipher()->fingerprint();
        if (($header['key'] ?? '') !== $mine) {
            $this->line(sprintf('Refusing: the file was made with key %s, this installation has %s — put the original APP_KEY in config/env.php first.', (string) ($header['key'] ?? '?'), $mine));
            return 2;
        }
        $this->line(sprintf('Backup %s · made %s UTC · release %s', (string) ($header['backup_id'] ?? '?'), (string) ($header['created_at'] ?? '?'), (string) ($header['release'] ?? '?')));
        $this->line('File:   ' . $file);
        $phrase = RestoreService::freshPhrase($file);
        $given = $this->option($flags, 'confirm');
        if ($given === null) {
            $this->line('This REPLACES everything in this installation with the backup (a drill runs first; nothing changes if it fails).');
            $this->line('Type the phrase to continue: ' . $phrase);
            $given = trim((string) fgets($this->in));
        }
        if ($given !== $phrase) {
            $this->line('Stopped: the phrase does not match — nothing was changed.');
            return 2;
        }
        $this->line('Importing, checking and drilling the file…');
        $r = $restores->freshRestore($actor, $file, $given, get_current_user() . '@cli');
        if (!$r['ok']) {
            $this->line('FAILED and rolled back: ' . (string) ($r['error'] ?? ''));
            return 1;
        }
        $this->line(sprintf('Restored: %d tables, %d rows, %d files in %d ms (drill %s passed). Backup release: %s', (int) $r['tables'], (int) $r['rows'], (int) $r['files'], (int) $r['ms'], $r['drill'], $r['backup_release']));
        $this->line($r['needs_migrate'] ? 'Next: php bin/blue migrate --backup-confirmed  (the backup is from an older release), then php bin/blue doctor' : 'Next: php bin/blue doctor — then sign in with an account from the restored data.');
        return 0;
    }

    /** The newest .bluebak in a folder (the off-site copies), by the creation time in its header. */
    private function newestBackupIn(string $dir): string
    {
        $best = '';
        $bestAt = '';
        foreach (glob(rtrim($dir, '/\\') . '/*.bluebak') ?: [] as $f) {
            try {
                $at = (string) (\Blue\Core\Backup\BackupCipher::header($f)['created_at'] ?? '');
            } catch (Throwable) {
                continue;
            }
            if ($at > $bestAt) {
                [$best, $bestAt] = [$f, $at];
            }
        }
        return $best;
    }

    /** @param list<string> $flags */
    private function actor(array $flags): int
    {
        $name = $this->option($flags, 'as') ?? '';
        $id = $name === '' ? null : $this->app->db()->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [$name]);
        if ($id === null) {
            throw new \RuntimeException('name an active administrator with --as=<username>');
        }
        return (int) $id;
    }

    /** @param list<string> $flags */
    private function option(array $flags, string $name): ?string
    {
        foreach ($flags as $f) {
            if (str_starts_with($f, '--' . $name . '=')) {
                return substr($f, strlen($name) + 3);
            }
        }
        return null;
    }

    private function help(): int
    {
        $this->line('Blue OS CLI — usage: php bin/blue <command>');
        foreach (self::COMMANDS as $c => $d) {
            $this->line(sprintf('  %-16s %s', $c, $d));
        }
        return 0;
    }

    private function line(string $s): void
    {
        fwrite($this->out, $s . PHP_EOL);
    }
}
