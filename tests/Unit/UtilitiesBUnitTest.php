<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\FileType;
use Blue\Core\Files\FontInfo;
use Blue\Modules\Calc\CalcEngine;
use Blue\Modules\Calc\CalcRules;

/**
 * Phase 3U-B building blocks: rate-table validation and the legal calculators' arithmetic (M26), font file reading (M30).
 * Every table here is a MADE-UP TEST TABLE — the numbers only exercise the arithmetic and are not legal rates.
 */
final class UtilitiesBUnitTest extends TestCase
{
    private const SSO = ['employee_rate' => '3', 'employer_rate' => '3', 'wage_floor' => '1000', 'wage_ceiling' => '10000', 'rounding' => 'none'];
    private const SEV = ['tiers' => [['min_days' => '300', 'days_pay' => '60'], ['min_days' => '100', 'days_pay' => '20'], ['min_days' => '1000', 'days_pay' => '200']],
        'monthly_divisor' => '30', 'rounding' => 'none'];
    private const PIT = ['expense_rate' => '40', 'expense_cap' => '50000', 'personal_allowance' => '30000',
        'brackets' => [['up_to' => '100000', 'rate' => '0'], ['up_to' => '300000', 'rate' => '10'], ['up_to' => '', 'rate' => '20']],
        'allowances' => [['label' => 'ทดสอบ', 'cap' => '9000']]];

    /** @param array<string, mixed> $in */
    private static function table(string $type, array $in): array
    {
        [$t, $e] = CalcRules::validate($type, $in);
        self::assertSame([], $e, json_encode($e) ?: '');
        self::assertNotNull($t);
        return $t;
    }

    public function testRateTablesAreValidatedAndNormalized(): void
    {
        $sso = self::table('sso', self::SSO);
        self::assertSame(['employee_rate' => '3.0000', 'employer_rate' => '3.0000', 'wage_floor' => '1000.00', 'wage_ceiling' => '10000.00', 'rounding' => 'none'], $sso);
        self::assertSame(['wage_ceiling' => 'calc.rule_error.ceiling'], CalcRules::validate('sso', ['wage_ceiling' => '500'] + self::SSO)[1]);
        self::assertArrayHasKey('employee_rate', CalcRules::validate('sso', ['employee_rate' => '101'] + self::SSO)[1]);
        self::assertArrayHasKey('employee_rate', CalcRules::validate('sso', ['employee_rate' => '1.23456'] + self::SSO)[1], 'at most 4 decimals');
        self::assertArrayHasKey('rounding', CalcRules::validate('sso', ['rounding' => 'banker'] + self::SSO)[1]);
        self::assertNull(self::table('ewf', ['employee_rate' => '0.25', 'employer_rate' => '0.25', 'wage_ceiling' => '', 'rounding' => 'none'])['wage_ceiling'], 'EWF ceiling optional');

        $sev = self::table('severance', self::SEV);
        self::assertSame([100, 300, 1000], array_column($sev['tiers'], 'min_days'), 'tiers sorted');
        self::assertFalse($sev['count_end_day']);
        $form = self::table('severance', ['tiers' => ['min_days' => ['100', '', '300'], 'days_pay' => ['20', '', '60']], 'monthly_divisor' => '30', 'count_end_day' => '1', 'rounding' => 'nearest']);
        self::assertSame([['min_days' => 100, 'days_pay' => '20.00'], ['min_days' => 300, 'days_pay' => '60.00']], $form['tiers'], 'form layout tiers[field][] and blank rows');
        self::assertTrue($form['count_end_day']);
        self::assertArrayHasKey('tiers', CalcRules::validate('severance', ['tiers' => [['min_days' => '100', 'days_pay' => '20'], ['min_days' => '100', 'days_pay' => '30']]] + self::SEV)[1], 'duplicate minimum');
        self::assertArrayHasKey('tiers', CalcRules::validate('severance', ['tiers' => []] + self::SEV)[1]);
        self::assertArrayHasKey('monthly_divisor', CalcRules::validate('severance', ['monthly_divisor' => '0'] + self::SEV)[1]);

        $pit = self::table('pit', self::PIT);
        self::assertNull($pit['brackets'][2]['up_to']);
        self::assertArrayHasKey('brackets', CalcRules::validate('pit', ['brackets' => [['up_to' => '300000', 'rate' => '5'], ['up_to' => '100000', 'rate' => '10'], ['up_to' => '', 'rate' => '20']]] + self::PIT)[1], 'must ascend');
        self::assertArrayHasKey('brackets', CalcRules::validate('pit', ['brackets' => [['up_to' => '100000', 'rate' => '5'], ['up_to' => '200000', 'rate' => '10']]] + self::PIT)[1], 'last row is open-ended');
        self::assertArrayHasKey('allowances', CalcRules::validate('pit', ['allowances' => [['label' => '', 'cap' => '100']]] + self::PIT)[1]);
        self::assertSame(['type' => 'calc.rule_error.type'], CalcRules::validate('nope', [])[1]);
        $many = ['tiers' => array_map(static fn (int $i): array => ['min_days' => (string) ($i * 10 + 1), 'days_pay' => '1'], range(1, 30))] + self::SEV;
        self::assertCount(CalcRules::MAX_ROWS, self::table('severance', $many)['tiers'], 'rows are capped');
    }

    public function testSocialSecurityAndWelfareFundArithmetic(): void
    {
        $sso = self::table('sso', self::SSO);
        $hi = CalcEngine::sso($sso, ['wage' => '20,000']);
        self::assertSame(['employee' => '300.00', 'employer' => '300.00', 'total' => '600.00', 'base' => '10,000.00'], $hi['outputs'], 'ceiling');
        self::assertSame('calc.step.sso_base', $hi['steps'][1]['key']);
        self::assertSame('30.00', CalcEngine::sso($sso, ['wage' => '500'])['outputs']['employee'], 'floor');
        self::assertSame('99.99', CalcEngine::sso($sso, ['wage' => '3333'])['outputs']['employee'], 'satang');
        self::assertSame('100.00', CalcEngine::sso(['rounding' => 'nearest'] + $sso, ['wage' => '3333'])['outputs']['employee']);
        self::assertSame('99.00', CalcEngine::sso(['rounding' => 'down'] + $sso, ['wage' => '3333'])['outputs']['employee']);
        self::assertSame('100.00', CalcEngine::sso(['rounding' => 'up'] + $sso, ['wage' => '3300.01'])['outputs']['employee']);
        foreach (['', 'abc', '-1', '999999999999'] as $bad) {
            try {
                CalcEngine::sso($sso, ['wage' => $bad]);
                self::fail("wage {$bad} accepted");
            } catch (ValidationException $e) {
                self::assertSame(['wage' => 'calc.error.amount'], $e->errors);
            }
        }
        $ewf = self::table('ewf', ['employee_rate' => '0.25', 'employer_rate' => '0.5', 'wage_ceiling' => '', 'rounding' => 'none']);
        self::assertSame(['employee' => '30.86', 'employer' => '61.73', 'total' => '92.59', 'base' => '12,345.67'], CalcEngine::ewf($ewf, ['wage' => '12345.67'])['outputs']);
        self::assertSame('25.00', CalcEngine::ewf(['wage_ceiling' => '10000.00'] + $ewf, ['wage' => '50000'])['outputs']['employee']);
        self::assertSame(3, CalcEngine::pct(1000, '0.3'), 'half up: 1000 × 0.3% = 3');
        self::assertSame(2, CalcEngine::pct(500, '0.3'), '1.5 → 2');
    }

    public function testSeveranceTiersAndServiceLength(): void
    {
        $sev = self::table('severance', self::SEV);
        $none = CalcEngine::severance($sev, ['start' => '2020-01-01', 'end' => '2020-03-01', 'wage' => '30000', 'wage_type' => 'monthly']);
        self::assertSame(['amount' => '0.00', 'days' => '60', 'days_pay' => '0'], $none['outputs']);
        self::assertSame('calc.step.no_tier', $none['steps'][2]['key']);
        $one = CalcEngine::severance($sev, ['start' => '2020-01-01', 'end' => '2021-01-01', 'wage' => '30000', 'wage_type' => 'monthly']);
        self::assertSame(['amount' => '60,000.00', 'days' => '366', 'days_pay' => '60.00'], $one['outputs'], '366 days → tier 300 → 60 days × 1,000');
        self::assertSame(['y' => '1', 'm' => '0', 'd' => '0'], $one['steps'][0]['p']);
        self::assertSame('27,000.00', CalcEngine::severance($sev, ['start' => '2020-01-01', 'end' => '2021-01-01', 'wage' => '450', 'wage_type' => 'daily'])['outputs']['amount']);
        $endDay = self::table('severance', ['count_end_day' => '1'] + self::SEV);
        self::assertSame('0.00', CalcEngine::severance($sev, ['start' => '2020-01-01', 'end' => '2020-04-09', 'wage' => '450', 'wage_type' => 'daily'])['outputs']['amount'], '99 days');
        self::assertSame('9,000.00', CalcEngine::severance($endDay, ['start' => '2020-01-01', 'end' => '2020-04-09', 'wage' => '450', 'wage_type' => 'daily'])['outputs']['amount'], '99 + last day = 100');
        self::assertSame('1,033.33', CalcEngine::severance(['monthly_divisor' => 30] + $sev, ['start' => '2000-01-01', 'end' => '2001-01-01', 'wage' => '31000', 'wage_type' => 'monthly'])['steps'][1]['v']);
        $half = self::table('severance', ['tiers' => [['min_days' => '1', 'days_pay' => '7.5']]] + self::SEV);
        self::assertSame('3,375.00', CalcEngine::severance($half, ['start' => '2020-01-01', 'end' => '2020-02-01', 'wage' => '450', 'wage_type' => 'daily'])['outputs']['amount']);
        try {
            CalcEngine::severance($sev, ['start' => '2021-01-01', 'end' => '2020-01-01', 'wage' => '1', 'wage_type' => 'daily']);
            self::fail('end before start accepted');
        } catch (ValidationException $e) {
            self::assertSame(['end' => 'calc.error.end_before_start'], $e->errors);
        }
        $this->expectException(ValidationException::class);
        CalcEngine::severance($sev, ['start' => '2020-13-01', 'end' => '2021-01-01', 'wage' => '1']);
    }

    public function testIncomeTaxBracketsAndCappedAllowances(): void
    {
        $pit = self::table('pit', self::PIT);
        $r = CalcEngine::pit($pit, ['income' => '600000', 'allowance' => ['12000']]);
        self::assertSame(['tax' => '62,200.00', 'monthly' => '5,183.33', 'net' => '511,000.00', 'effective_rate' => '10.37'], $r['outputs']);
        $keys = array_column($r['steps'], 'key');
        self::assertSame(['calc.step.income', 'calc.step.expense', 'calc.step.personal', 'calc.step.allowance', 'calc.step.net', 'calc.step.bracket', 'calc.step.bracket', 'calc.step.bracket'], $keys);
        self::assertSame('-9,000.00', $r['steps'][3]['v'], 'allowance capped by the table');
        self::assertSame('-50,000.00', $r['steps'][1]['v'], 'expense capped');
        self::assertSame(['from' => '300,000', 'to' => '∞', 'rate' => '20'], $r['steps'][7]['p']);
        self::assertSame('0.00', CalcEngine::pit($pit, ['income' => '50000'])['outputs']['tax'], 'net below zero → 0');
        self::assertSame('20,000.00', CalcEngine::pit($pit, ['income' => '380000', 'allowance' => ['']])['outputs']['tax'], 'net exactly at a bracket limit');
        $this->expectException(ValidationException::class);
        CalcEngine::pit($pit, ['income' => '600000', 'allowance' => ['-5']]);
    }

    public function testPrizeWithholdingAndGrossUp(): void
    {
        $prize = self::table('prize', ['rate' => '5', 'threshold' => '1000', 'rounding' => 'none']); // MADE-UP rate
        $r = CalcEngine::prize($prize, ['value' => '10000', 'mode' => 'withhold']);
        self::assertSame(['value' => '10,000.00', 'tax' => '500.00', 'net' => '9,500.00', 'gross' => '10,000.00', 'payer_cost' => '10,000.00'], $r['outputs']);
        self::assertSame(['calc.step.prize_value', 'calc.step.prize_tax', 'calc.step.prize_net'], array_column($r['steps'], 'key'));
        $low = CalcEngine::prize($prize, ['value' => '999.99']);
        self::assertSame('0.00', $low['outputs']['tax'], 'below the minimum value → nothing withheld');
        self::assertSame('calc.step.prize_below', $low['steps'][1]['key']);
        self::assertSame('500.00', CalcEngine::prize($prize, ['value' => '10000'])['outputs']['tax'], 'withhold is the default mode');
        $g = CalcEngine::prize($prize, ['value' => '9500', 'mode' => 'grossup']);
        self::assertSame(['value' => '9,500.00', 'tax' => '500.00', 'net' => '9,500.00', 'gross' => '10,000.00', 'payer_cost' => '10,000.00'], $g['outputs']);
        $odd = CalcEngine::prize(self::table('prize', ['rate' => '7', 'threshold' => '', 'rounding' => 'none']), ['value' => '100', 'mode' => 'grossup']);
        self::assertSame(['7.53', '107.53'], [$odd['outputs']['tax'], $odd['outputs']['gross']], 'gross − 7% of gross = the prize, to the satang');
        self::assertNull(self::table('prize', ['rate' => '7', 'rounding' => 'none'])['threshold']);
        self::assertArrayHasKey('rate', CalcRules::validate('prize', ['rate' => '100', 'rounding' => 'none'])[1]);
        self::assertArrayHasKey('rate', CalcRules::validate('prize', ['rate' => '', 'rounding' => 'none'])[1], 'no default rate');
        self::assertArrayHasKey('threshold', CalcRules::validate('prize', ['rate' => '5', 'threshold' => '-1', 'rounding' => 'none'])[1]);
        $this->expectException(ValidationException::class);
        CalcEngine::prize($prize, ['value' => 'abc']);
    }

    public function testFontFilesAreRecognisedAndRead(): void
    {
        $dir = dirname(__DIR__) . '/fixtures/fonts/';
        $reg = (string) file_get_contents($dir . 'IBMPlexMono-Regular.ttf');
        $bold = (string) file_get_contents($dir . 'IBMPlexMono-Bold.ttf');
        self::assertSame('ttf', FontInfo::kind($reg));
        self::assertTrue(FontInfo::sane($reg));
        $i = FontInfo::read($reg);
        self::assertSame('IBM Plex Mono', $i['family']);
        self::assertSame('Regular', $i['style']);
        self::assertSame(400, $i['weight']);
        self::assertFalse($i['italic']);
        self::assertStringContainsString('2.3', (string) $i['version']);
        self::assertSame('installable', $i['embedding']);
        self::assertSame(700, FontInfo::read($bold)['weight']);
        self::assertSame(['type' => 'ttf', 'mime' => 'font/ttf', 'ext' => 'ttf', 'problem' => null], FileType::sniff($reg, 'x.ttf'));
        self::assertSame('file.error.font_corrupt', FileType::sniff(substr($reg, 0, 40), 'cut.ttf')['problem'], 'truncated table directory');
        self::assertSame('file.error.font_corrupt', FileType::sniff("\x00\x01\x00\x00\xFF\xFF" . str_repeat("\0", 40), 'x.ttf')['problem'], 'absurd table count');
        self::assertSame('file.error.type_not_allowed', FileType::sniff('ttcf' . str_repeat("\0", 60), 'x.ttc')['problem'], 'collections refused');
        self::assertSame('txt', FileType::sniff('not a font at all', 'fake.ttf')['type'], 'a renamed text file is text — the font class refuses it');
        $woff = (string) file_get_contents($dir . 'IBMPlexMono-Regular.woff'); // converted from the TTF with fontTools (OFL allows)
        self::assertSame('woff', FileType::sniff($woff, 'x.woff')['type']);
        self::assertSame(['IBM Plex Mono', 400], [FontInfo::read($woff)['family'], FontInfo::read($woff)['weight']], 'WOFF tables are read (zlib)');
        $woff2 = (string) file_get_contents($dir . 'IBMPlexMono-Regular.woff2');
        self::assertSame(['type' => 'woff2', 'mime' => 'font/woff2', 'ext' => 'woff2', 'problem' => null], FileType::sniff($woff2, 'x.woff2'));
        self::assertNull(FontInfo::read($woff2)['family'], 'WOFF2 names need Brotli — known limitation');
        self::assertSame('file.error.font_corrupt', FileType::sniff(substr($woff2, 0, 20), 'x.woff2')['problem']);
        $otf = 'OTTO' . substr($reg, 4);
        self::assertSame('otf', FontInfo::kind($otf));
        $restricted = self::withFsType($reg, 0x0002);
        self::assertSame('restricted', FontInfo::read($restricted)['embedding']);
    }

    /** Same font with the OS/2 fsType changed (checksum not updated — readers ignore it). */
    private static function withFsType(string $font, int $fsType): string
    {
        $n = unpack('n', substr($font, 4, 2))[1];
        for ($i = 0; $i < $n; $i++) {
            $rec = substr($font, 12 + 16 * $i, 16);
            if (substr($rec, 0, 4) === 'OS/2') {
                $off = unpack('N', substr($rec, 8, 4))[1];
                return substr_replace($font, pack('n', $fsType), $off + 8, 2);
            }
        }
        self::fail('no OS/2 table');
    }
}
