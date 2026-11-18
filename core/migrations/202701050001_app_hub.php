<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;

/**
 * 0.4.3 — Home as an app hub (6.4, REQ-IAM-003): every app is shown; apps the user may not open are greyed out with
 * a "request access" button. The request now remembers WHICH app it was made from (access_requests.module_code,
 * P0-03 §2) so the approver sees it and the tile can show "waiting for approval". NULL = made from the general form.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'App hub: access requests remember the app they were made for (module_code)';
    }

    public function up(Connection $db): void
    {
        $has = $db->scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'access_requests' AND COLUMN_NAME = 'module_code'"
        );
        if ((int) $has === 0) {
            $db->exec('ALTER TABLE access_requests ADD COLUMN module_code VARCHAR(60) NULL AFTER role_code');
        }
    }

    /** @return list<string> */
    public function postCheck(Connection $db): array
    {
        return (int) $db->scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'access_requests' AND COLUMN_NAME = 'module_code'"
        ) === 1 ? [] : ['access_requests.module_code missing'];
    }
};
