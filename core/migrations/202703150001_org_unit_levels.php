<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * 0.8.1 (DEC-68) — fixed organization levels under each company:
 *   company → top_management → division → department → section → sub_section
 * The existing "departments" table holds every unit; unit_level says which level it is. A unit may skip levels
 * (a department straight under top management) but a child is always on a LOWER level than its parent.
 * Sites (branches) stay a separate dimension: a unit may be tagged with the site it belongs to (optional), so one
 * structure serves a company with many branches. Existing units become "department".
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Organization: fixed unit levels (top management, division, department, section, sub-section)';
    }

    public function up(Connection $db): void
    {
        if (!Schema::columnExists($db, 'departments', 'unit_level')) {
            $db->exec("ALTER TABLE departments ADD COLUMN unit_level ENUM('top_management','division','department','section','sub_section') NOT NULL DEFAULT 'department' AFTER parent_id,
                ADD KEY ix_departments_level (legal_entity_id, unit_level)");
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return Schema::columnExists($db, 'departments', 'unit_level') ? [] : ['departments.unit_level missing'];
    }
};
