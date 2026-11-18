<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 5B (DEC-70) — a day's result remembers the shift it was measured against (M35), so the month view and the
 * payroll time input say "N · 22:00–07:00" instead of the company's fixed day. NULL = the work schedule / no shift.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Attendance: shift code on daily results (M35 shifts)';
    }

    public function up(Connection $db): void
    {
        if (!Schema::columnExists($db, 'att_days', 'shift_code')) {
            $db->exec('ALTER TABLE att_days ADD COLUMN shift_code VARCHAR(20) NULL AFTER day_name');
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return Schema::columnExists($db, 'att_days', 'shift_code') ? [] : ['att_days.shift_code missing'];
    }
};
