<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * 0.47.0 (R8-2 · DEC-116): the annual withholding tax filing (P.N.D. 1A) — a third kind whose period is a year ("YYYY").
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Filing kind wht_annual';
    }

    public function up(Connection $db): void
    {
        $db->exec("ALTER TABLE fl_filings MODIFY kind ENUM('wht_monthly','sso_monthly','wht_annual') NOT NULL");
    }
};
