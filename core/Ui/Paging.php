<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

use Blue\Core\Http\Request;

/**
 * Shared pagination rules (6.10.1) — every list uses this instead of its own:
 * - page sizes 10/20/30/40/50/100/200/300 only; anything else → 20 (default)
 * - the size a user picks is remembered per list in user_preferences
 * - page < 1 → 1; a page past the end (rows deleted meanwhile) → the last page that has rows
 * - the query string keeps search/filter/sort when moving between pages; changing them restarts at page 1
 *   (forms do not send "page")
 */
final class Paging
{
    public const SIZES = [10, 20, 30, 40, 50, 100, 200, 300];
    public const DEFAULT = 20;

    public function __construct(
        public int $page,
        public readonly int $per,
    ) {
    }

    public static function from(Request $r, ?Preferences $prefs = null, ?int $userId = null, string $listKey = ''): self
    {
        $raw = $r->query['per'] ?? null;
        $per = is_scalar($raw) && in_array((int) $raw, self::SIZES, true) && (string) (int) $raw === (string) $raw ? (int) $raw : null;
        $prefKey = 'page_size.' . $listKey;
        if ($per !== null && $prefs !== null && $userId !== null && $listKey !== '') {
            $prefs->set($userId, $prefKey, (string) $per);
        }
        if ($per === null && $prefs !== null && $userId !== null && $listKey !== '') {
            $saved = (int) ($prefs->get($userId, $prefKey) ?? 0);
            $per = in_array($saved, self::SIZES, true) ? $saved : null;
        }
        $page = $r->query['page'] ?? 1;
        $page = is_scalar($page) && preg_match('/^\d{1,6}$/', (string) $page) ? max(1, (int) $page) : 1;
        return new self($page, $per ?? self::DEFAULT);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->per;
    }

    /** Moves past-the-end requests to the last page that has rows; returns true when the page changed. */
    public function clamp(int $total): bool
    {
        $last = max(1, (int) ceil($total / $this->per));
        if ($this->page > $last) {
            $this->page = $last;
            return true;
        }
        return false;
    }

    /**
     * Page numbers to show, with null for an ellipsis (1 … 4 5 6 … 20).
     *
     * @return list<int|null>
     */
    public static function window(int $page, int $last): array
    {
        if ($last <= 7) {
            return range(1, max(1, $last));
        }
        $out = [1];
        $from = max(2, $page - 1);
        $to = min($last - 1, $page + 1);
        if ($from > 2) {
            $out[] = null;
        }
        for ($i = $from; $i <= $to; $i++) {
            $out[] = $i;
        }
        if ($to < $last - 1) {
            $out[] = null;
        }
        $out[] = $last;
        return $out;
    }
}
