<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * 0.39.0 (R3, item 8.2) — PDF results are not kept unless the person presses Save. The original files are removed as
 * soon as the result is made, the result stays only for pdf.result_minutes so it can be downloaded, and a job appears
 * in "my saved results" only once one of its outputs has been saved to the document register (saved_at).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'S01 PDF: saved_at on jobs — only saved results are listed; unsaved results are short-lived';
    }

    public function up(Connection $db): void
    {
        if (!Schema::columnExists($db, 'pdf_jobs', 'saved_at')) {
            $db->exec('ALTER TABLE pdf_jobs ADD COLUMN saved_at DATETIME(6) NULL AFTER finished_at, ADD KEY ix_pdf_jobs_saved (owner_user_id, saved_at)');
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return Schema::columnExists($db, 'pdf_jobs', 'saved_at') ? [] : ['pdf_jobs.saved_at missing'];
    }
};
