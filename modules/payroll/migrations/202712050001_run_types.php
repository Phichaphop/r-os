<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 6F (DEC-87): kinds of payroll run — regular (one per period), off-cycle (e.g. a bonus: only amounts Payroll
 * enters and welfare) and correction (the difference after recalculating an approved run) — and pay profile changes
 * that knowingly reach back into an approved period ("retro": paid through a correction run).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Payroll: off-cycle and correction runs, retroactive pay changes';
    }

    public function up(Connection $db): void
    {
        if (!Schema::columnExists($db, 'pay_runs', 'run_type')) {
            $db->exec("ALTER TABLE pay_runs ADD COLUMN run_type ENUM('regular','off_cycle','correction') NOT NULL DEFAULT 'regular' AFTER run_no,
                ADD COLUMN corrects_run_id BIGINT UNSIGNED NULL AFTER run_type, ADD COLUMN purpose VARCHAR(300) NULL AFTER label, ADD KEY ix_pay_runs_corrects (corrects_run_id)");
        }
        if (!Schema::columnExists($db, 'pay_profiles', 'retro')) {
            $db->exec('ALTER TABLE pay_profiles ADD COLUMN retro TINYINT(1) NOT NULL DEFAULT 0 AFTER reason');
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        $out = [];
        foreach ([['pay_runs', 'run_type'], ['pay_runs', 'corrects_run_id'], ['pay_runs', 'purpose'], ['pay_profiles', 'retro']] as [$t, $c]) {
            if (!Schema::columnExists($db, $t, $c)) {
                $out[] = $t . '.' . $c . ' missing';
            }
        }
        return $out;
    }
};
