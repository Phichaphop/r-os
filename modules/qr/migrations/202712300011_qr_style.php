<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * 0.38.0 (R2): saved QR codes remember what kind of content they hold (link, text, phone, e-mail, Wi-Fi, contact card)
 * with the fields typed (so the code can be edited), their colours and decoration, and a soft delete (a deleted
 * managed code stops redirecting; the row stays for the history).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'QR codes: content type + fields, colours/decoration, soft delete';
    }

    public function up(Connection $db): void
    {
        $db->exec("ALTER TABLE qr_codes
            ADD COLUMN content_type VARCHAR(10) NOT NULL DEFAULT 'url' AFTER kind,
            ADD COLUMN fields_json TEXT NULL AFTER content,
            ADD COLUMN style_json TEXT NULL AFTER with_logo,
            ADD COLUMN deleted_at DATETIME(6) NULL AFTER revoke_reason,
            ADD COLUMN deleted_by BIGINT UNSIGNED NULL AFTER deleted_at,
            ADD KEY ix_qr_codes_deleted (deleted_at)");
    }
};
