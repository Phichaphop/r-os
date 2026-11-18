<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M29 Lucky draw (7C ข้อ 29): event + rules, participant snapshot, prizes, draw rounds (never overwritten — a redraw is a
 * new round with its reason), winners (voided ones kept), and a log. The seed stays secret (encrypted) until results
 * are confirmed; its SHA-256 commitment is shown when the list is locked so anyone can verify the draw afterwards.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M29 Lucky draw: events, participants, prizes, rounds, winners, log';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS draw_events (
            {$std},
            owner_user_id BIGINT UNSIGNED NOT NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(1000) NULL,
            mode ENUM('wheel','race') NOT NULL DEFAULT 'wheel',
            no_repeat TINYINT(1) NOT NULL DEFAULT 1,
            event_at DATETIME(6) NULL,
            status ENUM('draft','ready','drawing','confirmed','archived') NOT NULL DEFAULT 'draft',
            law_ack TINYINT(1) NOT NULL DEFAULT 0,
            seed_enc TEXT NULL,
            commitment CHAR(64) NULL,
            snapshot_hash CHAR(64) NULL,
            locked_at DATETIME(6) NULL,
            locked_by BIGINT UNSIGNED NULL,
            confirmed_at DATETIME(6) NULL,
            confirmed_by BIGINT UNSIGNED NULL,
            witness VARCHAR(120) NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_draw_events_public (public_id),
            KEY ix_draw_events_owner (owner_user_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS draw_participants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL,
            code VARCHAR(40) NULL,
            name VARCHAR(120) NOT NULL,
            grp VARCHAR(80) NULL,
            PRIMARY KEY (id),
            KEY ix_draw_participants_event (event_id, position),
            CONSTRAINT fk_draw_participants_event FOREIGN KEY (event_id) REFERENCES draw_events (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS draw_prizes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            sort INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY ix_draw_prizes_event (event_id, sort),
            CONSTRAINT fk_draw_prizes_event FOREIGN KEY (event_id) REFERENCES draw_events (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS draw_rounds (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            prize_id BIGINT UNSIGNED NOT NULL,
            round_no INT UNSIGNED NOT NULL,
            draw_count INT UNSIGNED NOT NULL,
            candidates INT UNSIGNED NOT NULL,
            candidates_hash CHAR(64) NOT NULL,
            reason VARCHAR(300) NULL,
            replaces_winner_id BIGINT UNSIGNED NULL,
            request_token CHAR(32) NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_draw_rounds_no (event_id, round_no),
            UNIQUE KEY uq_draw_rounds_token (event_id, request_token),
            CONSTRAINT fk_draw_rounds_event FOREIGN KEY (event_id) REFERENCES draw_events (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS draw_winners (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            round_id BIGINT UNSIGNED NOT NULL,
            prize_id BIGINT UNSIGNED NOT NULL,
            participant_id BIGINT UNSIGNED NOT NULL,
            pick_no INT UNSIGNED NOT NULL,
            status ENUM('active','void') NOT NULL DEFAULT 'active',
            void_reason VARCHAR(300) NULL,
            voided_by BIGINT UNSIGNED NULL,
            voided_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            KEY ix_draw_winners_event (event_id, prize_id, status),
            CONSTRAINT fk_draw_winners_round FOREIGN KEY (round_id) REFERENCES draw_rounds (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS draw_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            detail_json TEXT NULL,
            actor_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_draw_log_event (event_id, id)
        ) {$o}");
    }
};
