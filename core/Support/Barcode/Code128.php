<?php

declare(strict_types=1);

namespace Blue\Core\Support\Barcode;

/**
 * Code 128 (set B — printable ASCII) barcode as SVG, for request numbers and other short codes a desk scanner reads
 * like a keyboard (0.38.0). Quiet zone of 10 modules each side; the human-readable text is printed under the bars.
 */
final class Code128
{
    /** Bar/space widths of the 107 symbols (0–105 + stop), ISO/IEC 15417. */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];
    private const START_B = 104;
    private const STOP = 106;
    public const QUIET = 10;
    public const MAX = 48;

    /**
     * Module widths of the whole symbol (bar, space, bar, …) including start, check and stop symbols.
     *
     * @return list<int>
     */
    public static function widths(string $text): array
    {
        if ($text === '' || strlen($text) > self::MAX || preg_match('/^[\x20-\x7E]+$/', $text) !== 1) {
            throw new \InvalidArgumentException('code128');
        }
        $codes = [self::START_B];
        foreach (str_split($text) as $ch) {
            $codes[] = ord($ch) - 32;
        }
        $sum = self::START_B;
        for ($i = 1, $n = count($codes); $i < $n; $i++) {
            $sum += $codes[$i] * $i;
        }
        $codes[] = $sum % 103;
        $codes[] = self::STOP;
        $w = [];
        foreach ($codes as $c) {
            foreach (str_split(self::PATTERNS[$c]) as $d) {
                $w[] = (int) $d;
            }
        }
        return $w;
    }

    /** SVG of the barcode: $module px per narrow bar, $height px bars, text under them unless $label is false. */
    public static function svg(string $text, int $module = 2, int $height = 60, bool $label = true, string $title = ''): string
    {
        $widths = self::widths($text);
        $total = array_sum($widths) + 2 * self::QUIET;
        $textH = $label ? 18 : 0;
        $x = self::QUIET;
        $bars = '';
        foreach ($widths as $i => $w) {
            if ($i % 2 === 0) {
                $bars .= 'M' . $x . ',0h' . $w . 'v' . $height . 'h-' . $w . 'z';
            }
            $x += $w;
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . ($height + $textH) . '" width="' . ($total * $module) . '" height="' . ($height + $textH) . '"'
            . ' preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($title !== '' ? $title : $text, ENT_QUOTES | ENT_XML1) . '">'
            . '<rect width="' . $total . '" height="' . ($height + $textH) . '" fill="#ffffff"/><path fill="#000000" d="' . $bars . '"/>';
        if ($label) {
            $svg .= '<text x="' . ($total / 2) . '" y="' . ($height + 14) . '" text-anchor="middle" font-family="monospace" font-size="13" fill="#000000" textLength="' . min($total - 2 * self::QUIET, strlen($text) * 9) . '" lengthAdjust="spacingAndGlyphs">'
                . htmlspecialchars($text, ENT_QUOTES | ENT_XML1) . '</text>';
        }
        return $svg . '</svg>';
    }
}
