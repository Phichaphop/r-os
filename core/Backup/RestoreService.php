<?php

declare(strict_types=1);

namespace Blue\Core\Backup;

use PDO;
use PDOException;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Config\AppConfig;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\ZipStream;
use Blue\Core\Install\InstallState;
use Blue\Core\Log\Logger;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Crypto;
use Blue\Core\Support\Ulid;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Workflow\WorkflowSubject;
use RuntimeException;
use Throwable;

/**
 * M48 restore (7B item 4–5, TC-DR, DEC-39).
 *
 * Restore DRILL — into a SEPARATE database (BACKUP_DRILL_DB, default "<DB_NAME>_drill"), never the live one:
 *   decrypt → load every table → count rows and re-export each table: the data must be byte-identical to the backup →
 *   create the triggers → extract every document file and compare SHA-256 → decrypt sample encrypted documents with the
 *   current APP_KEY (key recovery) → record time taken (RTO evidence) and backup age (RPO evidence) → remove the copy.
 *
 * Production RESTORE — only a backup that passed a drill; requested with an incident/change reference, approved by a
 * DIFFERENT System Admin (central workflow), executed by the requester after re-authentication and a typed confirmation.
 * Before anything is replaced a fresh "pre_restore" backup is made (the rollback plan); if loading fails, that backup is
 * loaded back automatically. The backup catalogue itself (backup_runs, restore_drills, restore_requests) is kept as it is
 * now, so the history of what happened survives the restore.
 */
final class RestoreService implements WorkflowSubject
{
    /** Kept from the current database across a restore. */
    public const KEEP_TABLES = ['backup_runs', 'restore_drills', 'restore_requests'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
        private readonly BackupService $backups,
        private readonly Crypto $crypto,
        private readonly AppConfig $config,
        private readonly Logger $logger,
        private readonly InstallState $install,
        private readonly string $documentsPath,
    ) {
    }

    public function drillDatabase(): string
    {
        $name = $this->config->string('BACKUP_DRILL_DB', '');
        return $name !== '' ? $name : $this->config->string('DB_NAME') . '_drill';
    }

    // ---- drill ----------------------------------------------------------------------------------------

    public function drill(Authorizer $az, string $backupPid): string
    {
        if (!$az->scope('core.backup.run')->all) {
            throw HttpException::forbidden();
        }
        $b = $this->backups->row($backupPid);
        if (!in_array($b['status'], ['verified', 'completed'], true)) {
            throw new BusinessException('backup.error.not_ready', [], 409);
        }
        $target = $this->drillDatabase();
        $pid = Ulid::generate($this->clock);
        $now = $this->now();
        $this->db->run(
            "INSERT INTO restore_drills (public_id, created_at, created_by, updated_at, updated_by, backup_run_id, target_database, status, started_at, performed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'running', ?, ?)",
            [$pid, $now, $az->userId, $now, $az->userId, $b['id'], mb_substr($target, 0, 64), $now, $az->userId]
        );
        @set_time_limit(0);
        $t0 = hrtime(true);
        $checks = [];
        $stats = ['tables' => 0, 'rows' => 0, 'files' => 0, 'key' => 'skipped'];
        $status = 'failed';
        $error = null;
        $tmp = $this->backups->tempFile('drill-' . $pid);
        $filesDir = $this->backups->workDir('D' . $pid);
        $pdo = null;
        try {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $target) || $target === $this->config->string('DB_NAME')) {
                throw new BusinessException('backup.error.drill_target', [], 409);
            }
            $pdo = $this->drillConnection($target);
            $this->backups->cipher()->decryptFile($this->backups->path($b), $tmp);
            $checks[] = ['check' => 'decrypt', 'ok' => true, 'detail' => 'key ' . $this->backups->cipher()->fingerprint()];
            $zip = ZipStream::open($tmp);
            $manifest = (array) json_decode($zip->read('manifest.json'), true);
            $this->emptyDatabase($pdo);
            $part = $this->backups->tempFile('part-' . $pid);
            $redump = $this->backups->tempFile('redump-' . $pid);
            try {
                $loaded = $this->loadDatabase($pdo, $zip, $manifest, $part);
                foreach ((array) $manifest['db']['tables'] as $t) {
                    $name = (string) $t['name'];
                    $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
                    $okRows = !$t['data'] || $count === (int) $t['rows'];
                    $okData = true;
                    if ($t['data']) {
                        SqlDumper::dumpTable($pdo, $name, $redump, true, null, microtime(true) + 3600);
                        $okData = SqlDumper::dataHash($redump) === $t['data_sha256'];
                    }
                    $stats['tables']++;
                    $stats['rows'] += $count;
                    if (!$okRows || !$okData) {
                        $checks[] = ['check' => 'table ' . $name, 'ok' => false, 'detail' => 'rows ' . $count . '/' . $t['rows'] . ($okData ? '' : ', data differs')];
                    }
                }
                $checks[] = ['check' => 'tables', 'ok' => !in_array(false, array_column(array_filter($checks, static fn ($c) => str_starts_with($c['check'], 'table ')), 'ok'), true),
                    'detail' => $stats['tables'] . ' tables, ' . $stats['rows'] . ' rows, data re-exported and compared'];
                $checks[] = ['check' => 'triggers', 'ok' => true, 'detail' => $loaded['triggers'] . ' statements'];
                $schema = (string) ($pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() ?: '');
                $checks[] = ['check' => 'schema', 'ok' => $schema === (string) ($manifest['schema'] ?? ''), 'detail' => $schema];
            } finally {
                @unlink($part);
                @unlink($redump);
            }
            // documents: extract every file, compare SHA-256
            $badFiles = 0;
            foreach ((array) ($manifest['files'] ?? []) as $f) {
                $dest = $filesDir . '/' . str_replace('/', '_', (string) $f['path']);
                if ($zip->extractTo('files/' . $f['path'], $dest) !== $f['sha256']) {
                    $badFiles++;
                }
                $stats['files']++;
            }
            $checks[] = ['check' => 'files', 'ok' => $badFiles === 0, 'detail' => $stats['files'] . ' files' . ($badFiles > 0 ? ', ' . $badFiles . ' differ' : '')];
            // key recovery: encrypted documents of the restored copy open with the current APP_KEY and match their recorded hash
            $stats['key'] = $this->keyCheck($pdo, $filesDir, $checks);
            $status = in_array(false, array_column($checks, 'ok'), true) || $stats['key'] === 'failed' ? 'failed' : 'passed';
        } catch (KeyMismatch $e) {
            $error = 'backup.error.key_mismatch';
            $checks[] = ['check' => 'decrypt', 'ok' => false, 'detail' => $e->getMessage()];
        } catch (BusinessException $e) {
            $status = $e->messageKey === 'backup.error.drill_db' ? 'blocked' : 'failed';
            $error = $e->messageKey;
            $checks[] = ['check' => 'setup', 'ok' => false, 'detail' => json_encode($e->params, JSON_UNESCAPED_UNICODE) ?: ''];
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 400);
            $checks[] = ['check' => 'restore', 'ok' => false, 'detail' => $error];
            $this->logger->error('restore_drill_failed', ['drill' => $pid, 'error' => $e->getMessage()]);
        } finally {
            @unlink($tmp);
            foreach (glob($filesDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($filesDir);
            if ($pdo !== null) {
                try {
                    $this->emptyDatabase($pdo); // no copy of live data is left behind in the drill database
                } catch (Throwable) {
                }
            }
        }
        $ms = (int) ((hrtime(true) - $t0) / 1_000_000);
        $age = (int) floor(($this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $b['started_at'], new \DateTimeZone('UTC')))->getTimestamp()) / 60);
        $this->db->run(
            'UPDATE restore_drills SET status = ?, finished_at = ?, duration_ms = ?, backup_age_minutes = ?, tables_checked = ?, rows_checked = ?, files_checked = ?, key_check = ?,
                report_json = ?, error = ?, updated_at = ? WHERE public_id = ?',
            [$status, $this->now(), $ms, max(0, $age), $stats['tables'], $stats['rows'], $stats['files'], $stats['key'], json_encode($checks, JSON_UNESCAPED_UNICODE), $error, $this->now(), $pid]
        );
        $this->audit->record('backup.drill_' . $status, $status === 'passed' ? 'success' : 'failure', 'restore_drill', $pid, null, $error, [
            'backup' => $backupPid, 'target' => $target, 'ms' => $ms, 'tables' => $stats['tables'], 'rows' => $stats['rows'], 'files' => $stats['files'], 'key' => $stats['key'],
        ]);
        return $pid;
    }

    /** @return array<string, mixed> */
    public function drillRow(string $pid): array
    {
        $d = $this->db->one('SELECT d.*, b.public_id AS backup_pid, u.display_name AS performed_name FROM restore_drills d JOIN backup_runs b ON b.id = d.backup_run_id
                               LEFT JOIN users u ON u.id = d.performed_by WHERE d.public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $d['report'] = (array) json_decode((string) ($d['report_json'] ?? '[]'), true);
        return $d;
    }

    // ---- production restore -------------------------------------------------------------------------

    public function request(Authorizer $az, string $backupPid, string $incident, string $reason): string
    {
        if (!$az->scope('core.backup.restore')->all) {
            throw HttpException::forbidden();
        }
        $b = $this->backups->row($backupPid);
        $errors = [];
        if (trim($incident) === '') {
            $errors['incident'] = 'backup.error.incident_required';
        }
        if (trim($reason) === '') {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $drill = $this->db->one("SELECT * FROM restore_drills WHERE backup_run_id = ? AND status = 'passed' ORDER BY id DESC LIMIT 1", [$b['id']]);
        if ($drill === null || !in_array($b['status'], ['verified', 'completed'], true)) {
            throw new BusinessException('backup.error.drill_first', [], 409);
        }
        return $this->db->transactional(function () use ($az, $b, $drill, $incident, $reason): string {
            if ($this->db->scalar("SELECT id FROM restore_requests WHERE status IN ('in_review','returned','approved','executing') FOR UPDATE") !== null) {
                throw new BusinessException('backup.error.restore_open', [], 409);
            }
            $pid = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                "INSERT INTO restore_requests (public_id, created_at, created_by, updated_at, updated_by, backup_run_id, drill_id, incident_ref, reason, status, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_review', ?)",
                [$pid, $now, $az->userId, $now, $az->userId, $b['id'], $drill['id'], mb_substr(trim($incident), 0, 80), mb_substr(trim($reason), 0, 500), $az->userId]
            );
            $inst = $this->workflow->start('core.restore', $pid, 'Restore ' . substr((string) $b['started_at'], 0, 16) . ' UTC · ' . mb_substr(trim($incident), 0, 40), null, $az->userId);
            $this->db->run('UPDATE restore_requests SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE public_id = ?', [$inst, $pid]);
            $this->audit->record('backup.restore_requested', 'success', 'restore_request', $pid, null, $reason, ['backup' => $b['public_id'], 'incident' => $incident]);
            return $pid;
        });
    }

    /** @return array<string, mixed> */
    public function requestRow(string $pid): array
    {
        $r = $this->db->one(
            'SELECT r.*, b.public_id AS backup_pid, b.started_at AS backup_started, d.public_id AS drill_pid, q.display_name AS requested_name, a.display_name AS approved_name
               FROM restore_requests r JOIN backup_runs b ON b.id = r.backup_run_id JOIN restore_drills d ON d.id = r.drill_id
               LEFT JOIN users q ON q.id = r.requested_by LEFT JOIN users a ON a.id = r.approved_by WHERE r.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $r['result'] = (array) json_decode((string) ($r['result_json'] ?? '[]'), true);
        return $r;
    }

    /** The phrase the executor types (prevents a restore by a stray click). */
    public static function confirmPhrase(string $backupPid): string
    {
        return 'RESTORE ' . substr($backupPid, -6);
    }

    /**
     * Carries out an APPROVED restore. Returns the result summary. The executor's session is gone afterwards
     * (sessions are not part of a backup) — everyone signs in again.
     *
     * @return array<string, mixed>
     */
    public function execute(Authorizer $az, string $requestPid, string $phrase): array
    {
        if (!$az->scope('core.backup.restore')->all) {
            throw HttpException::forbidden();
        }
        $r = $this->requestRow($requestPid);
        if ((int) $r['requested_by'] !== $az->userId) {
            throw new BusinessException('backup.error.executor', [], 403);
        }
        if ($r['status'] !== 'approved') {
            throw new BusinessException('backup.error.not_approved', [], 409);
        }
        if (trim($phrase) !== self::confirmPhrase((string) $r['backup_pid'])) {
            throw new ValidationException(['phrase' => 'backup.error.phrase']);
        }
        $b = $this->backups->row((string) $r['backup_pid']);
        $problems = $this->backups->verifyFile($this->backups->path($b), (string) $b['sha256'], $requestPid);
        if ($problems !== []) {
            throw new BusinessException('backup.error.verify_failed', ['detail' => implode('; ', array_slice($problems, 0, 2))], 409);
        }
        @set_time_limit(0);
        $t0 = hrtime(true);
        $this->db->run("UPDATE restore_requests SET status = 'executing', executed_by = ?, executed_at = ?, updated_at = ? WHERE public_id = ?", [$az->userId, $this->now(), $this->now(), $requestPid]);
        $this->audit->record('backup.restore_started', 'success', 'restore_request', $requestPid, null, null, ['backup' => $r['backup_pid']]);

        // 1. rollback plan: a fresh full backup of the current state
        $pre = $this->backups->start('pre_restore', $az->userId, true);
        $preRow = $this->backups->runToEnd($pre);
        if ($preRow['status'] !== 'verified') {
            $this->db->run("UPDATE restore_requests SET status = 'failed', error = 'pre-restore backup failed', finished_at = ? WHERE public_id = ?", [$this->now(), $requestPid]);
            throw new BusinessException('backup.error.pre_backup', [], 500);
        }
        $this->db->run('UPDATE restore_requests SET pre_restore_backup_id = ? WHERE public_id = ?', [$preRow['id'], $requestPid]);

        // 2. keep the backup catalogue as it is now
        $keep = [];
        foreach (self::KEEP_TABLES as $t) {
            $keep[$t] = $this->db->all('SELECT * FROM `' . $t . '`');
        }
        $this->install->setRestoring(true, $requestPid);
        $result = ['backup' => $r['backup_pid'], 'pre_restore_backup' => $preRow['public_id']];
        $moved = null;
        try {
            try {
                $stats = $this->restoreInto($this->db->pdo(), $this->backups->path($b));
                $moved = $this->restoreFiles($this->backups->path($b), $requestPid);
                $result += ['tables' => $stats['tables'], 'rows' => $stats['rows'], 'files' => $moved['files']];
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
                $result['error'] = mb_substr($e->getMessage(), 0, 300);
                $this->logger->error('restore_failed_rolling_back', ['request' => $requestPid, 'error' => $e->getMessage()]);
                // roll back to the state before: the pre-restore backup
                $this->restoreInto($this->db->pdo(), $this->backups->path($preRow)); // documents were put back by restoreFiles itself
                $result['rolled_back'] = true;
            }
            $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($keep as $t => $rows) {
                $this->db->pdo()->exec('DELETE FROM `' . $t . '`');
                foreach ($rows as $row) {
                    $cols = array_keys($row);
                    $this->db->run('INSERT INTO `' . $t . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
                }
            }
            $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            $ms = (int) ((hrtime(true) - $t0) / 1_000_000);
            $result['ms'] = $ms;
            $this->db->run(
                'UPDATE restore_requests SET status = ?, finished_at = ?, duration_ms = ?, result_json = ?, error = ?, updated_at = ? WHERE public_id = ?',
                [$ok ? 'done' : 'failed', $this->now(), $ms, json_encode($result, JSON_UNESCAPED_UNICODE), $ok ? null : mb_substr((string) ($result['error'] ?? ''), 0, 500), $this->now(), $requestPid]
            );
            $this->audit->record($ok ? 'backup.restored' : 'backup.restore_rolled_back', $ok ? 'success' : 'failure', 'restore_request', $requestPid, null, (string) $r['incident_ref'], $result, $az->userId);
            if ($ok && $moved !== null) {
                $this->dropOldFiles($moved);
            }
        } finally {
            $this->install->setRestoring(false);
        }
        $result['ok'] = !isset($result['error']);
        return $result;
    }

    // ---- WorkflowSubject ------------------------------------------------------------------------------

    public function type(): string
    {
        return 'restore_request';
    }

    public function link(string $subjectId): string
    {
        return '/admin/backups/restore/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        $r = $this->requestRow((string) $instance['subject_id']);
        if (in_array($r['status'], ['approved', 'executing', 'done'], true)) {
            return;
        }
        if ((int) $r['requested_by'] === $approverId) {
            throw new BusinessException('workflow.error.self_approval', [], 403);
        }
        if (!(new Authorizer($this->db, $this->clock, $approverId))->scope('core.backup.restore_approve')->all) {
            throw new BusinessException('backup.error.approver_right', [], 403);
        }
        $this->db->run("UPDATE restore_requests SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ? WHERE public_id = ?", [$approverId, $this->now(), $this->now(), $r['public_id']]);
        $this->audit->record('backup.restore_approved', 'success', 'restore_request', (string) $r['public_id'], null, null, [], $approverId);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $status = ['rejected' => 'rejected', 'returned' => 'returned', 'cancelled' => 'cancelled'][$outcome] ?? null;
        if ($status !== null) {
            $this->db->run("UPDATE restore_requests SET status = ?, updated_at = ? WHERE public_id = ? AND status IN ('in_review','returned')", [$status, $this->now(), $instance['subject_id']]);
        }
    }

    public function onResubmitted(array $instance): void
    {
        $this->db->run("UPDATE restore_requests SET status = 'in_review', updated_at = ? WHERE public_id = ? AND status = 'returned'", [$this->now(), $instance['subject_id']]);
    }

    // ---- loading ----------------------------------------------------------------------------------

    /**
     * Replaces every table of the connection's database with the backup's.
     *
     * @return array{tables: int, rows: int}
     */
    public function restoreInto(PDO $pdo, string $backupPath): array
    {
        $tmp = $this->backups->tempFile('restore');
        $part = $this->backups->tempFile('restore-part');
        try {
            $this->backups->cipher()->decryptFile($backupPath, $tmp);
            $zip = ZipStream::open($tmp);
            $manifest = (array) json_decode($zip->read('manifest.json'), true);
            $this->emptyDatabase($pdo);
            $loaded = $this->loadDatabase($pdo, $zip, $manifest, $part);
            return ['tables' => $loaded['tables'], 'rows' => array_sum(array_map(static fn ($t): int => (int) $t['rows'], (array) $manifest['db']['tables']))];
        } finally {
            @unlink($tmp);
            @unlink($part);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{tables: int, triggers: int}
     */
    private function loadDatabase(PDO $pdo, ZipStream $zip, array $manifest, string $part): array
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('SET UNIQUE_CHECKS = 0');
        $n = 0;
        try {
            foreach ((array) $manifest['db']['tables'] as $t) {
                if ($zip->extractTo((string) $t['file'], $part) !== $t['sha256']) {
                    throw new RuntimeException('Backup part differs: ' . $t['name']);
                }
                SqlDumper::load($pdo, $part);
                $n++;
            }
            if ($zip->extractTo((string) $manifest['db']['triggers'], $part) !== $manifest['db']['triggers_sha256']) {
                throw new RuntimeException('Backup part differs: triggers');
            }
            $triggers = SqlDumper::load($pdo, $part);
        } finally {
            $pdo->exec('SET UNIQUE_CHECKS = 1');
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        return ['tables' => $n, 'triggers' => $triggers];
    }

    private function emptyDatabase(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN) ?? [] as $tr) {
            $pdo->exec('DROP TRIGGER IF EXISTS `' . str_replace('`', '', (string) $tr) . '`');
        }
        foreach (SqlDumper::tables($pdo) as $t) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return array{files: int, old: ?string, dir: string} */
    private function restoreFiles(string $backupPath, string $label): array
    {
        $tmp = $this->backups->tempFile('restore-files');
        try {
            $this->backups->cipher()->decryptFile($backupPath, $tmp);
            $zip = ZipStream::open($tmp);
            $manifest = (array) json_decode($zip->read('manifest.json'), true);
            $files = (array) ($manifest['files'] ?? []);
            if ($files === []) {
                return ['files' => 0, 'old' => null, 'dir' => $this->documentsPath];
            }
            $old = null;
            if (is_dir($this->documentsPath)) {
                $old = $this->documentsPath . '.pre-restore-' . preg_replace('/[^0-9A-Z]/', '', $label);
                if (!rename($this->documentsPath, $old)) {
                    throw new RuntimeException('Cannot set the current documents aside');
                }
            }
            mkdir($this->documentsPath, 0750, true);
            try {
                foreach ($files as $f) {
                    if (!preg_match('#^[0-9a-f]{2}/[0-9a-f]{40}$#', (string) $f['path'])) {
                        continue;
                    }
                    $dest = $this->documentsPath . '/' . $f['path'];
                    if (!is_dir(dirname($dest))) {
                        mkdir(dirname($dest), 0750, true);
                    }
                    if ($zip->extractTo('files/' . $f['path'], $dest) !== $f['sha256']) {
                        throw new RuntimeException('Document file differs: ' . $f['path']);
                    }
                }
            } catch (Throwable $e) {
                $this->undoFiles(['files' => 0, 'old' => $old, 'dir' => $this->documentsPath]);
                throw $e;
            }
            return ['files' => count($files), 'old' => $old, 'dir' => $this->documentsPath];
        } finally {
            @unlink($tmp);
        }
    }

    /** @param array{files: int, old: ?string, dir: string} $moved */
    private function undoFiles(array $moved): void
    {
        if ($moved['old'] === null) {
            return;
        }
        $this->removeTree($moved['dir']);
        rename($moved['old'], $moved['dir']);
    }

    /** @param array{files: int, old: ?string, dir: string} $moved */
    private function dropOldFiles(array $moved): void
    {
        if ($moved['old'] !== null) {
            $this->removeTree($moved['old']); // still inside the pre-restore backup
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * @param list<array{check: string, ok: bool, detail: string}> $checks
     * @return 'ok'|'failed'|'skipped'
     */
    private function keyCheck(PDO $pdo, string $filesDir, array &$checks): string
    {
        $docs = [];
        try {
            $docs = $pdo->query("SELECT storage_key, sha256 FROM document_versions WHERE encrypted = 1 AND storage_key IS NOT NULL AND purged_at IS NULL ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException) {
        }
        $tried = 0;
        $good = 0;
        foreach ($docs as $d) {
            $key = (string) $d['storage_key'];
            $file = $filesDir . '/' . substr($key, 0, 2) . '_' . $key;
            if (!is_file($file)) {
                continue;
            }
            $tried++;
            try {
                if (hash('sha256', $this->crypto->decrypt((string) file_get_contents($file))) === $d['sha256']) {
                    $good++;
                }
            } catch (Throwable) {
            }
        }
        if ($tried === 0) {
            // no encrypted documents in this backup: try an encrypted database field instead
            try {
                $blob = $pdo->query('SELECT data_enc FROM import_rows WHERE data_enc IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn();
                if (is_string($blob) && $blob !== '') {
                    $tried = 1;
                    $this->crypto->decrypt($blob);
                    $good = 1;
                }
            } catch (Throwable) {
            }
        }
        if ($tried === 0) {
            $checks[] = ['check' => 'key', 'ok' => true, 'detail' => 'no encrypted data to test'];
            return 'skipped';
        }
        $checks[] = ['check' => 'key', 'ok' => $good === $tried, 'detail' => $good . '/' . $tried . ' encrypted items opened with the current APP_KEY'];
        return $good === $tried ? 'ok' : 'failed';
    }

    private function drillConnection(string $target): PDO
    {
        $make = fn (): PDO => (new Connection(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $this->config->string('DB_HOST'), $this->config->int('DB_PORT', 3306), $target, $this->config->string('DB_CHARSET', 'utf8mb4')),
            $this->config->string('DB_USER'),
            $this->config->string('DB_PASS'),
        ))->pdo();
        try {
            return $make();
        } catch (PDOException) {
            try {
                $this->db->pdo()->exec('CREATE DATABASE IF NOT EXISTS `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                return $make();
            } catch (PDOException $e) {
                throw new BusinessException('backup.error.drill_db', ['database' => $target, 'detail' => mb_substr($e->getMessage(), 0, 120)], 409);
            }
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
