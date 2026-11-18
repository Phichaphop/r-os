<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M29 0.11.2 (DEC-74): the lucky draw works like the quick draw — names beside the wheel, spin — and every spin is saved.
 * - draw_events.style: classic (list locked first, prizes set in advance — events made before 0.11.2) | simple.
 * - draw_rounds.candidate_list: the snapshot positions that could be drawn in that spin (a simple draw's list may change
 *   between spins — names taken out, names added), so each spin can be replayed from the seed afterwards.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M29 Lucky draw: simple saved draws (style, candidate list per spin)';
    }

    public function up(Connection $db): void
    {
        if (!Schema::columnExists($db, 'draw_events', 'style')) {
            $db->exec("ALTER TABLE draw_events ADD COLUMN style ENUM('classic','simple') NOT NULL DEFAULT 'classic' AFTER mode");
        }
        if (!Schema::columnExists($db, 'draw_rounds', 'candidate_list')) {
            $db->exec('ALTER TABLE draw_rounds ADD COLUMN candidate_list MEDIUMTEXT NULL AFTER candidates_hash');
        }
    }

    public function postCheck(Connection $db): array
    {
        $p = [];
        foreach ([['draw_events', 'style'], ['draw_rounds', 'candidate_list']] as [$t, $c]) {
            if (!Schema::columnExists($db, $t, $c)) {
                $p[] = "{$t}.{$c} missing";
            }
        }
        return $p;
    }
};
