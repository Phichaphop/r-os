<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * Embeds a TrueType font as a Type0/CIDFontType2 font (Identity-H) so watermarks and page numbers can be written in
 * Thai. The whole font file is embedded (no subsetting); widths and ToUnicode cover the glyphs used, so text stays
 * searchable/copyable. No OpenType shaping: Thai marks use the font's default placement (known limitation).
 */
final class TrueTypeFont
{
    private int $unitsPerEm = 1000;
    /** @var array<int, int> code point => glyph id */
    private array $cmap = [];
    /** @var list<int> advance widths (font units) per glyph */
    private array $advances = [];
    /** @var array<int, int> gid => code point (for ToUnicode) */
    private array $used = [];
    /** @var array{0: int, 1: int, 2: int, 3: int} */
    private array $bbox = [0, -250, 1000, 1000];
    private int $ascent = 1000;
    private int $descent = -250;
    private int $capHeight = 700;
    /** @var array<string, array<int, int>> Thai alternates: variant => code point => glyph id */
    private array $thaiAlt = [];
    private ?Ref $ref = null;
    private int $fontNum = 0;
    private int $cidNum = 0;
    private int $descNum = 0;
    private int $fileNum = 0;
    private int $uniNum = 0;

    public function __construct(private readonly string $bytes, public readonly string $baseName = 'ROSFont')
    {
        $tables = [];
        $n = unpack('n', substr($bytes, 4, 2))[1] ?? 0;
        for ($i = 0; $i < $n; $i++) {
            $rec = substr($bytes, 12 + 16 * $i, 16);
            $t = unpack('Noff/Nlen', substr($rec, 8, 8));
            $tables[substr($rec, 0, 4)] = [(int) $t['off'], (int) $t['len']];
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $req) {
            if (!isset($tables[$req])) {
                throw new PdfError('pdf.error.font');
            }
        }
        $head = substr($bytes, $tables['head'][0], 54);
        $this->unitsPerEm = (int) (unpack('n', substr($head, 18, 2))[1] ?? 1000);
        $bb = unpack('n4', substr($head, 36, 8)) ?: [1 => 0, 0, 0, 0];
        $this->bbox = [$this->s16($bb[1]), $this->s16($bb[2]), $this->s16($bb[3]), $this->s16($bb[4])];
        $hhea = substr($bytes, $tables['hhea'][0], 36);
        $this->ascent = $this->s16((int) (unpack('n', substr($hhea, 4, 2))[1] ?? 0));
        $this->descent = $this->s16((int) (unpack('n', substr($hhea, 6, 2))[1] ?? 0));
        $numH = (int) (unpack('n', substr($hhea, 34, 2))[1] ?? 0);
        $hm = substr($bytes, $tables['hmtx'][0], $tables['hmtx'][1]);
        $last = 0;
        for ($i = 0; $i < $numH; $i++) {
            $last = (int) (unpack('n', substr($hm, $i * 4, 2))[1] ?? 0);
            $this->advances[$i] = $last;
        }
        if (isset($tables['maxp'])) {
            $num = (int) (unpack('n', substr($bytes, $tables['maxp'][0] + 4, 2))[1] ?? 0);
            for ($i = $numH; $i < $num; $i++) {
                $this->advances[$i] = $last;
            }
        }
        if (isset($tables['OS/2']) && $tables['OS/2'][1] >= 90) {
            $os2 = substr($bytes, $tables['OS/2'][0], $tables['OS/2'][1]);
            $this->capHeight = $this->s16((int) (unpack('n', substr($os2, 88, 2))[1] ?? 700)) ?: 700;
        }
        $this->readCmap(substr($bytes, $tables['cmap'][0], $tables['cmap'][1]));
    }

    public static function thai(): self
    {
        $f = new self((string) file_get_contents(__DIR__ . '/fonts/IBMPlexSansThai-Regular.ttf'), 'IBMPlexSansThai');
        $alt = json_decode((string) file_get_contents(__DIR__ . '/fonts/IBMPlexSansThai-Regular.thai.json'), true);
        foreach (is_array($alt) ? $alt : [] as $variant => $map) {
            foreach ((array) $map as $cp => $gid) {
                $f->thaiAlt[(string) $variant][(int) $cp] = (int) $gid;
            }
        }
        return $f;
    }

    /**
     * Glyphs for a text with the classic Thai fallbacks (what the font's GSUB would do): tone marks above an upper
     * vowel or before SARA AM use the raised ".small" form; marks over ป ฝ ฟ ฬ use ".narrow"; ญ ฐ / ฎ ฏ lose their
     * tail (".less" / ".short") when a below vowel follows.
     *
     * @return list<array{0: int, 1: int, 2: int}> [glyph id, code point, shift left in 1/1000 em]
     */
    public function shape(string $utf8): array
    {
        $cps = array_map(static fn (string $c): int => (int) mb_ord($c, 'UTF-8'), mb_str_split($utf8));
        $upper = [0x0E31, 0x0E34, 0x0E35, 0x0E36, 0x0E37, 0x0E47, 0x0E4D];
        $tone = [0x0E48, 0x0E49, 0x0E4A, 0x0E4B, 0x0E4C];
        $below = [0x0E38, 0x0E39, 0x0E3A];
        $tall = [0x0E1B, 0x0E1D, 0x0E1F, 0x0E2C];
        $out = [];
        $base = 0;
        $prev = 0;
        foreach ($cps as $i => $cp) {
            $gid = $this->cmap[$cp] ?? 0;
            $next = $cps[$i + 1] ?? 0;
            if (in_array($cp, $tone, true) && (in_array($prev, $upper, true) || $next === 0x0E33)) {
                $gid = $this->thaiAlt['small'][$cp] ?? $gid;
            } elseif (in_array($next, $below, true)) {
                $gid = $this->thaiAlt['less'][$cp] ?? $this->thaiAlt['short'][$cp] ?? $gid;
            }
            if (!in_array($cp, $tone, true) && !in_array($cp, $upper, true) && !in_array($cp, $below, true)) {
                $base = $cp;
            }
            $shift = (in_array($cp, $tone, true) || in_array($cp, $upper, true)) && in_array($base, $tall, true) ? 170 : 0; // clear the tall stroke
            $out[] = [$gid, $cp, $shift];
            $prev = $cp;
        }
        return $out;
    }

    public function supports(string $utf8): bool
    {
        foreach (mb_str_split($utf8) as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp > 32 && !isset($this->cmap[$cp]) && $cp !== 0x20) {
                return false;
            }
        }
        return true;
    }

    /** TJ array of glyph ids (with the small left shifts of marks over tall consonants); records glyphs for widths/ToUnicode. */
    public function encode(string $utf8): string
    {
        $out = '';
        $run = '';
        foreach ($this->shape($utf8) as [$gid, $cp, $shift]) {
            $this->used[$gid] ??= $cp;
            if ($shift === 0) {
                $run .= sprintf('%04X', $gid);
                continue;
            }
            $out .= ($run !== '' ? '<' . $run . '>' : '') . ' ' . $shift . ' <' . sprintf('%04X', $gid) . '> ' . -$shift . ' ';
            $run = '';
        }
        return '[' . trim($out . ($run !== '' ? '<' . $run . '>' : '')) . ']';
    }

    /** Width of a text in points at a font size. */
    public function width(string $utf8, float $size): float
    {
        $w = 0;
        foreach ($this->shape($utf8) as [$gid]) {
            $w += $this->advances[$gid] ?? 0;
        }
        return $w * $size / $this->unitsPerEm;
    }

    /** The font object to put in /Resources /Font (objects are filled in by finish()). */
    public function ref(Writer $w): Ref
    {
        if ($this->ref === null) {
            $this->fontNum = $w->reserve();
            $this->cidNum = $w->reserve();
            $this->descNum = $w->reserve();
            $this->fileNum = $w->reserve();
            $this->uniNum = $w->reserve();
            $this->ref = new Ref($this->fontNum);
        }
        return $this->ref;
    }

    public function finish(Writer $w): void
    {
        if ($this->ref === null) {
            return;
        }
        $k = 1000 / $this->unitsPerEm;
        ksort($this->used);
        $widths = [];
        foreach (array_keys($this->used) as $gid) {
            $widths[] = $gid;
            $widths[] = [(int) round(($this->advances[$gid] ?? 0) * $k)];
        }
        $name = new Name($this->baseName);
        $w->set($this->fileNum, new Stream(new Dict(['Filter' => new Name('FlateDecode'), 'Length1' => strlen($this->bytes)]), Filters::deflate($this->bytes)));
        $w->set($this->descNum, new Dict([
            'Type' => new Name('FontDescriptor'), 'FontName' => $name, 'Flags' => 32,
            'FontBBox' => array_map(static fn (int $v): int => (int) round($v * $k), $this->bbox), 'ItalicAngle' => 0,
            'Ascent' => (int) round($this->ascent * $k), 'Descent' => (int) round($this->descent * $k), 'CapHeight' => (int) round($this->capHeight * $k),
            'StemV' => 80, 'FontFile2' => new Ref($this->fileNum),
        ]));
        $w->set($this->cidNum, new Dict([
            'Type' => new Name('Font'), 'Subtype' => new Name('CIDFontType2'), 'BaseFont' => $name,
            'CIDSystemInfo' => new Dict(['Registry' => new Str('Adobe'), 'Ordering' => new Str('Identity'), 'Supplement' => 0]),
            'FontDescriptor' => new Ref($this->descNum), 'DW' => 1000, 'W' => $widths, 'CIDToGIDMap' => new Name('Identity'),
        ]));
        $w->set($this->uniNum, new Stream(new Dict(['Filter' => new Name('FlateDecode')]), Filters::deflate($this->toUnicode())));
        $w->set($this->fontNum, new Dict([
            'Type' => new Name('Font'), 'Subtype' => new Name('Type0'), 'BaseFont' => $name, 'Encoding' => new Name('Identity-H'),
            'DescendantFonts' => [new Ref($this->cidNum)], 'ToUnicode' => new Ref($this->uniNum),
        ]));
    }

    private function toUnicode(): string
    {
        $lines = [];
        foreach ($this->used as $gid => $cp) {
            $u = mb_convert_encoding(mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8');
            $lines[] = sprintf('<%04X> <%s>', $gid, strtoupper(bin2hex($u)));
        }
        $body = '';
        foreach (array_chunk($lines, 100) as $chunk) {
            $body .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }
        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . $body
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    private function readCmap(string $t): void
    {
        $n = (int) (unpack('n', substr($t, 2, 2))[1] ?? 0);
        $best = null;
        $bestScore = -1;
        for ($i = 0; $i < $n; $i++) {
            $rec = unpack('nplat/nenc/Noff', substr($t, 4 + 8 * $i, 8)) ?: [];
            $fmt = (int) (unpack('n', substr($t, (int) $rec['off'], 2))[1] ?? 0);
            $score = match (true) {
                $rec['plat'] === 3 && $rec['enc'] === 10 && $fmt === 12 => 4,
                $rec['plat'] === 0 && $fmt === 12 => 3,
                $rec['plat'] === 3 && $rec['enc'] === 1 && $fmt === 4 => 2,
                $rec['plat'] === 0 && $fmt === 4 => 1,
                default => -1,
            };
            if ($score > $bestScore) {
                $best = [(int) $rec['off'], $fmt];
                $bestScore = $score;
            }
        }
        if ($best === null) {
            throw new PdfError('pdf.error.font');
        }
        [$off, $fmt] = $best;
        if ($fmt === 12) {
            $groups = (int) (unpack('N', substr($t, $off + 12, 4))[1] ?? 0);
            for ($g = 0; $g < $groups && $g < 10000; $g++) {
                $r = unpack('Nstart/Nend/Ngid', substr($t, $off + 16 + 12 * $g, 12)) ?: [];
                for ($c = $r['start']; $c <= $r['end'] && $c - $r['start'] < 70000; $c++) {
                    $this->cmap[$c] = $r['gid'] + $c - $r['start'];
                }
            }
            return;
        }
        $segX2 = (int) (unpack('n', substr($t, $off + 6, 2))[1] ?? 0);
        $seg = intdiv($segX2, 2);
        $ends = array_values(unpack('n*', substr($t, $off + 14, $segX2)) ?: []);
        $starts = array_values(unpack('n*', substr($t, $off + 16 + $segX2, $segX2)) ?: []);
        $deltas = array_values(unpack('n*', substr($t, $off + 16 + 2 * $segX2, $segX2)) ?: []);
        $rangeOff = $off + 16 + 3 * $segX2;
        $ranges = array_values(unpack('n*', substr($t, $rangeOff, $segX2)) ?: []);
        for ($s = 0; $s < $seg; $s++) {
            for ($c = $starts[$s]; $c <= $ends[$s] && $c !== 0xFFFF; $c++) {
                if ($ranges[$s] === 0) {
                    $gid = ($c + $deltas[$s]) & 0xFFFF;
                } else {
                    $p = $rangeOff + 2 * $s + $ranges[$s] + 2 * ($c - $starts[$s]);
                    $gid = (int) (unpack('n', substr($t, $p, 2))[1] ?? 0);
                    if ($gid !== 0) {
                        $gid = ($gid + $deltas[$s]) & 0xFFFF;
                    }
                }
                if ($gid !== 0) {
                    $this->cmap[$c] = $gid;
                }
            }
        }
    }

    private function s16(int $v): int
    {
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }
}
