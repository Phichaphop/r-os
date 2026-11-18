<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * 0.8.2 (DEC-69) — approvals follow the branch and unit of the person a request is about, not only the company:
 * a workflow instance remembers the subject's site (branch) and department (unit) so the request reaches the approver
 * whose rights cover that branch/unit (e.g. HR of branch 1), and the company-wide approver only when there is none.
 * Existing instances keep NULL = company-level routing as before.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Workflow: site_id / department_id on instances for branch- and unit-level approver routing';
    }

    public function up(Connection $db): void
    {
        if (!Schema::columnExists($db, 'workflow_instances', 'site_id')) {
            $db->exec('ALTER TABLE workflow_instances ADD COLUMN site_id BIGINT UNSIGNED NULL AFTER legal_entity_id,
                ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER site_id');
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return Schema::columnExists($db, 'workflow_instances', 'department_id') ? [] : ['workflow_instances.department_id missing'];
    }
};
