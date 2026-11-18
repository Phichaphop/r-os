<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * M31 External links: link_items (live values + pending edit), link_revisions (published history, append-only),
 * link_clicks (counts per link per day — no user ids, 7C "รายงานคลิกต้องไม่เก็บข้อมูลเกินจำเป็น").
 * A few common services are added as DRAFTS — an administrator reviews the risk and publishes them (M31 B1–B4).
 */

return new class extends Migration {
    public function description(): string
    {
        return 'M31 external links: items, revisions, daily click counts (+ draft suggestions)';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS link_items (
            {$std},
            name_th VARCHAR(120) NOT NULL,
            name_en VARCHAR(120) NULL,
            description VARCHAR(300) NULL,
            url VARCHAR(1000) NOT NULL,
            host VARCHAR(255) NOT NULL,
            category VARCHAR(60) NOT NULL,
            icon VARCHAR(40) NOT NULL DEFAULT 'external-link',
            open_new_tab TINYINT(1) NOT NULL DEFAULT 1,
            audience_roles VARCHAR(400) NULL,
            legal_entity_id BIGINT UNSIGNED NULL,
            owner_user_id BIGINT UNSIGNED NULL,
            purpose VARCHAR(300) NULL,
            risk_note VARCHAR(500) NULL,
            status ENUM('draft','published','disabled','archived') NOT NULL DEFAULT 'draft',
            revision INT UNSIGNED NOT NULL DEFAULT 0,
            draft_json TEXT NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME(6) NULL,
            published_at DATETIME(6) NULL,
            sort_order INT NOT NULL DEFAULT 100,
            PRIMARY KEY (id),
            UNIQUE KEY uq_link_items_public (public_id),
            KEY ix_link_items_status (status, category, sort_order)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS link_revisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            link_id BIGINT UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL,
            snapshot_json TEXT NOT NULL,
            reason VARCHAR(300) NULL,
            changed_by BIGINT UNSIGNED NULL,
            changed_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY ix_link_revisions_link (link_id, id),
            CONSTRAINT fk_link_revisions_link FOREIGN KEY (link_id) REFERENCES link_items (id)
        ) {$o}");
        $db->exec("CREATE TABLE IF NOT EXISTS link_clicks (
            link_id BIGINT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            clicks INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (link_id, day),
            CONSTRAINT fk_link_clicks_link FOREIGN KEY (link_id) REFERENCES link_items (id)
        ) {$o}");

        $suggest = [
            ['Google Translate', 'แปลภาษา', 'https://translate.google.com/', 'translate', 'language'],
            ['Google Maps', 'แผนที่', 'https://www.google.com/maps', 'maps', 'map-pin'],
            ['Gmail', 'อีเมล', 'https://mail.google.com/', 'email', 'send'],
            ['Canva', 'ออกแบบกราฟิก', 'https://www.canva.com/', 'design', 'palette'],
            ['Photopea', 'แต่งรูป', 'https://www.photopea.com/', 'design', 'palette'],
            ['iLovePDF', 'เครื่องมือ PDF ภายนอก', 'https://www.ilovepdf.com/', 'documents', 'file-type-pdf'],
        ];
        $n = 0;
        foreach ($suggest as [$name, $desc, $url, $cat, $icon]) {
            if ($db->scalar('SELECT id FROM link_items WHERE url = ?', [$url]) !== null) {
                continue;
            }
            $db->run(
                "INSERT INTO link_items (public_id, created_at, updated_at, name_th, name_en, description, url, host, category, icon, open_new_tab, risk_note, status, sort_order)
                 VALUES (?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?, ?, ?, ?, ?, ?, ?, 1, ?, 'draft', ?)",
                [\Blue\Core\Support\Ulid::generate(), $name, $name, $desc, $url, (string) parse_url($url, PHP_URL_HOST), $cat, $icon,
                    'บริการภายนอก — ห้ามอัปโหลดหรือวางข้อมูลลับ/ข้อมูลส่วนบุคคล (S01 6.24.1) · ร่างจากระบบ ต้องทบทวนก่อนเผยแพร่', ($n + 1) * 10]
            );
            $n++;
        }
    }
};
