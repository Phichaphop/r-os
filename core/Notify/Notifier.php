<?php

declare(strict_types=1);

namespace Blue\Core\Notify;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * In-app notifications (6.12, 6.30.13). Messages carry only a translation key + minimal parameters and a link;
 * the target page checks permissions again when opened. External channels (email/SMS) are not used here —
 * no message is claimed as "sent" outside the app until a provider is connected and tested.
 */
final class Notifier
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, scalar> $params
     * @param string|null $dedupeKey same key for the same user is stored once (retries, duplicate events)
     */
    public function notify(int $userId, string $eventType, string $titleKey, array $params = [], ?string $link = null, ?string $dedupeKey = null): void
    {
        $this->db->run(
            'INSERT IGNORE INTO notifications (public_id, user_id, event_type, title_key, params_json, link, dedupe_key, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'queued\', ?)',
            [
                Ulid::generate($this->clock), $userId, $eventType, $titleKey,
                $params === [] ? null : json_encode($params, JSON_UNESCAPED_UNICODE), $link, $dedupeKey,
                $this->clock->now()->format('Y-m-d H:i:s.u'),
            ]
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'queued'", [$userId]);
    }

    /** @return array{rows:list<array<string,mixed>>, total:int} */
    public function list(int $userId, int $page, int $per): array
    {
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$userId]);
        $offset = ($page - 1) * $per;
        $rows = $this->db->all(
            "SELECT public_id, event_type, title_key, params_json, link, status, created_at
               FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT {$per} OFFSET {$offset}",
            [$userId]
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** Marks one (or all, when $publicId is null) of the user's own notifications as read — never someone else's. */
    public function markRead(int $userId, ?string $publicId = null): ?string
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        if ($publicId === null) {
            $this->db->run("UPDATE notifications SET status = 'read', read_at = ? WHERE user_id = ? AND status = 'queued'", [$now, $userId]);
            return null;
        }
        $row = $this->db->one('SELECT id, link FROM notifications WHERE public_id = ? AND user_id = ?', [$publicId, $userId]);
        if ($row === null) {
            return null;
        }
        $this->db->run("UPDATE notifications SET status = 'read', read_at = COALESCE(read_at, ?) WHERE id = ?", [$now, $row['id']]);
        return $row['link'] !== null ? (string) $row['link'] : '/notifications';
    }
}
