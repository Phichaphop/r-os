<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * 0.8.1 (DEC-68) — PDF tools may be opened to visitors who have not signed in. Their jobs have no owner account
 * (owner_user_id = 0), so each job also carries the visitor's random browser-session key: only that browser can see,
 * run, download or discard it. The files stay temporary and are removed after pdf.temp_hours like every other job.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'S01 PDF: guest_key on jobs made by visitors (owner 0) so only that browser session reaches them';
    }

    public function up(Connection $db): void
    {
        if (!Schema::columnExists($db, 'pdf_jobs', 'guest_key')) {
            $db->exec('ALTER TABLE pdf_jobs ADD COLUMN guest_key CHAR(32) NULL AFTER owner_user_id, ADD KEY ix_pdf_jobs_guest (guest_key)');
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return Schema::columnExists($db, 'pdf_jobs', 'guest_key') ? [] : ['pdf_jobs.guest_key missing'];
    }
};
