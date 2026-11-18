<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M29 0.6.1: "reset the draw" and the "lucky order" mode.
 * - generation: a reset starts a new generation; rounds and winners of earlier generations are kept (never deleted) and
 *   the old commitment/seed go to the log, so a reset can always be explained afterwards.
 * - prize_mode: prizes (set prizes in advance) | sequence (no prizes — draw the order: lucky person no. 1, 2, 3 …).
 * - draw_prizes.retired: prizes replaced after a reset stay for the history of earlier generations.
 * - draw_winners.seq_no: place in sequence mode; a redraw takes over the place of the winner it replaces.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M29 Lucky draw: reset (generations) and sequence mode';
    }

    public function up(Connection $db): void
    {
        if (!Schema::columnExists($db, 'draw_events', 'generation')) {
            $db->exec("ALTER TABLE draw_events ADD COLUMN generation INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
                ADD COLUMN prize_mode ENUM('prizes','sequence') NOT NULL DEFAULT 'prizes' AFTER mode,
                ADD COLUMN seq_count INT UNSIGNED NULL AFTER prize_mode");
        }
        if (!Schema::columnExists($db, 'draw_rounds', 'generation')) {
            $db->exec('ALTER TABLE draw_rounds ADD COLUMN generation INT UNSIGNED NOT NULL DEFAULT 1 AFTER event_id');
        }
        if (Schema::indexExists($db, 'draw_rounds', 'uq_draw_rounds_no')) {
            $db->exec('ALTER TABLE draw_rounds DROP INDEX uq_draw_rounds_no, ADD UNIQUE KEY uq_draw_rounds_gen_no (event_id, generation, round_no)');
        }
        if (!Schema::columnExists($db, 'draw_winners', 'generation')) {
            $db->exec('ALTER TABLE draw_winners ADD COLUMN generation INT UNSIGNED NOT NULL DEFAULT 1 AFTER event_id, ADD COLUMN seq_no INT UNSIGNED NULL AFTER pick_no,
                ADD KEY ix_draw_winners_gen (event_id, generation, status)');
        }
        if (!Schema::columnExists($db, 'draw_prizes', 'retired')) {
            $db->exec('ALTER TABLE draw_prizes ADD COLUMN retired TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function postCheck(Connection $db): array
    {
        $p = [];
        foreach ([['draw_events', 'generation'], ['draw_events', 'prize_mode'], ['draw_rounds', 'generation'], ['draw_winners', 'seq_no'], ['draw_prizes', 'retired']] as [$t, $c]) {
            if (!Schema::columnExists($db, $t, $c)) {
                $p[] = "{$t}.{$c} missing";
            }
        }
        return $p;
    }
};
