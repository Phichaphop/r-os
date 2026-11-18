<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * audit_events is append-only (6.30.5, P0-04 §7, DM-02).
 * Layer 1 (always): application code never updates/deletes audit rows (checked by tests).
 * Layer 2 (this migration): database triggers that reject UPDATE / DELETE.
 * Shared hosting may refuse CREATE TRIGGER; then the guard is recorded as 'code_only'
 * (shown on the status page) instead of failing the installation.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Database triggers that block UPDATE/DELETE on audit_events (best effort)';
    }

    public function up(Connection $db): void
    {
        $mode = 'triggers';
        try {
            $db->exec('DROP TRIGGER IF EXISTS trg_audit_no_update');
            $db->exec('DROP TRIGGER IF EXISTS trg_audit_no_delete');
            $db->exec("CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_events FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only'");
            $db->exec("CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_events FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only'");
        } catch (\PDOException) {
            $mode = 'code_only';
        }
        $db->run(
            "INSERT INTO system_info (info_key, info_value, updated_at) VALUES ('audit_guard', ?, UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE info_value = VALUES(info_value), updated_at = VALUES(updated_at)",
            [$mode]
        );
    }
};
