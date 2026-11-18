<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * 0.7.0 — date-range locks owned by a module (attendance periods): the owner may release its own lock after its own
 * approval (a close sent back, a reopen approved) and attach the snapshot the lock protects. Two new history events.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Locks: "released" and "snapshot" history events';
    }

    public function up(Connection $db): void
    {
        $db->exec("ALTER TABLE lock_events MODIFY event ENUM('created','locked','reopen_requested','reopen_approved','reopen_rejected','closed','blocked_write','released','snapshot') NOT NULL");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $type = (string) $db->scalar("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lock_events' AND COLUMN_NAME = 'event'");
        return str_contains($type, "'released'") ? [] : ['lock_events.event lacks released'];
    }
};
