<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * 0.49.0 (R8-4, DEC-118 · Q-11): the Payroll Checker reviews approved payroll runs afterwards, one run at a time
 * (regular, off-cycle and correction runs separately; the screen groups them by pay period). Until a checker is
 * appointed, approved runs wait in the review list. Append-only history: every review is kept, the latest one counts.
 * No amounts are stored here — the review points at the run and the hash of the result it looked at.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Payroll: Payroll Checker reviews of approved runs (history per run)';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $db->exec("CREATE TABLE IF NOT EXISTS pay_run_reviews (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            run_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            reviewer_id BIGINT UNSIGNED NOT NULL,
            result ENUM('ok','issues') NOT NULL,
            note VARCHAR(1000) NULL,
            calc_hash CHAR(64) NULL,
            reviewed_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_pay_review_pid (public_id), KEY ix_pay_review_run (run_id, id), KEY ix_pay_review_le (legal_entity_id),
            CONSTRAINT fk_pay_review_run FOREIGN KEY (run_id) REFERENCES pay_runs (id)
        ) {$o}");
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return $db->tableExists('pay_run_reviews') ? [] : ['pay_run_reviews missing'];
    }
};
