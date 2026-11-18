<?php

declare(strict_types=1);

namespace Blue\Core\Support;

/**
 * Line-based diff (longest common subsequence) used to compare template / document versions (6.15 "ดู/เทียบประวัติ").
 * Input is split into lines; HTML is first broken after block tags so a changed paragraph shows as one line.
 */
final class LineDiff
{
    public const MAX_LINES = 2000;

    /** @return list<array{0: '='|'-'|'+', 1: string}> */
    public static function compare(string $old, string $new): array
    {
        $a = self::lines($old);
        $b = self::lines($new);
        if (count($a) > self::MAX_LINES || count($b) > self::MAX_LINES) {
            return [['-', '(too long to compare)'], ['+', '(too long to compare)']];
        }
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['=', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['-', $a[$i++]];
            } else {
                $out[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['+', $b[$j++]];
        }
        return $out;
    }

    /** @return list<string> */
    private static function lines(string $s): array
    {
        $s = (string) preg_replace('#(</(p|h[1-6]|li|tr|div|table|ul|ol|blockquote)>|<br\s*/?>)#i', "$1\n", $s);
        $lines = array_map('trim', explode("\n", str_replace("\r", '', $s)));
        return array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
    }
}
