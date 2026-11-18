<?php

declare(strict_types=1);

namespace Blue\Core\Support\Qr;

use InvalidArgumentException;

/**
 * QR Code Model 2 encoder (ISO/IEC 18004) in plain PHP — no extension, no external service (M27; DEC-03 no vendor at runtime).
 * Versions 1–40, error correction L/M/Q/H, numeric / alphanumeric / byte (UTF-8) mode, automatic mask choice.
 * The block tables and the construction follow the well-known public algorithm (as in Project Nayuki's reference
 * implementation); results are checked in the tests by decoding with independent readers.
 */
final class QrEncoder
{
    public const LEVELS = ['L', 'M', 'Q', 'H'];

    /** Error-correction codewords per block, by level, by version (index 0 unused). */
    private const ECC_PER_BLOCK = [
        'L' => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** Number of error-correction blocks, by level, by version. */
    private const BLOCKS = [
        'L' => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    private const FORMAT_LEVEL_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];
    private const ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /** @var list<list<bool>> */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $isFunction = [];
    private int $size = 0;

    private function __construct()
    {
    }

    /**
     * Encodes text into the smallest version that fits at the given error-correction level.
     *
     * @return array{modules: list<list<bool>>, version: int, level: string, mask: int, mode: string, size: int}
     */
    public static function encode(string $text, string $level = 'M', int $minVersion = 1): array
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('level');
        }
        [$mode, $bits, $count] = self::segment($text);
        $version = 0;
        $dataBits = 0;
        for ($v = max(1, $minVersion); $v <= 40; $v++) {
            $need = 4 + self::countBits($mode, $v) + count($bits);
            if ($count < (1 << self::countBits($mode, $v)) && $need <= self::dataCodewords($v, $level) * 8) {
                $version = $v;
                $dataBits = $need;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('too_long');
        }
        // mode indicator + character count + data + terminator + pad
        $bb = [];
        self::append($bb, ['numeric' => 1, 'alnum' => 2, 'byte' => 4][$mode], 4);
        self::append($bb, $count, self::countBits($mode, $version));
        array_push($bb, ...$bits);
        $capacity = self::dataCodewords($version, $level) * 8;
        self::append($bb, 0, min(4, $capacity - count($bb)));
        self::append($bb, 0, (8 - count($bb) % 8) % 8);
        for ($pad = 0xEC; count($bb) < $capacity; $pad ^= 0xEC ^ 0x11) {
            self::append($bb, $pad, 8);
        }
        $data = [];
        for ($i = 0; $i < count($bb); $i += 8) {
            $b = 0;
            for ($j = 0; $j < 8; $j++) {
                $b = ($b << 1) | $bb[$i + $j];
            }
            $data[] = $b;
        }
        unset($dataBits);

        $qr = new self();
        $qr->size = $version * 4 + 17;
        $qr->modules = array_fill(0, $qr->size, array_fill(0, $qr->size, false));
        $qr->isFunction = $qr->modules;
        $qr->drawFunctionPatterns($version, $level);
        $qr->drawCodewords(self::addEccAndInterleave($data, $version, $level));

        $best = -1;
        $bestPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $qr->applyMask($m);
            $qr->drawFormatBits($level, $m);
            $p = $qr->penalty();
            if ($p < $bestPenalty) {
                $best = $m;
                $bestPenalty = $p;
            }
            $qr->applyMask($m); // XOR again = undo
        }
        $qr->applyMask($best);
        $qr->drawFormatBits($level, $best);
        return ['modules' => $qr->modules, 'version' => $version, 'level' => $level, 'mask' => $best, 'mode' => $mode, 'size' => $qr->size];
    }

    /** Largest number of bytes that fit at a level in version 40 (byte mode). */
    public static function maxBytes(string $level): int
    {
        return intdiv(self::dataCodewords(40, $level) * 8 - 4 - 16, 8);
    }

    // ---- data -------------------------------------------------------------------------------------------

    /** @return array{0: string, 1: list<int>, 2: int} mode, data bits, character count */
    private static function segment(string $text): array
    {
        $bits = [];
        if ($text !== '' && ctype_digit($text)) {
            foreach (str_split($text, 3) as $chunk) {
                self::append($bits, (int) $chunk, [1 => 4, 2 => 7, 3 => 10][strlen($chunk)]);
            }
            return ['numeric', $bits, strlen($text)];
        }
        if ($text !== '' && strspn($text, self::ALNUM) === strlen($text)) {
            foreach (str_split($text, 2) as $pair) {
                if (strlen($pair) === 2) {
                    self::append($bits, strpos(self::ALNUM, $pair[0]) * 45 + strpos(self::ALNUM, $pair[1]), 11);
                } else {
                    self::append($bits, (int) strpos(self::ALNUM, $pair), 6);
                }
            }
            return ['alnum', $bits, strlen($text)];
        }
        foreach (str_split($text) as $ch) {
            self::append($bits, ord($ch), 8);
        }
        return ['byte', $bits, strlen($text)];
    }

    private static function countBits(string $mode, int $version): int
    {
        $i = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);
        return ['numeric' => [10, 12, 14], 'alnum' => [9, 11, 13], 'byte' => [8, 16, 16]][$mode][$i];
    }

    /** @param list<int> $bits */
    private static function append(array &$bits, int $value, int $len): void
    {
        for ($i = $len - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private static function rawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function dataCodewords(int $ver, string $level): int
    {
        return intdiv(self::rawDataModules($ver), 8) - self::ECC_PER_BLOCK[$level][$ver] * self::BLOCKS[$level][$ver];
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function addEccAndInterleave(array $data, int $ver, string $level): array
    {
        $numBlocks = self::BLOCKS[$level][$ver];
        $eccLen = self::ECC_PER_BLOCK[$level][$ver];
        $raw = intdiv(self::rawDataModules($ver), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) {
                $dat[] = 0;
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $out = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $out[] = $block[$i];
                }
            }
        }
        return $out;
    }

    /** @return list<int> */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $result;
    }

    /**
     * @param list<int> $data
     * @param list<int> $divisor
     * @return list<int>
     */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $result;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    // ---- matrix -----------------------------------------------------------------------------------------

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(int $ver, string $level): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $dist = max(abs($dx), abs($dy));
                        $this->set($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $pos = $this->alignmentPositions($ver);
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits($level, 0); // reserves the area; redrawn after masking
        if ($ver >= 7) {
            $rem = $ver;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($ver << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(int $ver): array
    {
        if ($ver === 1) {
            return [];
        }
        $numAlign = intdiv($ver, 7) + 2;
        $step = $ver === 32 ? 26 : intdiv($ver * 4 + $numAlign * 2 + 1, $numAlign * 2 - 2) * 2;
        $result = array_fill(0, $numAlign, 0);
        $result[0] = 6;
        for ($i = $numAlign - 1, $pos = $this->size - 7; $i >= 1; $i--, $pos -= $step) {
            $result[$i] = $pos;
        }
        return $result;
    }

    private function drawFormatBits(string $level, int $mask): void
    {
        $data = (self::FORMAT_LEVEL_BITS[$level] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }
        $this->set(8, $this->size - 8, true); // dark module
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** The four penalty rules of the standard (runs, 2×2 blocks, finder-like patterns, dark balance). */
    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;
        $finder = [true, false, true, true, true, false, true];
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $line = [];
                for ($b = 0; $b < $n; $b++) {
                    $line[] = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                }
                $run = 1;
                for ($b = 1; $b <= $n; $b++) {
                    if ($b < $n && $line[$b] === $line[$b - 1]) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += 3 + $run - 5;
                        }
                        $run = 1;
                    }
                }
                for ($b = 0; $b + 7 <= $n; $b++) {
                    if (array_slice($line, $b, 7) !== $finder) {
                        continue;
                    }
                    $before = $b >= 4 && !in_array(true, array_slice($line, $b - 4, 4), true);
                    $after = $b + 11 <= $n && !in_array(true, array_slice($line, $b + 7, 4), true);
                    if ($before || $after) {
                        $score += 40;
                    }
                }
            }
        }
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $dark++;
                }
                if ($x < $n - 1 && $y < $n - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $total = $n * $n;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        return $score + max(0, $k) * 10;
    }
}
