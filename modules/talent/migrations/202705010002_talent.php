<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M49 Talent pool (phase 5E, DEC-76): profiles from applications (with the applicant's consent), uploads, walk-ins and
 * referrals — source, consent / basis, retention date — plus contact history and links to job postings (M08).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M49 Talent pool: profiles and contact history';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS tal_profiles (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            email VARCHAR(160) NULL,
            phone VARCHAR(30) NULL,
            dup_email BINARY(32) NULL,
            dup_phone BINARY(32) NULL,
            positions VARCHAR(300) NOT NULL DEFAULT '',
            skills VARCHAR(1000) NOT NULL DEFAULT '',
            experience_years DECIMAL(4,1) NULL,
            education_level VARCHAR(20) NULL,
            source ENUM('applicant','upload','walk_in','referral') NOT NULL,
            referred_by VARCHAR(160) NULL,
            candidate_id BIGINT UNSIGNED NULL,
            status ENUM('new','reviewed','pool','linked','contacted','hired','inactive') NOT NULL DEFAULT 'new',
            starred TINYINT(1) NOT NULL DEFAULT 0,
            consent_at DATE NOT NULL,
            consent_basis VARCHAR(300) NOT NULL,
            retention_until DATE NULL,
            cv_document_pid CHAR(26) NULL,
            cv_filename VARCHAR(200) NULL,
            notes VARCHAR(2000) NULL,
            anonymized_at DATETIME(6) NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_tal_public (public_id), KEY ix_tal_le (legal_entity_id, status), KEY ix_tal_dup_e (dup_email), KEY ix_tal_dup_p (dup_phone)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS tal_contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            note VARCHAR(1000) NULL,
            actor_id BIGINT UNSIGNED NULL,
            occurred_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id), KEY ix_tal_contacts (profile_id, id),
            CONSTRAINT fk_tal_contacts_profile FOREIGN KEY (profile_id) REFERENCES tal_profiles (id)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, ['tal_profiles', 'tal_contacts']);
    }
};
