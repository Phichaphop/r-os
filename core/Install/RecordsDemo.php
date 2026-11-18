<?php

declare(strict_types=1);

namespace Blue\Core\Install;

use Blue\Core\Database\Connection;
use Blue\Core\Records\TemplateService;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 3B, added once to installs that have demo data (also when upgrading from phase 3A):
 * a work calendar and a shuttle service calendar for DEMO1, and one published letter template.
 * No official public holidays are invented — the only holiday is an obviously fictitious company day.
 */
final class RecordsDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        if ($le === null) {
            return 0;
        }
        $n = 0;
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        if ($db->scalar("SELECT id FROM calendars WHERE code = 'DEMO1-WORK'") === null) {
            $db->run(
                "INSERT INTO calendars (public_id, created_at, updated_at, code, name, calendar_type, scope_level, scope_id, weekly_pattern, is_demo)
                 VALUES (?, ?, ?, 'DEMO1-WORK', 'ปฏิทินวันทำงาน บริษัทตัวอย่าง (DEMO)', 'work', 'legal_entity', ?, '0111110', 1)",
                [Ulid::generate($clock), $now, $now, $le]
            );
            $work = (int) $db->scalar("SELECT id FROM calendars WHERE code = 'DEMO1-WORK'");
            $db->run(
                "INSERT INTO calendar_days (calendar_id, day_date, day_type, holiday_kind, name, note, source, created_at, updated_at)
                 VALUES (?, '2026-12-30', 'holiday', 'company', 'วันหยุดบริษัทตัวอย่าง (DEMO)', 'ข้อมูลจำลอง — ไม่ใช่วันหยุดราชการ', 'manual', ?, ?)",
                [$work, $now, $now]
            );
            $db->run(
                "INSERT INTO calendars (public_id, created_at, updated_at, code, name, calendar_type, scope_level, scope_id, base_calendar_id, weekly_pattern, is_demo)
                 VALUES (?, ?, ?, 'DEMO1-SHUTTLE', 'รถรับส่งพนักงาน (DEMO) — วิ่ง จันทร์–เสาร์', 'service', 'legal_entity', ?, ?, '0111111', 1)",
                [Ulid::generate($clock), $now, $now, $le, $work]
            );
            $n += 2;
        }
        if ($db->scalar("SELECT id FROM document_templates WHERE code = 'DEMO_EMPLOYMENT_CERT'") === null) {
            $db->run(
                "INSERT INTO document_templates (public_id, created_at, updated_at, code, document_class, legal_entity_id, name, locale, number_pattern, is_demo)
                 VALUES (?, ?, ?, 'DEMO_EMPLOYMENT_CERT', 'core.letter', NULL, 'หนังสือรับรองการทำงาน (ตัวอย่าง DEMO)', 'th', 'HRC-{YYYY}-{SEQ:4}', 1)",
                [Ulid::generate($clock), $now, $now]
            );
            $tid = (int) $db->scalar("SELECT id FROM document_templates WHERE code = 'DEMO_EMPLOYMENT_CERT'");
            $body = '<h1>หนังสือรับรองการทำงาน</h1>'
                . '<p class="indent text-justify">หนังสือฉบับนี้ให้ไว้เพื่อรับรองว่า <strong>{{employee_name}}</strong> ตำแหน่ง {{position}} '
                . 'เป็นพนักงานของ {{company.name}} ตั้งแต่วันที่ {{start_date}} จนถึงปัจจุบัน</p>'
                . '<p class="indent">ออกให้เพื่อ {{purpose}}</p>'
                . '<p class="small muted">ข้อมูลจำลองสำหรับทดสอบระบบ (DEMO) — ไม่ใช่เอกสารจริง</p>'
                . '<div class="signature"><p>ลงชื่อ ..........................................</p><p>({{signer_name}})</p><p>{{signer_title}}</p><p>{{doc.date_long}}</p></div>';
            $fields = TemplateService::parseFields("employee_name | ชื่อ-นามสกุลพนักงาน | text | required\nposition | ตำแหน่ง | text | required\nstart_date | วันที่เริ่มงาน | date | required\npurpose | วัตถุประสงค์ | text | required\nsigner_name | ชื่อผู้ลงนาม | text | required\nsigner_title | ตำแหน่งผู้ลงนาม | text | required")[0];
            $db->run(
                "INSERT INTO document_template_versions (public_id, template_id, version_no, status, title, body_html, fields_json, show_header, show_footer, body_sha256,
                    change_reason, created_at, updated_at, published_at) VALUES (?, ?, 1, 'published', 'หนังสือรับรองการทำงาน', ?, ?, 1, 1, ?, 'DEMO template (DEC-34)', ?, ?, ?)",
                [Ulid::generate($clock), $tid, $body, json_encode($fields, JSON_UNESCAPED_UNICODE), hash('sha256', $body), $now, $now, $now]
            );
            $n++;
        }
        return $n;
    }
}
