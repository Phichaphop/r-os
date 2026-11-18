<?php

declare(strict_types=1);

namespace Blue\Core\Console;

use Blue\Core\App;
use Blue\Core\Arch\AiOutboundCheck;
use Blue\Core\Arch\ArchitectureCheck;
use Blue\Core\Arch\SecretScan;
use Blue\Core\Arch\TokenLint;
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
    ];

    public function __construct(private readonly App $app)
    {
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
                default => $this->help(),
            };
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
        fwrite(STDOUT, $s . PHP_EOL);
    }
}
