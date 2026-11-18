<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * Phase 5C (DEC-71) — raw punches may also come from M57 GPS check-in/out: source "gps", verify method "gps".
 * The GPS module keeps the location evidence; att_raw_punches only gets the time (append-only, unique event id).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Attendance: raw punches from M57 GPS check-in (source/verify method "gps")';
    }

    public function up(Connection $db): void
    {
        $db->exec("ALTER TABLE att_raw_punches MODIFY source ENUM('api','file','gps') NOT NULL");
        $db->exec("ALTER TABLE att_raw_punches MODIFY verify_method ENUM('finger','face','card','password','file','gps','unknown') NOT NULL");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $type = (string) $db->scalar("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'att_raw_punches' AND COLUMN_NAME = 'source'");
        return str_contains($type, "'gps'") ? [] : ['att_raw_punches.source has no gps'];
    }
};
