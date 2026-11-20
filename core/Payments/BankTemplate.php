<?php

declare(strict_types=1);

namespace Blue\Core\Payments;

use Blue\Core\Support\Money;

/**
 * Bank file templates (0.48.0 · R8-3 · DEC-117): a company describes the transfer file its bank wants with lines of text
 * holding variables — so a new bank, or a new version of a bank's layout, needs no programming. The same language covers
 * fixed-width files (give every field a width) and delimited files (type the delimiter between fields).
 *
 *   {name}                       a value
 *   {name|w:10|pad:0|right}      exactly 10 characters, padded with 0 on the left (default: spaces, value on the left)
 *   {payee_name|w:30|cut}        cut to 30 characters (without "cut" a longer value is an error, never cut silently)
 *   {pay_date|date:DDMMBBBB}     date tokens: YYYY (A.D.), BBBB (B.E.), YY, MM, DD
 *   {amount|dec:2}  {amount|nodot}   2 decimals with a point (default) · no point (in satang when dec is 2)
 *   {account|digits}  {payee_name|upper}  {payee_name|q}   digits only · upper case · in double quotes for CSV
 *   {{ and }}                    a literal brace
 *
 * Variables — everywhere: batch_no, company_code, pay_date, today, time (HHMMSS), count, total, currency, period, and
 * p.<key> for the format's own constants (e.g. the company's account or customer number at the bank); detail lines also:
 * seq, ref (batch no + sequence), payee_code, payee_name, bank_code, account, amount, site.
 *
 * Amounts are units of 1/10 000 baht (Money). Nothing here knows any real bank's layout: the samples are made up.
 */
final class BankTemplate
{
    public const COMMON = ['batch_no', 'company_code', 'pay_date', 'today', 'time', 'count', 'total', 'currency', 'period'];

    public const DETAIL = ['seq', 'ref', 'payee_code', 'payee_name', 'bank_code', 'account', 'amount', 'site'];

    private const DATES = ['pay_date', 'today'];

    private const MONEY = ['total', 'amount'];

    private const OPTIONS = ['w', 'pad', 'right', 'left', 'cut', 'date', 'dec', 'nodot', 'digits', 'upper', 'q'];

    /**
     * Made-up layouts to start from ("ตัวอย่างสมมุติ") — not any bank's format.
     *
     * @return array<string, array{name: string, extension: string, encoding: string, line_end: string, final_newline: bool, header: string, detail: string, footer: string, params: array<string, string>}>
     */
    public static function samples(): array
    {
        return [
            'fixed' => ['name' => 'ตัวอย่างสมมุติ — ธนาคารสมมุติ ก (ความกว้างคงที่)', 'extension' => 'txt', 'encoding' => 'UTF-8', 'line_end' => 'CRLF', 'final_newline' => true,
                'header' => 'H{p.company_id|w:10|pad:0|right}{pay_date|date:DDMMYYYY}{count|w:6|pad:0|right}{total|nodot|w:15|pad:0|right}',
                'detail' => 'D{seq|w:6|pad:0|right}{bank_code|w:3|pad:0|right}{account|digits|w:11|pad:0|right}{amount|nodot|w:13|pad:0|right}{payee_name|w:40|cut}{ref|w:20|cut}',
                'footer' => 'T{count|w:6|pad:0|right}{total|nodot|w:15|pad:0|right}', 'params' => ['company_id' => '0000000001']],
            'csv' => ['name' => 'ตัวอย่างสมมุติ — ธนาคารสมมุติ ข (CSV)', 'extension' => 'csv', 'encoding' => 'UTF-8', 'line_end' => 'CRLF', 'final_newline' => true,
                'header' => 'ลำดับ,รหัสธนาคาร,เลขที่บัญชี,ชื่อผู้รับ,จำนวนเงิน,วันที่โอน,อ้างอิง',
                'detail' => '{seq},{bank_code},{account|digits},{payee_name|q},{amount|dec:2},{pay_date|date:DD/MM/BBBB},{ref}',
                'footer' => '', 'params' => []],
        ];
    }

    /**
     * @param list<string> $params the format's own constant names
     * @return list<array{key: string, params: array<string, string>}> what is wrong with one template (empty = fine)
     */
    public static function check(string $tpl, bool $detail, array $params): array
    {
        $errors = [];
        foreach (self::tokens($tpl) as $tok) {
            if ($tok['error'] !== null) {
                $errors[] = ['key' => $tok['error'], 'params' => ['t' => $tok['raw']]];
                continue;
            }
            if ($tok['var'] === null) {
                continue;
            }
            $v = $tok['var'];
            $known = in_array($v, self::COMMON, true) || ($detail && in_array($v, self::DETAIL, true)) || (str_starts_with($v, 'p.') && in_array(substr($v, 2), $params, true));
            if (!$known) {
                $errors[] = ['key' => in_array($v, self::DETAIL, true) ? 'payment.format.error_detail_only' : 'payment.format.error_variable', 'params' => ['t' => $tok['raw']]];
            }
            foreach ($tok['opts'] as $k => $o) {
                $bad = !in_array($k, self::OPTIONS, true)
                    || ($k === 'w' && (preg_match('/^\d{1,3}$/', $o) !== 1 || (int) $o < 1))
                    || ($k === 'pad' && !in_array($o, ['0', 'space'], true))
                    || ($k === 'dec' && preg_match('/^[0-4]$/', $o) !== 1)
                    || ($k === 'date' && (!in_array($v, self::DATES, true) || preg_match('/^(YYYY|BBBB|YY|MM|DD|[^A-Za-z])+$/', $o) !== 1))
                    || (in_array($k, ['dec', 'nodot'], true) && !in_array($v, self::MONEY, true))
                    || (!in_array($k, ['w', 'pad', 'dec', 'date'], true) && $o !== '');
                if ($bad) {
                    $errors[] = ['key' => 'payment.format.error_option', 'params' => ['t' => $tok['raw']]];
                }
            }
        }
        return $errors;
    }

    /**
     * Renders one template line.
     *
     * @param array<string, string|int> $vars
     * @return array{0: ?string, 1: ?string} the line, or the raw token that could not be written (too long for its width)
     */
    public static function line(string $tpl, array $vars): array
    {
        $out = '';
        foreach (self::tokens($tpl) as $tok) {
            if ($tok['var'] === null) {
                $out .= $tok['text'];
                continue;
            }
            $v = $tok['var'];
            $o = $tok['opts'];
            $val = $vars[$v] ?? '';
            if (in_array($v, self::MONEY, true)) {
                $dec = isset($o['dec']) ? (int) $o['dec'] : 2;
                $s = Money::format((int) $val, $dec, false);
                $s = isset($o['nodot']) ? str_replace('.', '', $s) : $s;
            } elseif (in_array($v, self::DATES, true)) {
                $s = self::date((string) $val, $o['date'] ?? 'YYYY-MM-DD');
            } else {
                $s = (string) $val;
            }
            if (isset($o['digits'])) {
                $s = preg_replace('/\D+/', '', $s) ?? '';
            }
            if (isset($o['upper'])) {
                $s = mb_strtoupper($s);
            }
            if (isset($o['w'])) {
                $w = (int) $o['w'];
                $len = mb_strlen($s);
                if ($len > $w) {
                    if (!isset($o['cut'])) {
                        return [null, $tok['raw']];
                    }
                    $s = mb_substr($s, 0, $w);
                    $len = $w;
                }
                $pad = str_repeat(($o['pad'] ?? 'space') === '0' ? '0' : ' ', $w - $len);
                $s = isset($o['right']) ? $pad . $s : $s . $pad;
            }
            if (isset($o['q'])) {
                $s = '"' . str_replace('"', '""', $s) . '"';
            }
            $out .= $s;
        }
        return [$out, null];
    }

    private static function date(string $ymd, string $fmt): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) !== 1) {
            return '';
        }
        return strtr($fmt, ['YYYY' => $m[1], 'BBBB' => (string) ((int) $m[1] + 543), 'YY' => substr($m[1], 2), 'MM' => $m[2], 'DD' => $m[3]]);
    }

    /**
     * @return list<array{text: string, raw: string, var: ?string, opts: array<string, string>, error: ?string}>
     */
    private static function tokens(string $tpl): array
    {
        $out = [];
        $n = strlen($tpl);
        $i = 0;
        $text = '';
        while ($i < $n) {
            $c = $tpl[$i];
            if ($c === '{' && ($tpl[$i + 1] ?? '') === '{') {
                $text .= '{';
                $i += 2;
                continue;
            }
            if ($c === '}' && ($tpl[$i + 1] ?? '') === '}') {
                $text .= '}';
                $i += 2;
                continue;
            }
            if ($c === '}') {
                $out[] = ['text' => '', 'raw' => '}', 'var' => null, 'opts' => [], 'error' => 'payment.format.error_brace'];
                $i++;
                continue;
            }
            if ($c !== '{') {
                $text .= $c;
                $i++;
                continue;
            }
            $end = strpos($tpl, '}', $i);
            if ($text !== '') {
                $out[] = ['text' => $text, 'raw' => $text, 'var' => null, 'opts' => [], 'error' => null];
                $text = '';
            }
            if ($end === false) {
                $out[] = ['text' => '', 'raw' => substr($tpl, $i), 'var' => null, 'opts' => [], 'error' => 'payment.format.error_brace'];
                break;
            }
            $raw = substr($tpl, $i, $end - $i + 1);
            $parts = explode('|', substr($tpl, $i + 1, $end - $i - 1));
            $var = trim((string) array_shift($parts));
            $opts = [];
            foreach ($parts as $p) {
                [$k, $v] = array_pad(explode(':', trim($p), 2), 2, '');
                $opts[$k] = $v;
            }
            $ok = preg_match('/^(p\.)?[a-z_][a-z0-9_]{0,30}$/', $var) === 1;
            $out[] = ['text' => '', 'raw' => $raw, 'var' => $ok ? $var : null, 'opts' => $opts, 'error' => $ok ? null : 'payment.format.error_variable'];
            $i = $end + 1;
        }
        if ($text !== '') {
            $out[] = ['text' => $text, 'raw' => $text, 'var' => null, 'opts' => [], 'error' => null];
        }
        return $out;
    }

    /**
     * Builds a whole file. Header and footer may hold several lines (one template per line); an empty one is left out.
     *
     * @param array{header: ?string, detail: string, footer: ?string, encoding: string, line_end: string, final_newline: bool|int, params: array<string, string>} $f
     * @param array<string, string|int> $common batch_no, company_code, pay_date, today, time, count, total, currency, period
     * @param list<array<string, string|int>> $items seq, ref, payee_code, payee_name, bank_code, account, amount, site
     * @return array{bytes: ?string, problems: list<array{key: string, params: array<string, string>}>, detail_lines: int}
     */
    public static function build(array $f, array $common, array $items): array
    {
        $vars = $common;
        foreach ($f['params'] as $k => $v) {
            $vars['p.' . $k] = $v;
        }
        $problems = [];
        $lines = [];
        $tpls = static fn (?string $t): array => array_values(array_filter(preg_split('/\r\n|\n|\r/', (string) $t) ?: [], static fn (string $l): bool => $l !== ''));
        foreach ($tpls($f['header']) as $t) {
            [$l, $bad] = self::line($t, $vars);
            $bad === null ? $lines[] = $l : $problems[] = ['key' => 'payment.format.error_width', 'params' => ['t' => $bad, 'seq' => '—']];
        }
        $detail = 0;
        foreach ($items as $it) {
            foreach ($tpls($f['detail']) as $t) {
                [$l, $bad] = self::line($t, $it + $vars);
                if ($bad !== null) {
                    $problems[] = ['key' => 'payment.format.error_width', 'params' => ['t' => $bad, 'seq' => (string) $it['seq']]];
                    continue;
                }
                $lines[] = $l;
                $detail++;
            }
        }
        foreach ($tpls($f['footer']) as $t) {
            [$l, $bad] = self::line($t, $vars);
            $bad === null ? $lines[] = $l : $problems[] = ['key' => 'payment.format.error_width', 'params' => ['t' => $bad, 'seq' => '—']];
        }
        if ($problems !== []) {
            return ['bytes' => null, 'problems' => $problems, 'detail_lines' => $detail];
        }
        $eol = $f['line_end'] === 'LF' ? "\n" : "\r\n";
        $text = implode($eol, $lines) . ((bool) $f['final_newline'] ? $eol : '');
        if ($f['encoding'] === 'TIS-620') {
            $conv = @iconv('UTF-8', 'TIS-620', $text);
            if ($conv === false) {
                return ['bytes' => null, 'problems' => [['key' => 'payment.format.error_encoding', 'params' => []]], 'detail_lines' => $detail];
            }
            $text = $conv;
        }
        return ['bytes' => $text, 'problems' => [], 'detail_lines' => $detail];
    }
}
