<?php

declare(strict_types=1);

namespace Blue\Modules\Assets;

use Blue\Core\App;
use Blue\Core\Privacy\PersonalDataProvider;

/**
 * Asset register personal data (DEC-94): the assets I hold or held (custody history). Erasing keeps the custody
 * records — they are the company's control of its property (who received and returned what); notes I wrote are not
 * part of them. Assets still held are reported so they can be returned first.
 */
final class AssetsPersonalData implements PersonalDataProvider
{
    public static function export(App $app, int $userId, ?int $employeeId): array
    {
        return [
            ['key' => 'custody', 'label' => 'assets.privacy.custody', 'rows' => $app->db()->all(
                'SELECT a.code, a.name, a.serial_no, c.assigned_at, c.condition_out, c.acknowledged_at, c.returned_at, c.condition_in FROM as_custody c JOIN as_assets a ON a.id = c.asset_id WHERE c.user_id = ? ORDER BY c.id',
                [$userId]
            )],
        ];
    }

    public static function erase(App $app, int $userId, ?int $employeeId, int $actorId, bool $dryRun): array
    {
        $db = $app->db();
        $held = (int) $db->scalar('SELECT COUNT(*) FROM as_custody WHERE user_id = ? AND returned_at IS NULL', [$userId]);
        $all = (int) $db->scalar('SELECT COUNT(*) FROM as_custody WHERE user_id = ?', [$userId]);
        return ['done' => [], 'kept' => array_filter([
            'assets.privacy.custody' => $all > 0 ? 'assets.privacy.kept' : null,
            'assets.privacy.still_held' => $held > 0 ? 'assets.privacy.return_first' : null,
        ])];
    }
}
