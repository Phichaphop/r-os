<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Modules\ModuleRegistry;
use Blue\Modules\Leave\LeavePolicy;

/** Phase 4A (DEC-63) — leave policy rules (values are test values only) and the new module manifest keys. */
final class LeavePolicyTest extends TestCase
{
    public function testValidateSeparatesStatutoryAndCompanyAndRefusesBadInput(): void
    {
        [$p, $e] = LeavePolicy::validate(['types' => ['ANNUAL' => ['legal_minimum_days' => '6', 'entitlement_days' => '10', 'group_text' => 'daily=7, PART_TIME : 6.5', 'half_day' => '1']]], ['ANNUAL']);
        self::assertSame([], $e);
        self::assertSame(['DAILY' => 7.0, 'PART_TIME' => 6.5], $p['types']['ANNUAL']['group_entitlements']);
        self::assertSame(6.0, $p['types']['ANNUAL']['legal_minimum_days']);
        self::assertTrue($p['types']['ANNUAL']['half_day']);

        $cases = [
            [['legal_minimum_days' => '6', 'entitlement_days' => '5'], 'ANNUAL.entitlement_days', 'leave.policy_error.below_legal'],
            [['legal_minimum_days' => '6'], 'ANNUAL.entitlement_days', 'leave.policy_error.legal_needs_quota'],
            [['entitlement_days' => '2.3'], 'ANNUAL.entitlement_days', 'leave.policy_error.days'],
            [['entitlement_days' => '400'], 'ANNUAL.entitlement_days', 'leave.policy_error.days'],
            [['legal_minimum_days' => '6', 'entitlement_days' => '10', 'group_text' => 'DAILY=3'], 'ANNUAL.group_entitlements', 'leave.policy_error.below_legal'],
            [['entitlement_days' => '10', 'group_text' => 'DAILY'], 'ANNUAL.group_entitlements', 'leave.policy_error.group'],
            [['carry_forward_max_days' => '3', 'min_notice_days' => '1'], 'ANNUAL.carry_forward_max_days', 'leave.policy_error.carry_needs_quota'],
            [['entitlement_days' => '10', 'min_service_months' => '1.5'], 'ANNUAL.min_service_months', 'leave.policy_error.whole'],
        ];
        foreach ($cases as [$in, $key, $msg]) {
            [$p2, $e2] = LeavePolicy::validate(['types' => ['ANNUAL' => $in]], ['ANNUAL']);
            self::assertNull($p2, json_encode($in) ?: '');
            self::assertSame($msg, $e2[$key] ?? null, json_encode($in) ?: '');
        }
        [, $e3] = LeavePolicy::validate(['types' => ['NOPE' => ['entitlement_days' => '1']]], ['ANNUAL']);
        self::assertSame('leave.policy_error.type', $e3['NOPE.code']);
        [, $e4] = LeavePolicy::validate(['types' => ['ANNUAL' => ['half_day' => '1', 'proration' => 'none']]], ['ANNUAL']);
        self::assertSame('leave.policy_error.empty', $e4['_form'], 'a checkbox alone is not a rule');
        [$p5] = LeavePolicy::validate(['types' => ['UNPAID' => ['entitlement_days' => '', 'min_notice_days' => '3']]], ['UNPAID']);
        self::assertNull($p5['types']['UNPAID']['entitlement_days'], 'no quota tracked');
    }

    public function testRuleGrantProrationAndService(): void
    {
        $policy = ['types' => ['ANNUAL' => ['legal_minimum_days' => 6.0, 'entitlement_days' => 15.0, 'group_entitlements' => ['DAILY' => 8.0], 'proration' => 'monthly',
            'min_service_months' => 0, 'carry_forward_max_days' => 0.0, 'allow_negative' => false, 'half_day' => true, 'min_notice_days' => null, 'max_backdate_days' => null, 'document_from_days' => null]]];
        $r = LeavePolicy::rule($policy, 'ANNUAL', 'MONTHLY');
        self::assertSame(15.0, $r['entitlement']);
        self::assertSame(8.0, LeavePolicy::rule($policy, 'ANNUAL', 'DAILY')['entitlement']);
        self::assertNull(LeavePolicy::rule($policy, 'SICK', 'MONTHLY'));
        self::assertSame(['units' => 15.0, 'statutory' => 6.0], LeavePolicy::grant($r, 2026, '2020-05-01'), 'hired before the year: full grant');
        self::assertSame(['units' => 11.0, 'statutory' => 4.5], LeavePolicy::grant($r, 2026, '2026-04-10'), 'April–December = 9/12 → 11.25 rounded down to half days; statutory 4.5');
        self::assertSame(['units' => 0.0, 'statutory' => 0.0], LeavePolicy::grant($r, 2026, '2027-01-02'));
        $r['proration'] = 'none';
        self::assertSame(15.0, LeavePolicy::grant($r, 2026, '2026-12-01')['units']);
        $r['entitlement'] = null;
        self::assertNull(LeavePolicy::grant($r, 2026, '2020-01-01'));
        self::assertSame(10, LeavePolicy::serviceMonths('2025-12-15', '2026-11-14'), 'one day short of 11 months');
        self::assertSame(11, LeavePolicy::serviceMonths('2025-12-15', '2026-11-15'), 'exactly 11 months on the 15th');
        self::assertSame(0, LeavePolicy::serviceMonths('2026-11-20', '2026-11-10'));
    }

    public function testManifestValidatorKnowsWorkflowsOwnersImportsAndDemo(): void
    {
        $base = ['code' => 'demo_mod', 'version' => '1', 'pack' => 'x', 'name' => ['th' => 'ก', 'en' => 'a'], 'permissions' => ['demo_mod.use' => 'normal'],
            'config' => ['demo_mod.k' => ['c', 'json', null, 'organization', [], 'high', 'LAW', false]]];
        $ok = $base + [
            'workflows' => ['demo_mod.x' => ['subject' => 'demo_x', 'class' => 'Blue\\Modules\\DemoMod\\X', 'steps' => [['approver' => ['type' => 'manager', 'value' => 'demo_mod.use'], 'scope' => 'subject']]]],
            'config_owners' => ['demo_mod.k' => ['propose' => 'demo_mod.use', 'workflow' => 'demo_mod.x']],
            'imports' => ['Blue\\Modules\\DemoMod\\Imp'], 'demo' => 'Blue\\Modules\\DemoMod\\Seed::run',
        ];
        self::assertSame([], ModuleRegistry::validate($ok, 'demo_mod'));
        $bad = $ok;
        $bad['workflows']['demo_mod.x']['steps'][0]['approver']['type'] = 'anyone';
        $bad['workflows']['demo_mod.x']['class'] = 'Blue\\Modules\\Other\\X';
        $bad['config_owners']['demo_mod.k']['workflow'] = 'demo_mod.y';
        $bad['imports'] = ['Blue\\Core\\Evil'];
        $bad['demo'] = 'system';
        self::assertCount(4, ModuleRegistry::validate($bad, 'demo_mod'));
        foreach (['employees', 'leave'] as $m) {
            $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/' . $m . '/module.json'), true);
            self::assertSame([], ModuleRegistry::validate($manifest, $m), $m);
        }
    }
}
