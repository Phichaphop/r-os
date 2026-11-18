<?php

declare(strict_types=1);

namespace Blue\Core\Backup;

use PDO;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Config\AppConfig;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Files\ZipStream;
use Blue\Core\Log\Logger;
use Blue\Core\Notify\Notifier;
use Blue\Core\Registry\ConfigRegistry;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;
use Blue\Core\Support\Ulid;
use RuntimeException;
use Throwable;

/**
 * M48 Backup (DEC-39, P0-06 §3, 8D): encrypted backups of the database and the private document storage.
 *
 * A backup advances in steps so it also works where a request may only run for seconds (shared hosting):
 *   db     — one gzip SQL file per table (read inside a consistent snapshot; several steps → "stepwise", shown as such)
 *   pack   — manifest (rows, SHA-256 per part, document files) + parts into one archive
 *   seal   — encrypt the archive (ROSBAK1, key from APP_KEY) → storage/backups/<key>.bluebak; plaintext removed
 *   verify — decrypt again, check every CRC and SHA-256 against the manifest → "verified"
 * then an optional copy to an off-site folder (e.g. the office NAS — BACKUP_OFFSITE_PATH), checked by hash.
 *
 * Downloads are for System Admins only, after re-authentication, audited; never by e-mail or public link.
 * Retention keeps the newest verified backup, those still needed by a restore request and the monthly ones.
 */
final class BackupService
{
    public const LOCK = 'blue.backup';
    /** Data of these tables is not backed up (structure only): live sessions are worthless after a restore. */
    public const STRUCTURE_ONLY = ['user_sessions'];

    private string $dir;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly Crypto $crypto,
        private readonly AppConfig $config,
        private readonly ConfigRegistry $registry,
        private readonly Notifier $notifier,
        private readonly Logger $logger,
        private readonly string $documentsPath,
        string $backupPath,
        private readonly string $release,
    ) {
        $this->dir = rtrim($backupPath, '/');
    }

    public function cipher(): BackupCipher
    {
        return new BackupCipher($this->crypto->deriveKey('blue-backup-v1'), $this->crypto->backend());
    }

    // ---- settings -----------------------------------------------------------------------------------

    public function setting(string $key, int $fallback): int
    {
        $v = $this->registry->resolveOrNull('backup.' . $key)?->value;
        return is_int($v) ? $v : $fallback;
    }

    public function includeFilesDefault(): bool
    {
        return ($this->registry->resolveOrNull('backup.include_files')?->value ?? 'yes') !== 'no';
    }

    public function offsitePath(): string
    {
        return rtrim($this->config->string('BACKUP_OFFSITE_PATH', ''), '/');
    }

    // ---- reading ------------------------------------------------------------------------------------

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function list(Authorizer $az, int $page, int $per): array
    {
        $this->require($az, 'core.backup.view');
        return [
            'total' => (int) $this->db->scalar('SELECT COUNT(*) FROM backup_runs'),
            'rows' => $this->db->all(
                'SELECT b.*, u.display_name AS requested_name FROM backup_runs b LEFT JOIN users u ON u.id = b.requested_by
                  ORDER BY b.id DESC LIMIT ' . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per)
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function get(Authorizer $az, string $pid): array
    {
        $this->require($az, 'core.backup.view');
        $b = $this->row($pid);
        $b['manifest'] = $b['manifest_json'] !== null ? (array) json_decode((string) $b['manifest_json'], true) : [];
        $b['drills'] = $this->db->all('SELECT d.*, u.display_name AS performed_name FROM restore_drills d LEFT JOIN users u ON u.id = d.performed_by WHERE d.backup_run_id = ? ORDER BY d.id DESC', [$b['id']]);
        $b['requested_name'] = $b['requested_by'] !== null ? $this->db->scalar('SELECT display_name FROM users WHERE id = ?', [$b['requested_by']]) : null;
        return $b;
    }

    /** @return array<string, mixed> */
    public function row(string $pid): array
    {
        return $this->db->one('SELECT * FROM backup_runs WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /**
     * Health for the status pages and alerts: newest verified backup and whether it is overdue.
     *
     * @return array{last: ?array<string, mixed>, age_hours: ?float, overdue: bool, running: ?array<string, mixed>, failed_recent: int, last_drill: ?array<string, mixed>, max_age_hours: int}
     */
    public function health(): array
    {
        $last = $this->db->one("SELECT * FROM backup_runs WHERE status = 'verified' ORDER BY finished_at DESC LIMIT 1");
        $age = $last !== null ? ($this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $last['finished_at'], new \DateTimeZone('UTC')))->getTimestamp()) / 3600 : null;
        $max = $this->setting('max_age_hours', 26);
        return [
            'last' => $last,
            'age_hours' => $age,
            'overdue' => $age === null || $age > $max,
            'running' => $this->db->one("SELECT * FROM backup_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1"),
            'failed_recent' => (int) $this->db->scalar("SELECT COUNT(*) FROM backup_runs WHERE status = 'failed' AND started_at >= ?", [$this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s.u')]),
            'last_drill' => $this->db->one("SELECT * FROM restore_drills WHERE status IN ('passed','failed','blocked') ORDER BY id DESC LIMIT 1"),
            'max_age_hours' => $max,
        ];
    }

    // ---- running -------------------------------------------------------------------------------------

    /** Starts a backup (one at a time). */
    public function start(string $kind, ?int $actorId, ?bool $includeFiles = null): string
    {
        $running = $this->db->scalar("SELECT public_id FROM backup_runs WHERE status = 'running' LIMIT 1");
        if ($running !== null) {
            throw new BusinessException('backup.error.running', [], 409);
        }
        $this->ensureDirs();
        $pid = Ulid::generate($this->clock);
        $now = $this->now();
        $files = $includeFiles ?? $this->includeFilesDefault();
        $this->db->run(
            "INSERT INTO backup_runs (public_id, created_at, created_by, updated_at, updated_by, kind, include_files, status, phase, cursor_json, started_at, requested_by, app_version, offsite_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'running', 'db', ?, ?, ?, ?, ?)",
            [$pid, $now, $actorId, $now, $actorId, $kind, $files ? 1 : 0, json_encode(['table' => 0, 'cursor' => null, 'tables' => []]), $now, $actorId, $this->release, 'not_configured']
        );
        $this->audit->record('backup.started', 'success', 'backup', $pid, null, $kind, ['include_files' => $files], $actorId);
        return $pid;
    }

    /** System Admin presses "back up now": starts and runs as far as the time budget allows. */
    public function startManual(Authorizer $az, bool $includeFiles): string
    {
        $this->require($az, 'core.backup.run');
        $pid = $this->start('manual', $az->userId, $includeFiles);
        $this->step($pid, (float) $this->setting('step_seconds', 20));
        return $pid;
    }

    /**
     * Runs a backup to the end (CLI / before an update / before a restore).
     *
     * @return array<string, mixed>
     */
    public function runToEnd(string $pid): array
    {
        $guard = 0;
        do {
            $state = $this->step($pid, 3600.0);
        } while ($state === 'running' && ++$guard < 10000);
        return $this->row($pid);
    }

    /**
     * Advances a running backup for about $seconds. Returns the status afterwards.
     */
    public function step(string $pid, float $seconds): string
    {
        if (!$this->db->acquireLock(self::LOCK, 0)) {
            return 'running'; // another request is working on it
        }
        $deadline = microtime(true) + max(1.0, $seconds);
        try {
            $b = $this->row($pid);
            if ($b['status'] !== 'running') {
                return (string) $b['status'];
            }
            $this->db->run('UPDATE backup_runs SET steps = steps + 1, updated_at = ? WHERE id = ?', [$this->now(), $b['id']]);
            try {
                $phase = (string) $b['phase'];
                if ($phase === 'db') {
                    $phase = $this->stepDb($b, $deadline);
                }
                if ($phase === 'pack' && microtime(true) < $deadline) {
                    $phase = $this->stepPack($this->row($pid));
                }
                if ($phase === 'seal' && microtime(true) < $deadline) {
                    $phase = $this->stepSeal($this->row($pid));
                }
                if ($phase === 'verify' && microtime(true) < $deadline) {
                    $phase = $this->stepVerify($this->row($pid));
                }
                return $phase === 'done' ? 'verified' : 'running';
            } catch (Throwable $e) {
                $this->fail($b, $e);
                return 'failed';
            }
        } finally {
            $this->db->releaseLock(self::LOCK);
        }
    }

    /** @param array<string, mixed> $b */
    private function stepDb(array $b, float $deadline): string
    {
        $work = $this->workDir((string) $b['public_id']);
        $cur = (array) json_decode((string) $b['cursor_json'], true);
        $dump = $this->dumpConnection();
        // a consistent snapshot for everything read in this step
        $dump->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $dump->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $tables = SqlDumper::tables($dump);
        $i = (int) ($cur['table'] ?? 0);
        $done = (array) ($cur['tables'] ?? []);
        $wholeInThisStep = $i === 0 && ($cur['cursor'] ?? null) === null; // every table read inside ONE snapshot
        try {
            while ($i < count($tables)) {
                $t = $tables[$i];
                $file = sprintf('%s/db_%04d_%s.sql.gz', $work, $i, $t);
                $res = SqlDumper::dumpTable($dump, $t, $file, !in_array($t, self::STRUCTURE_ONLY, true), $cur['cursor'] ?? null, $deadline);
                $done[$t]['rows'] = (int) ($done[$t]['rows'] ?? 0) + $res['rows'];
                if ($res['cursor'] !== null) {
                    $cur = ['table' => $i, 'cursor' => $res['cursor'], 'tables' => $done];
                    $this->saveCursor((int) $b['id'], $cur);
                    return 'db';
                }
                $done[$t]['file'] = basename($file);
                $done[$t]['data'] = !in_array($t, self::STRUCTURE_ONLY, true);
                $i++;
                $cur = ['table' => $i, 'cursor' => null, 'tables' => $done];
                if (microtime(true) >= $deadline && $i < count($tables)) {
                    $this->saveCursor((int) $b['id'], $cur);
                    return 'db';
                }
            }
            SqlDumper::dumpTriggers($dump, $work . '/db_9999_triggers.sql.gz');
            $schema = (string) ($dump->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() ?: '');
        } finally {
            $dump->exec('COMMIT');
        }
        $cur['schema'] = $schema;
        $cur['consistency'] = $wholeInThisStep ? 'snapshot' : 'stepwise';
        $this->saveCursor((int) $b['id'], $cur);
        $this->db->run("UPDATE backup_runs SET phase = 'pack', schema_version = ?, consistency = ? WHERE id = ?", [$schema, $cur['consistency'], $b['id']]);
        return 'pack';
    }

    /** @param array<string, mixed> $b */
    private function stepPack(array $b): string
    {
        $pid = (string) $b['public_id'];
        $work = $this->workDir($pid);
        $cur = (array) json_decode((string) $b['cursor_json'], true);
        $tables = [];
        $rows = 0;
        foreach ((array) ($cur['tables'] ?? []) as $name => $t) {
            $path = $work . '/' . $t['file'];
            $tables[] = ['name' => (string) $name, 'file' => 'db/' . $t['file'], 'rows' => (int) $t['rows'], 'data' => (bool) $t['data'],
                'sha256' => (string) hash_file('sha256', $path), 'data_sha256' => SqlDumper::dataHash($path)];
            $rows += (int) $t['rows'];
        }
        $files = [];
        $bytes = 0;
        if ((int) $b['include_files'] === 1 && is_dir($this->documentsPath)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->documentsPath, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                $rel = substr($f->getPathname(), strlen($this->documentsPath) + 1);
                if (!$f->isFile() || !preg_match('#^[0-9a-f]{2}/[0-9a-f]{40}$#', $rel)) {
                    continue; // only stored documents (xx/<40 hex>), nothing else from that folder
                }
                $files[] = ['path' => $rel, 'size' => (int) $f->getSize(), 'sha256' => (string) hash_file('sha256', $f->getPathname())];
                $bytes += (int) $f->getSize();
            }
            usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        }
        $manifest = [
            'format' => 'ROSBAK1', 'backup_id' => $pid, 'created_at' => $b['started_at'], 'release' => $this->release, 'schema' => $cur['schema'] ?? '',
            'consistency' => $cur['consistency'] ?? 'stepwise', 'key_fingerprint' => $this->cipher()->fingerprint(),
            'db' => ['tables' => $tables, 'triggers' => 'db/db_9999_triggers.sql.gz', 'triggers_sha256' => (string) hash_file('sha256', $work . '/db_9999_triggers.sql.gz')],
            'files' => $files, 'php' => PHP_VERSION, 'crypto' => $this->crypto->backend(),
        ];
        $zipPath = $work . '/backup.zip';
        $zip = ZipStream::create($zipPath);
        $zip->addString('manifest.json', (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        foreach ($tables as $t) {
            $zip->addFile($t['file'], $work . '/' . basename($t['file']));
        }
        $zip->addFile('db/db_9999_triggers.sql.gz', $work . '/db_9999_triggers.sql.gz');
        foreach ($files as $f) {
            $zip->addFile('files/' . $f['path'], $this->documentsPath . '/' . $f['path']);
        }
        $zip->finish();
        $summary = $manifest;
        $summary['files'] = count($files);
        $this->db->run(
            "UPDATE backup_runs SET phase = 'seal', db_tables = ?, db_rows = ?, file_count = ?, file_bytes = ?, manifest_json = ?, updated_at = ? WHERE id = ?",
            [count($tables), $rows, count($files), $bytes, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->now(), $b['id']]
        );
        foreach (glob($work . '/db_*.sql.gz') ?: [] as $part) {
            @unlink($part);
        }
        return 'seal';
    }

    /** @param array<string, mixed> $b */
    private function stepSeal(array $b): string
    {
        $pid = (string) $b['public_id'];
        $work = $this->workDir($pid);
        $key = bin2hex(random_bytes(20));
        $target = $this->dir . '/' . $key . '.bluebak';
        $cipher = $this->cipher();
        $cipher->encryptFile($work . '/backup.zip', $target, ['backup_id' => $pid, 'created_at' => (string) $b['started_at'], 'release' => $this->release]);
        @unlink($work . '/backup.zip');
        $this->db->run(
            "UPDATE backup_runs SET phase = 'verify', file_key = ?, size_bytes = ?, sha256 = ?, key_fingerprint = ?, updated_at = ? WHERE id = ?",
            [$key, filesize($target), hash_file('sha256', $target), $cipher->fingerprint(), $this->now(), $b['id']]
        );
        return 'verify';
    }

    /** @param array<string, mixed> $b */
    private function stepVerify(array $b): string
    {
        $problems = $this->verifyFile($this->path($b), (string) $b['sha256'], (string) $b['public_id']);
        if ($problems !== []) {
            throw new RuntimeException('Verification failed: ' . implode('; ', array_slice($problems, 0, 3)));
        }
        $now = $this->now();
        $this->db->run(
            "UPDATE backup_runs SET status = 'verified', phase = 'done', cursor_json = NULL, finished_at = ?, verified_at = ?, updated_at = ? WHERE id = ?",
            [$now, $now, $now, $b['id']]
        );
        $this->removeWork((string) $b['public_id']);
        $this->audit->record('backup.verified', 'success', 'backup', (string) $b['public_id'], null, null, [
            'size' => (int) $b['size_bytes'], 'sha256' => $b['sha256'], 'tables' => (int) $b['db_tables'], 'rows' => (int) $b['db_rows'], 'files' => (int) $b['file_count'],
        ], $b['requested_by'] !== null ? (int) $b['requested_by'] : null);
        $this->copyOffsite($this->row((string) $b['public_id']));
        return 'done';
    }

    /**
     * Decrypts a backup to a temporary file and checks hash, CRCs and every manifest SHA-256.
     *
     * @return list<string> problems (empty = good)
     */
    public function verifyFile(string $path, string $expectedSha, string $label): array
    {
        if (!is_file($path)) {
            return ['file missing'];
        }
        if ($expectedSha !== '' && !hash_equals($expectedSha, (string) hash_file('sha256', $path))) {
            return ['file hash differs from the catalogue'];
        }
        $tmp = $this->tempFile('verify-' . $label);
        try {
            $this->cipher()->decryptFile($path, $tmp);
            $zip = ZipStream::open($tmp);
            $manifest = (array) json_decode($zip->read('manifest.json'), true);
            $problems = [];
            $check = $this->tempFile('part-' . $label);
            foreach ((array) ($manifest['db']['tables'] ?? []) as $t) {
                if (!$zip->has((string) $t['file']) || $zip->extractTo((string) $t['file'], $check) !== $t['sha256']) {
                    $problems[] = 'table ' . $t['name'];
                }
            }
            if ($zip->extractTo((string) ($manifest['db']['triggers'] ?? ''), $check) !== ($manifest['db']['triggers_sha256'] ?? '')) {
                $problems[] = 'triggers';
            }
            foreach ((array) ($manifest['files'] ?? []) as $f) {
                if (!$zip->has('files/' . $f['path']) || $zip->extractTo('files/' . $f['path'], $check) !== $f['sha256']) {
                    $problems[] = 'file ' . $f['path'];
                }
            }
            @unlink($check);
            return $problems;
        } catch (KeyMismatch) {
            return ['encrypted with another key'];
        } catch (Throwable $e) {
            return [mb_substr($e->getMessage(), 0, 200)];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Re-checks a stored backup on demand.
     *
     * @return list<string>
     */
    public function recheck(Authorizer $az, string $pid): array
    {
        $this->require($az, 'core.backup.run');
        $b = $this->row($pid);
        if (!in_array($b['status'], ['verified', 'completed'], true)) {
            throw new BusinessException('backup.error.not_ready', [], 409);
        }
        $problems = $this->verifyFile($this->path($b), (string) $b['sha256'], $pid);
        $this->audit->record('backup.rechecked', $problems === [] ? 'success' : 'failure', 'backup', $pid, null, null, ['problems' => array_slice($problems, 0, 5)]);
        return $problems;
    }

    /** @param array<string, mixed> $b */
    private function fail(array $b, Throwable $e): void
    {
        $msg = mb_substr($e::class === RuntimeException::class ? $e->getMessage() : $e::class . ': ' . $e->getMessage(), 0, 480);
        $this->db->run("UPDATE backup_runs SET status = 'failed', error = ?, finished_at = ?, updated_at = ? WHERE id = ?", [$msg, $this->now(), $this->now(), $b['id']]);
        $this->removeWork((string) $b['public_id']);
        if ($b['file_key'] !== null) {
            @unlink($this->path($b));
        }
        $this->logger->error('backup_failed', ['backup' => $b['public_id'], 'error' => $msg]);
        $this->audit->record('backup.failed', 'failure', 'backup', (string) $b['public_id'], null, $msg);
        $this->alertAdmins('notify.backup.failed', 'backup-failed-' . $b['public_id'], '/admin/backups/' . $b['public_id']);
    }

    // ---- scheduled work --------------------------------------------------------------------------------

    /**
     * Background job (every minute): continue a running backup; start a scheduled one when due; alert when overdue.
     * Uses at most the time the scheduler gives it.
     */
    public function tick(int $timeLeftMs): string
    {
        $budget = min((float) $this->setting('step_seconds', 20), max(1.0, $timeLeftMs / 1000));
        $running = $this->db->scalar("SELECT public_id FROM backup_runs WHERE status = 'running' ORDER BY id LIMIT 1");
        if ($running !== null) {
            $this->step((string) $running, $budget);
            return 'stepped';
        }
        $last = $this->db->scalar("SELECT MAX(started_at) FROM backup_runs WHERE kind IN ('scheduled','manual','pre_update') AND status IN ('verified','completed')");
        $interval = $this->setting('interval_hours', 24);
        $due = $last === null || (new \DateTimeImmutable((string) $last, new \DateTimeZone('UTC'))) <= $this->clock->now()->modify('-' . $interval . ' hours');
        $recentFail = $this->db->scalar("SELECT id FROM backup_runs WHERE status = 'failed' AND kind = 'scheduled' AND started_at >= ?", [$this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s.u')]);
        $out = 'idle';
        if ($due && $recentFail === null) {
            $pid = $this->start('scheduled', null);
            $this->step($pid, $budget);
            $out = 'started';
        }
        $h = $this->health();
        if ($h['overdue'] && $h['running'] === null) {
            $this->alertAdmins('notify.backup.overdue', 'backup-overdue-' . $this->clock->now()->format('Ymd'), '/admin/backups');
        }
        return $out;
    }

    /**
     * Retention (technical defaults DEC-39: daily 35 days, monthly 12 months): removes old backup files, keeps the catalogue
     * row with the reason. Never removes the newest verified backup or one referenced by an open restore request.
     */
    public function prune(?int $actorId = null): int
    {
        $keepDays = $this->setting('keep_days', 35);
        $keepMonths = $this->setting('keep_monthly', 12);
        $cut = $this->clock->now()->modify('-' . $keepDays . ' days')->format('Y-m-d H:i:s.u');
        $newest = (int) ($this->db->scalar("SELECT id FROM backup_runs WHERE status = 'verified' ORDER BY finished_at DESC LIMIT 1") ?? 0);
        $monthly = [];
        foreach ($this->db->all("SELECT id, started_at FROM backup_runs WHERE status = 'verified' ORDER BY started_at") as $r) {
            $m = substr((string) $r['started_at'], 0, 7);
            $monthly[$m] ??= (int) $r['id']; // first verified backup of each month
        }
        $keepMonthly = $keepMonths > 0 ? array_slice(array_values($monthly), -$keepMonths) : [];
        $n = 0;
        foreach ($this->db->all("SELECT * FROM backup_runs WHERE status IN ('verified','completed','failed') AND started_at < ?", [$cut]) as $b) {
            $id = (int) $b['id'];
            $needed = (int) $this->db->scalar("SELECT COUNT(*) FROM restore_requests WHERE backup_run_id = ? AND status IN ('in_review','returned','approved','executing')", [$id]) > 0;
            if ($id === $newest || in_array($id, $keepMonthly, true) || $needed || ($b['keep_until'] !== null && (string) $b['keep_until'] >= $this->clock->now()->format('Y-m-d'))) {
                continue;
            }
            $this->deleteFile($b, 'retention: older than ' . $keepDays . ' days', $actorId);
            $n++;
        }
        return $n;
    }

    /** System Admin deletes one backup file (reason kept). The newest verified backup cannot be deleted. */
    public function delete(Authorizer $az, string $pid, string $reason): void
    {
        $this->require($az, 'core.backup.run');
        $b = $this->row($pid);
        if (trim($reason) === '') {
            throw new BusinessException('iam.error.reason_required', [], 422);
        }
        $newest = (int) ($this->db->scalar("SELECT id FROM backup_runs WHERE status = 'verified' ORDER BY finished_at DESC LIMIT 1") ?? 0);
        if ((int) $b['id'] === $newest || $b['status'] === 'running' || $b['status'] === 'deleted') {
            throw new BusinessException('backup.error.cannot_delete', [], 409);
        }
        $this->deleteFile($b, $reason, $az->userId);
    }

    /** @param array<string, mixed> $b */
    private function deleteFile(array $b, string $reason, ?int $actorId): void
    {
        if ($b['file_key'] !== null) {
            @unlink($this->path($b));
        }
        $now = $this->now();
        $this->db->run("UPDATE backup_runs SET status = 'deleted', deleted_at = ?, deleted_by = ?, delete_reason = ?, updated_at = ? WHERE id = ?", [$now, $actorId, mb_substr($reason, 0, 255), $now, $b['id']]);
        $this->audit->record('backup.deleted', 'success', 'backup', (string) $b['public_id'], null, $reason, ['sha256' => $b['sha256']], $actorId);
    }

    // ---- files in and out --------------------------------------------------------------------------------

    /** @return array{path: string, filename: string} the encrypted file (caller re-authenticated) */
    public function download(Authorizer $az, string $pid): array
    {
        $this->require($az, 'core.backup.download');
        $b = $this->row($pid);
        if (!in_array($b['status'], ['verified', 'completed'], true) || !is_file($this->path($b))) {
            throw new BusinessException('backup.error.not_ready', [], 409);
        }
        $this->db->run('UPDATE backup_runs SET download_count = download_count + 1 WHERE id = ?', [$b['id']]);
        $this->audit->record('backup.downloaded', 'success', 'backup', $pid, null, null, ['sha256' => $b['sha256']]);
        return ['path' => $this->path($b), 'filename' => 'blue-backup-' . substr(str_replace([' ', ':', '-'], '', (string) $b['started_at']), 0, 12) . '.bluebak'];
    }

    /**
     * Registers a backup file brought from elsewhere (off-site copy, another server) — e.g. after a server loss.
     * It must open with this installation's APP_KEY and pass the full check.
     */
    public function importFile(int $actorId, string $sourcePath): string
    {
        $this->ensureDirs();
        $header = BackupCipher::header($sourcePath);
        if (($header['key'] ?? '') !== $this->cipher()->fingerprint()) {
            throw new BusinessException('backup.error.key_mismatch', ['fingerprint' => (string) ($header['key'] ?? '?')], 422);
        }
        $key = bin2hex(random_bytes(20));
        $target = $this->dir . '/' . $key . '.bluebak';
        if (!copy($sourcePath, $target)) {
            throw new RuntimeException('Cannot store the backup file');
        }
        $pid = Ulid::generate($this->clock);
        $problems = $this->verifyFile($target, '', $pid);
        if ($problems !== []) {
            @unlink($target);
            throw new BusinessException('backup.error.import_failed', ['detail' => implode('; ', array_slice($problems, 0, 2))], 422);
        }
        $tmp = $this->tempFile('manifest-' . $pid);
        $this->cipher()->decryptFile($target, $tmp);
        $manifest = (array) json_decode(ZipStream::open($tmp)->read('manifest.json'), true);
        @unlink($tmp);
        $tables = (array) ($manifest['db']['tables'] ?? []);
        $summary = $manifest;
        $summary['files'] = count((array) ($manifest['files'] ?? []));
        $now = $this->now();
        $this->db->run(
            "INSERT INTO backup_runs (public_id, created_at, created_by, updated_at, updated_by, kind, include_files, status, phase, file_key, size_bytes, sha256, key_fingerprint,
                consistency, db_tables, db_rows, file_count, file_bytes, app_version, schema_version, manifest_json, started_at, finished_at, verified_at, requested_by)
             VALUES (?, ?, ?, ?, ?, 'uploaded', ?, 'verified', 'done', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$pid, $now, $actorId, $now, $actorId, $summary['files'] > 0 ? 1 : 0, $key, filesize($target), hash_file('sha256', $target), $header['key'],
                in_array($manifest['consistency'] ?? '', ['snapshot', 'stepwise'], true) ? $manifest['consistency'] : null, count($tables),
                array_sum(array_map(static fn ($t): int => (int) ($t['rows'] ?? 0), $tables)), $summary['files'],
                array_sum(array_map(static fn ($f): int => (int) ($f['size'] ?? 0), (array) ($manifest['files'] ?? []))),
                mb_substr((string) ($manifest['release'] ?? ''), 0, 40), mb_substr((string) ($manifest['schema'] ?? ''), 0, 40),
                json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::dbTime((string) ($manifest['created_at'] ?? $now)), $now, $now, $actorId]
        );
        $this->audit->record('backup.imported', 'success', 'backup', $pid, null, null, ['sha256' => hash_file('sha256', $target), 'original' => $manifest['backup_id'] ?? null], $actorId);
        return $pid;
    }

    /** @param array<string, mixed> $b */
    private function copyOffsite(array $b): void
    {
        $dest = $this->offsitePath();
        if ($dest === '') {
            return;
        }
        $status = 'failed';
        $detail = null;
        try {
            if (!is_dir($dest) || !is_writable($dest)) {
                throw new RuntimeException('off-site folder not reachable');
            }
            $target = $dest . '/' . basename($this->path($b));
            if (!copy($this->path($b), $target) || hash_file('sha256', $target) !== $b['sha256']) {
                throw new RuntimeException('copy differs');
            }
            $status = 'copied';
            $detail = basename($target);
        } catch (Throwable $e) {
            $detail = mb_substr($e->getMessage(), 0, 250);
            $this->alertAdmins('notify.backup.offsite_failed', 'backup-offsite-' . $b['public_id'], '/admin/backups/' . $b['public_id']);
        }
        $this->db->run('UPDATE backup_runs SET offsite_status = ?, offsite_detail = ? WHERE id = ?', [$status, $detail, $b['id']]);
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /** @param array<string, mixed> $b */
    public function path(array $b): string
    {
        if (!preg_match('/^[0-9a-f]{40}$/', (string) $b['file_key'])) {
            return $this->dir . '/missing';
        }
        return $this->dir . '/' . $b['file_key'] . '.bluebak';
    }

    public function tempFile(string $label): string
    {
        $this->ensureDirs();
        return $this->dir . '/work/' . preg_replace('/[^A-Za-z0-9_-]/', '', $label) . '-' . bin2hex(random_bytes(4)) . '.tmp';
    }

    public function workDir(string $pid): string
    {
        $d = $this->dir . '/work/' . preg_replace('/[^0-9A-Z]/', '', $pid);
        if (!is_dir($d) && !mkdir($d, 0700, true) && !is_dir($d)) {
            throw new RuntimeException('Cannot create work folder');
        }
        return $d;
    }

    private function removeWork(string $pid): void
    {
        $d = $this->dir . '/work/' . preg_replace('/[^0-9A-Z]/', '', $pid);
        foreach (glob($d . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($d);
    }

    private function ensureDirs(): void
    {
        foreach ([$this->dir, $this->dir . '/work'] as $d) {
            if (!is_dir($d) && !mkdir($d, 0700, true) && !is_dir($d)) {
                throw new RuntimeException('Cannot create the backup folder');
            }
        }
        if (!is_file($this->dir . '/.htaccess')) {
            @file_put_contents($this->dir . '/.htaccess', "Require all denied\n");
        }
    }

    /** A second connection for reading the snapshot, so progress updates on the main connection are not inside it. */
    private function dumpConnection(): PDO
    {
        $pdo = Connection::fromConfig($this->config)->pdo();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        return $pdo;
    }

    /** @param array<string, mixed> $cur */
    private function saveCursor(int $id, array $cur): void
    {
        $this->db->run('UPDATE backup_runs SET cursor_json = ?, updated_at = ? WHERE id = ?', [json_encode($cur, JSON_UNESCAPED_SLASHES), $this->now(), $id]);
    }

    private function alertAdmins(string $titleKey, string $dedupe, string $link): void
    {
        $today = $this->clock->now()->format('Y-m-d');
        $users = $this->db->all(
            "SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id
              WHERE a.status = 'active' AND u.status = 'active' AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?)
                AND a.role_id IN (SELECT role_id FROM role_permissions WHERE permission_code = 'core.backup.run')",
            [$today, $today]
        );
        foreach ($users as $u) {
            $this->notifier->notify((int) $u['user_id'], 'backup.alert', $titleKey, [], $link, $dedupe);
        }
    }

    private function require(Authorizer $az, string $perm): void
    {
        if (!$az->scope($perm)->all) {
            throw HttpException::forbidden();
        }
    }

    private static function dbTime(string $v): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $v) ? substr($v, 0, 26) : gmdate('Y-m-d H:i:s');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
