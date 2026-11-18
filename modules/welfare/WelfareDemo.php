<?php

declare(strict_types=1);

namespace Blue\Modules\Welfare;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * DEC-34 demo data for phase 6D (installs with demo data, once): DEMO1 gets an earning component WELFARE (manual,
 * made by payroll.maker, activated by payroll.checker — NOT taxable / social-security base in the demo; the real
 * treatment is Tax's decision) and two welfare types written by hr.demo1 and activated by hr.head1, with made-up limits
 * labelled DEMO: MED (medical, 20,000 a year, paid with payroll) and FUNERAL (assistance, 5,000 a claim, at most 2 a
 * year, paid by bank transfer). No claims are seeded.
 */
final class WelfareDemo
{
    public static function run(Connection $db, Clock $clock): int
    {
        if (!$db->tableExists('wf_types') || !$db->tableExists('pay_components')) {
            return 0;
        }
        $le = $db->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1' AND is_demo = 1");
        $hr = $db->scalar("SELECT id FROM users WHERE username = 'hr.demo1' AND is_demo = 1");
        $head = $db->scalar("SELECT id FROM users WHERE username = 'hr.head1' AND is_demo = 1");
        $maker = $db->scalar("SELECT id FROM users WHERE username = 'payroll.maker' AND is_demo = 1");
        $checker = $db->scalar("SELECT id FROM users WHERE username = 'payroll.checker' AND is_demo = 1");
        if ($le === null || $hr === null || $head === null || $maker === null || $checker === null || $db->scalar('SELECT id FROM wf_types WHERE legal_entity_id = ? LIMIT 1', [$le]) !== null) {
            return 0;
        }
        $now = $clock->now()->format('Y-m-d H:i:s.u');
        if ($db->scalar("SELECT id FROM pay_components WHERE legal_entity_id = ? AND code = 'WELFARE'", [$le]) === null) {
            $db->run(
                "INSERT INTO pay_components (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, name_th, name_en, kind, method, taxable, sso_base, statutory, sort_order, status, activated_by, activated_at, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, 'WELFARE', 'เงินสวัสดิการ (DEMO)', 'Welfare (DEMO)', 'earning', 'manual', 0, 0, 'none', 45, 'active', ?, ?, 1)",
                [Ulid::generate($clock), $now, $maker, $now, $maker, $le, $checker, $now]
            );
        }
        $types = [
            ['MED', 'ค่ารักษาพยาบาล (DEMO)', 'Medical expenses (DEMO)', 'medical', '20000.0000', null, null, 90, null, 'payroll', 'WELFARE', 'ระเบียบสวัสดิการ (DEMO) หมวดค่ารักษาพยาบาล — วงเงินสมมุติ'],
            ['FUNERAL', 'ช่วยเหลืองานศพครอบครัว (DEMO)', 'Family funeral support (DEMO)', 'assistance', null, '5000.0000', 2, 60, 119, 'payment', null, 'ระเบียบสวัสดิการ (DEMO) หมวดเงินช่วยเหลือ — วงเงินสมมุติ'],
        ];
        foreach ($types as [$code, $th, $en, $cat, $year, $event, $max, $window, $service, $payout, $comp, $ref]) {
            $db->run(
                "INSERT INTO wf_types (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, version_no, name_th, name_en, category, limit_year, limit_event, max_claims, window_days, min_service_days,
                    receipt_required, payout, component_code, policy_ref, effective_from, status, activated_by, activated_at, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, '2026-01-01', 'active', ?, ?, 1)",
                [Ulid::generate($clock), $now, $hr, $now, $hr, $le, $code, $th, $en, $cat, $year, $event, $max, $window, $service, $payout, $comp, $ref, $head, $now]
            );
        }
        return count($types);
    }
}
