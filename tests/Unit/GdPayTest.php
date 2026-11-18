<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Support\Money;
use Blue\Modules\Payroll\PayCalc;

/**
 * GD-PAY v1 (DEC-41, DEC-87): the payroll engine against the golden dataset worked out by an independent reference
 * calculation (tests/fixtures/gd-pay/build_gd_pay.py — Python Decimal, not the PHP code). Status of the dataset:
 * AI-prepared — unverified, until a Payroll Checker signs docs/GD-PAY.md. Every value is a TEST value.
 */
final class GdPayTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/gd-pay/gd-pay.json'), true);
        $out = [];
        foreach ((array) $data['cases'] as $c) {
            $out[(string) $c['id']] = [$c];
        }
        return $out;
    }

    private static function u(string $baht): int
    {
        return Money::parse($baht, 4) ?? 0;
    }

    /**
     * @dataProvider cases
     * @param array<string, mixed> $c
     */
    public function testCase(array $c): void
    {
        $byCode = [];
        foreach ($c['components'] as $k) {
            $byCode[(string) $k['code']] = $k;
        }
        $e = $c['employee'];
        $p = $e['profile'];
        $emp = [
            'employee_id' => 1, 'code' => 'GD', 'name' => 'GD-PAY', 'site_id' => null, 'days_employed' => (int) $e['days_employed'],
            'profile' => ['pid' => 'gd-profile', 'basis' => (string) $p['basis'], 'base' => self::u((string) $p['base']),
                'items' => array_map(static fn (array $it): array => ['component_id' => (int) $byCode[$it[0]]['id'], 'code' => (string) $it[0], 'amount' => self::u((string) $it[1])], (array) $p['items']),
                'has_bank' => true, 'effective_from' => '2026-10-01', 'changed_in_period' => false],
            'time' => $e['time'], 'time_ref' => $e['time'] !== null ? 'gd-time' : null,
            'ot' => array_map(static fn (array $o): array => ['multiplier' => (string) $o['multiplier'], 'minutes' => (int) $o['minutes'], 'batch' => 'B1'], (array) $e['ot']),
            'manual' => array_map(static fn (array $m): array => ['component_id' => (int) $byCode[$m['code']]['id'], 'code' => (string) $m['code'], 'amount' => self::u((string) $m['amount']), 'note' => 'GD', 'ref' => 'gd-manual'], (array) $e['manual']),
            'welfare' => array_map(static fn (array $w): array => ['code' => (string) $w['code'], 'amount' => self::u((string) $w['amount']), 'ref' => (string) $w['ref'], 'note' => 'GD'], (array) $e['welfare']),
            'tax' => ['ytd_taxable' => self::u((string) $e['tax']['ytd_taxable']), 'ytd_wht' => self::u((string) $e['tax']['ytd_wht']), 'ytd_sso' => self::u((string) $e['tax']['ytd_sso']),
                'month' => (int) $e['tax']['month'], 'declared' => (array) $e['tax']['declared'], 'declared_ok' => (bool) $e['tax']['declared_ok']],
        ];
        $original = [];
        foreach ((array) $c['original'] as $code => $amount) {
            $original[(string) $code] = ['amount' => self::u((string) $amount), 'kind' => (string) $byCode[$code]['kind'], 'component_id' => (int) $byCode[$code]['id']];
        }
        $in = ['mode' => (string) $c['mode'], 'period' => ['start' => '2026-10-01', 'end' => '2026-10-31', 'days' => (int) $c['period_days'], 'pay_date' => '2026-10-31'],
            'components' => $c['components'], 'employees' => [$emp], 'has_time' => $e['time'] !== null, 'original' => ['GD' => $original], 'correction_of' => 'GD original', 'work_out_tax' => (bool) ($c['work_out_tax'] ?? true)];
        $out = PayCalc::run($in, (array) $c['config']);
        $r = $out['employees'][0] ?? null;
        self::assertNotNull($r, $c['id'] . ': person in the result');
        $got = array_map(static fn (array $l): array => ['code' => (string) $l['code'], 'amount' => Money::format((int) $l['amount'], 2, false), 'source_type' => (string) $l['source_type']], $r['lines']);
        self::assertSame($c['expected']['lines'], $got, $c['id'] . ' ' . $c['title']);
        foreach (['gross', 'deductions', 'net', 'employer'] as $k) {
            self::assertSame((string) $c['expected']['totals'][$k], Money::format((int) $r[$k], 2, false), $c['id'] . ' ' . $k);
        }
        $keys = array_map(static fn (array $w): string => (string) $w['key'], $r['warnings']);
        foreach ((array) $c['expected']['warnings'] as $w) {
            self::assertContains($w, $keys, $c['id'] . ' warning');
        }
        // blocking warnings appear only where the dataset expects them
        $blocking = array_values(array_map(static fn (array $w): string => (string) $w['key'], array_filter($r['warnings'], static fn (array $w): bool => (bool) $w['blocking'])));
        self::assertSame(array_values(array_intersect((array) $c['expected']['warnings'], $blocking)), $blocking, $c['id'] . ' blocking');
    }

    public function testDatasetStatus(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/gd-pay/gd-pay.json'), true);
        self::assertSame('AI-prepared — unverified', $data['status'], 'only a Payroll Checker may change the status (DEC-41)');
        self::assertGreaterThanOrEqual(16, count($data['cases']));
    }
}
