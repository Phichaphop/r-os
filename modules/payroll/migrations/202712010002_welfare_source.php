<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 6D (DEC-86): a payroll run may take welfare payout batches (M37) as a source, like OT batches — the frozen
 * batch lines become earnings on the welfare type's component.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Payroll: welfare batches as a run source';
    }

    public function up(Connection $db): void
    {
        if (!Schema::columnExists($db, 'pay_runs', 'welfare_batch_ids')) {
            $db->exec('ALTER TABLE pay_runs ADD COLUMN welfare_batch_ids VARCHAR(500) NULL AFTER ot_batch_ids');
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return Schema::columnExists($db, 'pay_runs', 'welfare_batch_ids') ? [] : ['pay_runs.welfare_batch_ids missing'];
    }
};
