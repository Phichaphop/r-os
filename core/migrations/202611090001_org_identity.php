<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * Phase 2 — Organization & Identity (P0-03 §2, P0-04 §3–4, DEC-10/19/21/51).
 *
 * Organization: organizations → legal_entities → sites; departments scoped to a legal entity.
 * Identity:     users (lifecycle), user_sessions, user_tokens (activation / reset OTP), user_mfa_factors.
 * Access:       modules, permissions, roles, role_permissions, user_role_assignments, access_scopes (deny-by-default).
 * Security:     auth_attempts (rate limit / lockout), audit_events (append-only), mail_outbox (dev mailbox).
 * Names that users see in two languages live in `translations` (P0-03 §0 — never use display names as keys).
 * Every index stays under 767 bytes (older MariaDB / XAMPP row formats).
 */

return new class extends Migration {
    private const TABLES = [
        'organizations', 'legal_entities', 'sites', 'departments', 'translations',
        'users', 'user_sessions', 'user_tokens', 'user_mfa_factors',
        'modules', 'permissions', 'roles', 'role_permissions', 'user_role_assignments', 'access_scopes',
        'auth_attempts', 'audit_events', 'mail_outbox',
    ];

    public function description(): string
    {
        return 'Organization, identity, RBAC + access scopes, auth attempts, audit events, mail outbox';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();

        $db->exec("CREATE TABLE IF NOT EXISTS organizations (
            {$std},
            code VARCHAR(30) NOT NULL,
            default_locale VARCHAR(10) NOT NULL DEFAULT 'th',
            default_timezone VARCHAR(40) NOT NULL DEFAULT 'Asia/Bangkok',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_organizations_public (public_id),
            UNIQUE KEY uq_organizations_code (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS legal_entities (
            {$std},
            organization_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            country_code CHAR(2) NOT NULL DEFAULT 'TH',
            base_currency CHAR(3) NOT NULL DEFAULT 'THB',
            fiscal_year_start_month TINYINT UNSIGNED NOT NULL DEFAULT 1,
            registration_no VARCHAR(40) NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_legal_entities_public (public_id),
            UNIQUE KEY uq_legal_entities_code (organization_id, code),
            CONSTRAINT fk_le_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS sites (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(30) NOT NULL,
            site_type ENUM('hq','branch','factory','warehouse','office') NOT NULL DEFAULT 'branch',
            timezone VARCHAR(40) NOT NULL DEFAULT 'Asia/Bangkok',
            branch_no VARCHAR(10) NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sites_public (public_id),
            UNIQUE KEY uq_sites_code (legal_entity_id, code),
            CONSTRAINT fk_sites_le FOREIGN KEY (legal_entity_id) REFERENCES legal_entities (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS departments (
            {$std},
            legal_entity_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NULL,
            parent_id BIGINT UNSIGNED NULL,
            code VARCHAR(30) NOT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_departments_public (public_id),
            UNIQUE KEY uq_departments_code (legal_entity_id, code),
            KEY ix_departments_site (site_id),
            CONSTRAINT fk_dept_le FOREIGN KEY (legal_entity_id) REFERENCES legal_entities (id),
            CONSTRAINT fk_dept_site FOREIGN KEY (site_id) REFERENCES sites (id),
            CONSTRAINT fk_dept_parent FOREIGN KEY (parent_id) REFERENCES departments (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS translations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(40) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(10) NOT NULL,
            field VARCHAR(40) NOT NULL,
            value VARCHAR(255) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_translations (entity_type, entity_id, locale, field)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            {$std},
            username VARCHAR(60) NOT NULL,
            email VARCHAR(160) NULL,
            display_name VARCHAR(120) NOT NULL,
            employee_id BIGINT UNSIGNED NULL,
            password_hash VARCHAR(255) NULL,
            status ENUM('invited','active','suspended','locked','offboarding','deactivated') NOT NULL DEFAULT 'invited',
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            locale VARCHAR(10) NULL,
            mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
            failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME(6) NULL,
            last_login_at DATETIME(6) NULL,
            password_changed_at DATETIME(6) NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_public (public_id),
            UNIQUE KEY uq_users_username (username),
            UNIQUE KEY uq_users_email (email)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS user_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash BINARY(32) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            last_seen_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            mfa_verified_at DATETIME(6) NULL,
            reauth_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            revoked_reason VARCHAR(40) NULL,
            ip_hash BINARY(32) NULL,
            user_agent VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_sessions_public (public_id),
            UNIQUE KEY uq_user_sessions_token (token_hash),
            KEY ix_user_sessions_user (user_id, revoked_at),
            CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS user_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            purpose ENUM('activation','reset_link','reset_otp') NOT NULL,
            token_hash BINARY(32) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_tokens_hash (token_hash),
            KEY ix_user_tokens_user (user_id, purpose),
            CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS user_mfa_factors (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type ENUM('totp') NOT NULL DEFAULT 'totp',
            secret_enc VARBINARY(255) NOT NULL,
            confirmed_at DATETIME(6) NULL,
            last_used_step BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_mfa_user (user_id),
            CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS modules (
            code VARCHAR(40) NOT NULL,
            version VARCHAR(20) NOT NULL,
            pack VARCHAR(40) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS permissions (
            code VARCHAR(100) NOT NULL,
            module_code VARCHAR(40) NOT NULL,
            risk ENUM('normal','high') NOT NULL DEFAULT 'normal',
            PRIMARY KEY (code),
            KEY ix_permissions_module (module_code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(20) NOT NULL,
            module_code VARCHAR(40) NOT NULL DEFAULT 'core',
            default_scope ENUM('self','team','site','legal_entity','organization') NOT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 1,
            high_risk TINYINT(1) NOT NULL DEFAULT 0,
            requires_mfa TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_roles_code (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id BIGINT UNSIGNED NOT NULL,
            permission_code VARCHAR(100) NOT NULL,
            PRIMARY KEY (role_id, permission_code),
            CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id),
            CONSTRAINT fk_rp_perm FOREIGN KEY (permission_code) REFERENCES permissions (code)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS user_role_assignments (
            {$std},
            user_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            status ENUM('pending_approval','active','revoked','rejected') NOT NULL DEFAULT 'active',
            valid_from DATE NOT NULL,
            valid_to DATE NULL,
            delegated_from_user_id BIGINT UNSIGNED NULL,
            requested_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME(6) NULL,
            reason VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ura_public (public_id),
            KEY ix_ura_user (user_id, status),
            CONSTRAINT fk_ura_user FOREIGN KEY (user_id) REFERENCES users (id),
            CONSTRAINT fk_ura_role FOREIGN KEY (role_id) REFERENCES roles (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS access_scopes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            assignment_id BIGINT UNSIGNED NOT NULL,
            scope_level ENUM('organization','legal_entity','site','department') NOT NULL,
            scope_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_access_scopes (assignment_id, scope_level, scope_id),
            CONSTRAINT fk_scope_assignment FOREIGN KEY (assignment_id) REFERENCES user_role_assignments (id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            point VARCHAR(20) NOT NULL,
            key_hash BINARY(32) NOT NULL,
            success TINYINT(1) NOT NULL,
            attempted_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_auth_attempts (point, key_hash, attempted_at)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS audit_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(26) NOT NULL,
            occurred_at DATETIME(6) NOT NULL,
            actor_type ENUM('user','system','anonymous') NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            on_behalf_of_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            target_type VARCHAR(40) NULL,
            target_id VARCHAR(40) NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            outcome ENUM('success','denied','failure') NOT NULL,
            reason VARCHAR(255) NULL,
            correlation_id CHAR(26) NOT NULL,
            ip_hash BINARY(32) NULL,
            diff_json JSON NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_audit_public (public_id),
            KEY ix_audit_time (occurred_at),
            KEY ix_audit_actor (actor_id, occurred_at),
            KEY ix_audit_action (action, occurred_at),
            KEY ix_audit_target (target_type, target_id)
        ) {$o}");

        $db->exec("CREATE TABLE IF NOT EXISTS mail_outbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            to_address VARCHAR(160) NOT NULL,
            subject VARCHAR(200) NOT NULL,
            body TEXT NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'dev_mailbox',
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_mail_outbox_expires (expires_at)
        ) {$o}");
    }

    public function postCheck(Connection $db): array
    {
        return Schema::expectTables($db, self::TABLES);
    }

    public function recovery(): string
    {
        return 'rollback';
    }

    public function down(Connection $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $db->exec("DROP TABLE IF EXISTS `{$t}`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
};
