<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 9D-1 (DEC-97): M41 suggestions / Kaizen and surveys — the company's published scoring criteria, ideas (named or
 * anonymous: an anonymous idea keeps no submitter, only a hash of the tracking code it was given), criterion scores,
 * votes, the idea history; surveys with questions, who has answered (no link to the answer) and the answers.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Kaizen: criteria, ideas, scores, votes, events, surveys, questions, respondents, responses';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS kz_criteria (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            weight SMALLINT UNSIGNED NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY uq_kz_criteria_public (public_id), UNIQUE KEY uq_kz_criteria_name (legal_entity_id, name)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kz_ideas (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            ref VARCHAR(20) NOT NULL,
            title VARCHAR(160) NOT NULL,
            problem TEXT NOT NULL,
            proposal TEXT NOT NULL,
            benefit TEXT NULL,
            anonymous TINYINT(1) NOT NULL DEFAULT 0,
            submitter_id BIGINT UNSIGNED NULL,
            track_hash CHAR(64) NULL,
            photo_pid CHAR(26) NULL,
            status ENUM('submitted','screening','evaluation','accepted','rejected','implementing','verified','closed') NOT NULL DEFAULT 'submitted',
            owner_id BIGINT UNSIGNED NULL,
            score DECIMAL(5,2) NULL,
            decision_note VARCHAR(1000) NULL,
            plan VARCHAR(1000) NULL,
            due_date DATE NULL,
            result_note VARCHAR(1000) NULL,
            reward VARCHAR(300) NULL,
            reward_status ENUM('none','proposed','approved','rejected') NOT NULL DEFAULT 'none',
            reward_by BIGINT UNSIGNED NULL,
            reward_decided_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_kz_ideas_public (public_id), UNIQUE KEY uq_kz_ideas_ref (legal_entity_id, ref),
            UNIQUE KEY uq_kz_ideas_track (track_hash), KEY ix_kz_ideas_status (legal_entity_id, status), KEY ix_kz_ideas_submitter (submitter_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kz_scores (
            idea_id BIGINT UNSIGNED NOT NULL,
            criterion_id BIGINT UNSIGNED NOT NULL,
            score TINYINT UNSIGNED NOT NULL,
            weight SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (idea_id, criterion_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kz_votes (
            idea_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (idea_id, user_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kz_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            idea_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            note VARCHAR(1000) NULL,
            internal TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_kz_events_idea (idea_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kz_surveys (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            intro VARCHAR(2000) NULL,
            audience_json VARCHAR(2000) NOT NULL,
            opens_on DATE NOT NULL,
            closes_on DATE NOT NULL,
            anonymous TINYINT(1) NOT NULL DEFAULT 1,
            min_results SMALLINT UNSIGNED NOT NULL,
            status ENUM('draft','open','closed','analyzed') NOT NULL DEFAULT 'draft',
            summary VARCHAR(4000) NULL,
            notified TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_kz_surveys_public (public_id), KEY ix_kz_surveys_status (legal_entity_id, status)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kz_questions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            survey_id BIGINT UNSIGNED NOT NULL,
            sort SMALLINT UNSIGNED NOT NULL,
            kind ENUM('scale','enps','choice','multi','text') NOT NULL,
            text VARCHAR(500) NOT NULL,
            options VARCHAR(2000) NULL,
            required TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), KEY ix_kz_questions_survey (survey_id, sort)
        ) {$o}");
        // who has answered — no time, no order, nothing that joins to the answer itself
        $db->exec("CREATE TABLE IF NOT EXISTS kz_respondents (
            survey_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (survey_id, user_id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS kz_responses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            survey_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            submitted_on DATE NOT NULL,
            answers_json TEXT NOT NULL,
            PRIMARY KEY (id), KEY ix_kz_responses_survey (survey_id)
        ) {$o}");
    }
};
