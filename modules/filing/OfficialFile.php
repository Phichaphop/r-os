<?php

declare(strict_types=1);

namespace Blue\Modules\Filing;

use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Money;

/**
 * The official upload files of a monthly filing (0.46.0 · DEC-115), built in memory from an approved revision:
 *
 *  RD_PND1_TXT_v1   withholding tax P.N.D. 1 — pipe-separated UTF-8 text in the layout of the RD Prep sample the
 *                   owner gave (H line, then one D line per person). The codes the file needs that the system cannot
 *                   know (filing type numbers, fixed H/D fields, tax rate, condition) come from payroll.rd_file,
 *                   which has no default.
 *  SSO_1_10_XLSX_v1 social security contributions (Sor.Por.Sor. 1-10) — one sheet named with the employer's branch
 *                   sequence (6 digits): citizen id, title, first name, last name, wage, contribution.
 *
 *  RD_PND1A_TXT_v1  annual withholding tax P.N.D. 1A (0.47.0) — the same layout with form code PND1A, month "00",
 *                   the paid date 31 December of the year and the registered address in parts at the end of each D line.
 *
 * Full id numbers are passed in by the caller, used here and never kept. Nothing is worked out again: amounts are the
 * revision's rows. A file is not built while anything is missing (problems).
 */
final class OfficialFile
{
    public const PND1 = 'RD_PND1_TXT_v1';

    public const SSO = 'SSO_1_10_XLSX_v1';

    public const PND1A = 'RD_PND1A_TXT_v1';

    /** the column-name line of the annual sample file */
    private const PND1A_NAMES = 'RECORD_TYPE|SEQ_NO|BRANCH|PIN|TIN|TITLE|FIRST_NAME|LAST_NAME|PAID_DATE|TAX_RATE|PAID_AMT|TAX_AMT|COND|HOUSE_NO|BUILDING|ALLEY|ROAD|SUBDISTRICT|DISTRICT|PROVINCE|POSTCODE';

    /** the address parts at the end of each annual D line, in order; an empty part is written "-" as in the sample */
    public const ADDRESS = ['house_no', 'building', 'alley', 'road', 'subdistrict', 'district', 'province', 'postcode'];

    /** the column-name line of the sample file, written when payroll.rd_file says so */
    private const PND1_NAMES = 'HEADER_TYPE|FIELD_1|FIELD_2|FIELD_3|FIELD_4|FIELD_5|FIELD_6|FIELD_7|FIELD_8|FIELD_9|FIELD_10|FIELD_11|FIELD_12|FIELD_13';

    private const SSO_HEADERS = ['เลขประจำตัวประชาชน', 'คำนำหน้าชื่อ', 'ชื่อผู้ประกันตน', 'นามสกุลผู้ประกันตน', 'ค่าจ้าง', 'จำนวนเงินสมทบ'];

    /**
     * A person's name split into first and last name at the first space (the system keeps one name field).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function splitName(string $name): ?array
    {
        $p = preg_split('/\s+/u', trim($name), 2);
        return is_array($p) && count($p) === 2 && $p[0] !== '' && trim($p[1]) !== '' ? [$p[0], trim($p[1])] : null;
    }

    /** a value that can go into a pipe-separated line */
    private static function clean(string $v): bool
    {
        return $v !== '' && preg_match('/[|\r\n]/', $v) !== 1;
    }

    /** "YYYY-MM-DD" → "DDMMYYYY" with the Buddhist-era year */
    public static function thaiDate(string $ymd): string
    {
        return substr($ymd, 8, 2) . substr($ymd, 5, 2) . ((int) substr($ymd, 0, 4) + 543);
    }

    /**
     * The annual file: $year is "YYYY"; each person needs address parts (house_no … postcode — building, alley, road may
     * be empty). The fixed codes are the same payroll.rd_file as the monthly file (the samples use the same values).
     *
     * @param array<string, mixed>|null $set
     * @param list<array{code: string, title: string, name: string, id: ?string, income: int, wht: int, address: ?array<string, ?string>}> $people
     * @return array{text: ?string, problems: list<array{key: string, params: array<string, string>}>}
     */
    public static function pnd1a(?array $set, ?string $payerTaxId, string $year, string $purpose, array $people): array
    {
        return self::pnd1($set, $payerTaxId, $year, $purpose, $year . '-12-31', $people, true);
    }

    /**
     * @param array<string, mixed>|null $set payroll.rd_file
     * @param list<array{code: string, title: string, name: string, id: ?string, income: int, wht: int, address?: ?array<string, ?string>}> $people
     * @return array{text: ?string, problems: list<array{key: string, params: array<string, string>}>}
     */
    public static function pnd1(?array $set, ?string $payerTaxId, string $period, string $purpose, string $paidOn, array $people, bool $annual = false): array
    {
        $problems = [];
        $p = static function (string $key, array $params = []) use (&$problems): void {
            $problems[] = ['key' => $key, 'params' => array_map('strval', $params)];
        };
        if ($set === null) {
            $p('filing.official.no_rd_file');
        }
        if ($payerTaxId === null || preg_match('/^\d{13}$/', $payerTaxId) !== 1) {
            $p('filing.official.no_payer');
        }
        if ($people === []) {
            $p('filing.official.no_rows');
        }
        $lines = [];
        foreach ($people as $i => $x) {
            $name = self::splitName($x['name']);
            if ($x['id'] === null || preg_match('/^\d{13}$/', $x['id']) !== 1) {
                $p('filing.official.no_id', ['code' => $x['code']]);
            }
            if (!self::clean($x['title'])) {
                $p('filing.official.no_title', ['code' => $x['code']]);
            }
            if ($name === null || !self::clean($name[0]) || !self::clean($name[1])) {
                $p('filing.official.name', ['code' => $x['code']]);
            }
            if ($x['income'] < 0 || $x['wht'] < 0) {
                $p('filing.official.negative', ['code' => $x['code']]);
            }
            $addr = [];
            if ($annual) {
                $a = $x['address'] ?? null;
                foreach (self::ADDRESS as $k) {
                    $v = trim((string) ($a[$k] ?? ''));
                    $addr[] = $v === '' ? '-' : $v;
                }
                $bad = !is_array($a) || array_filter([$a['house_no'] ?? '', $a['subdistrict'] ?? '', $a['district'] ?? '', $a['province'] ?? ''], static fn ($v): bool => trim((string) $v) === '') !== []
                    || preg_match('/^\d{5}$/', (string) ($a['postcode'] ?? '')) !== 1 || array_filter($addr, static fn (string $v): bool => !self::clean($v)) !== [];
                if ($bad) {
                    $p('filing.official.no_address', ['code' => $x['code']]);
                }
            }
            if ($set !== null && $name !== null) {
                $lines[] = implode('|', array_merge(['D', (string) ($i + 1), (string) $set['d_branch'], (string) $x['id'], (string) $set['d_tin'], $x['title'], $name[0], $name[1], self::thaiDate($paidOn),
                    (string) $set['d_tax_rate'], Money::format($x['income'], 2, false), Money::format($x['wht'], 2, false), (string) $set['d_cond']], $addr));
            }
        }
        if ($problems !== [] || $set === null) {
            return ['text' => null, 'problems' => $problems];
        }
        $h = implode('|', ['H', (string) $set['h_field1'], (string) $payerTaxId, (string) $set['h_field3'], $annual ? 'PND1A' : 'PND1', (string) $payerTaxId, (string) $set['h_field6'],
            $annual ? '00' : substr($period, 5, 2), (string) ((int) substr($period, 0, 4) + 543), (string) $set[$purpose === 'additional' ? 'type_additional' : 'type_original']]);
        $all = array_merge(!empty($set['names_line']) ? [$annual ? self::PND1A_NAMES : self::PND1_NAMES] : [], [$h], $lines);
        return ['text' => implode("\n", $all), 'problems' => []];
    }

    /**
     * @param list<array{code: string, title: string, name: string, id: ?string, wage: int, ee: int}> $people
     * @return array{bytes: ?string, problems: list<array{key: string, params: array<string, string>}>}
     */
    public static function sso(?string $branchNo, array $people): array
    {
        $problems = [];
        $p = static function (string $key, array $params = []) use (&$problems): void {
            $problems[] = ['key' => $key, 'params' => array_map('strval', $params)];
        };
        if ($branchNo === null || preg_match('/^\d{1,6}$/', $branchNo) !== 1) {
            $p('filing.official.no_sso_branch');
        }
        if ($people === []) {
            $p('filing.official.no_rows');
        }
        $rows = [];
        foreach ($people as $x) {
            $name = self::splitName($x['name']);
            if ($x['id'] === null || preg_match('/^\d{13}$/', $x['id']) !== 1) {
                $p('filing.official.no_id', ['code' => $x['code']]);
            }
            if (trim($x['title']) === '') {
                $p('filing.official.no_title', ['code' => $x['code']]);
            }
            if ($name === null) {
                $p('filing.official.name', ['code' => $x['code']]);
            }
            if ($x['wage'] < 0 || $x['ee'] < 0) {
                $p('filing.official.negative', ['code' => $x['code']]);
            }
            $rows[] = [(string) $x['id'], $x['title'], $name[0] ?? '', $name[1] ?? '', (float) Money::format($x['wage'], 2, false), (float) Money::format($x['ee'], 2, false)];
        }
        if ($problems !== []) {
            return ['bytes' => null, 'problems' => $problems];
        }
        $sheet = str_pad((string) $branchNo, 6, '0', STR_PAD_LEFT);
        return ['bytes' => Xlsx::write([$sheet => ['headers' => self::SSO_HEADERS, 'rows' => $rows, 'numeric' => [4, 5], 'money' => [4, 5], 'widths' => [20, 12, 20, 24, 14, 16]]], 'SSO 1-10'), 'problems' => []];
    }
}
