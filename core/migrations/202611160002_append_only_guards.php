<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * Append-only history tables of the shared services (6.26.1, 6.27, 6.30.4): lock_events, calculation_snapshots,
 * workflow_decisions. Same approach as audit_events: application code never updates/deletes them, and database
 * triggers reject it where the host allows CREATE TRIGGER (otherwise recorded as code_only).
 */

return new class extends Migration {
    private const TABLES = ['lock_events', 'calculation_snapshots', 'workflow_decisions'];

    public function description(): string
    {
        return 'Triggers that block UPDATE/DELETE on lock_events, calculation_snapshots, workflow_decisions (best effort)';
    }

    public function up(Connection $db): void
    {
        $mode = 'triggers';
        try {
            foreach (self::TABLES as $t) {
                foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $suffix => $op) {
                    $db->exec("DROP TRIGGER IF EXISTS trg_{$t}_no_{$suffix}");
                    $db->exec("CREATE TRIGGER trg_{$t}_no_{$suffix} BEFORE {$op} ON {$t} FOR EACH ROW
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$t} is append-only'");
                }
            }
        } catch (\PDOException) {
            $mode = 'code_only';
        }
        $db->run(
            "INSERT INTO system_info (info_key, info_value, updated_at) VALUES ('history_guard', ?, UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE info_value = VALUES(info_value), updated_at = VALUES(updated_at)",
            [$mode]
        );
    }
};
