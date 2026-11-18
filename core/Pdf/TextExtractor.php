<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * Best-effort text from PDF pages, used to search inside documents (M03 library). It reads the page content
 * streams (Tj, TJ, ', ") and maps codes through the font's ToUnicode CMap, or through the simple-font encoding
 * (WinAnsi / Differences) when there is none. It does NOT do layout analysis or OCR: scanned pages give no text,
 * fonts without a usable mapping are skipped, and word order follows the content stream. The result is for
 * finding a page, never a faithful copy of the document.
 */
final class TextExtractor
{
    public const MAX_PAGES = 500;
    public const MAX_PAGE_CHARS = 20000;
    private const MAX_OPS = 300000;
    private const MAX_XOBJECT_DEPTH = 3;

    /** @var array<int|string, array{cmap: ?array{ranges: list<array{0: string, 1: string}>, map: array<string, string>}, simple: ?array<int, string>}> */
    private array $fonts = [];
    private int $ops = 0;

    private function __construct(private readonly Reader $r)
    {
    }

    /**
     * Text per page (index 0 = page 1). Throws PdfError for a file that cannot be opened (password, corrupt).
     *
     * @return list<string>
     */
    public static function pages(string $bytes, int $maxPages = self::MAX_PAGES): array
    {
        $r = new Reader($bytes);
        $x = new self($r);
        $out = [];
        foreach ($r->pages() as $i => [, $page]) {
            if ($i >= $maxPages) {
                break;
            }
            $x->ops = 0;
            try {
                $text = $x->pageText($page);
            } catch (PdfError) {
                $text = '';
            }
            $out[] = self::tidy($text);
        }
        return $out;
    }

    /** Collapse whitespace, drop control characters, cap length. */
    public static function tidy(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}\x{FFFD}\x{E000}-\x{F8FF}]/u', '', $text);
        $text = trim((string) preg_replace('/[ \t\x{A0}]+/u', ' ', $text));
        $text = (string) preg_replace('/ *\n[\s]*/u', "\n", $text);
        return mb_substr($text, 0, self::MAX_PAGE_CHARS);
    }

    private function pageText(Dict $page): string
    {
        $contents = $this->r->resolve($page->get('Contents'));
        $data = '';
        foreach (is_array($contents) ? $contents : [$contents] as $c) {
            $c = $this->r->resolve($c);
            if ($c instanceof Stream) {
                $data .= $this->r->streamData($c) . "\n";
            }
        }
        $res = $this->r->resolve($page->get('Resources'));
        return $this->run($data, $res instanceof Dict ? $res : new Dict(), 0);
    }

    private function run(string $data, Dict $res, int $depth): string
    {
        $p = new Parser($data);
        $len = strlen($data);
        $stack = [];
        $font = null;
        $out = '';
        $y = null;
        $size = 12.0;
        $marks = []; // marked-content stack: [ActualText or null, offset in $out]
        while ($this->ops++ < self::MAX_OPS) {
            $p->skipWs();
            if ($p->pos >= $len) {
                break;
            }
            $start = $p->pos;
            try {
                $v = $p->value();
            } catch (PdfError) {
                if ($p->pos <= $start) {
                    $p->pos = $start + 1;
                }
                $stack = [];
                continue;
            }
            if (!$v instanceof Keyword) {
                $stack[] = $v;
                if (count($stack) > 64) {
                    array_shift($stack);
                }
                continue;
            }
            switch ($v->word) {
                case 'Tf':
                    $name = $stack[0] ?? null;
                    $font = $name instanceof Name ? $this->font($res, $name->name) : null;
                    $sz = $stack[1] ?? null;
                    $size = (is_int($sz) || is_float($sz)) && abs((float) $sz) > 0.1 ? abs((float) $sz) : $size;
                    break;
                case 'BMC':
                    $marks[] = [null, strlen($out)];
                    break;
                case 'BDC':
                    // /Span << /ActualText (...) >> replaces the glyphs inside it (used for Thai clusters, ligatures)
                    $props = $stack[1] ?? null;
                    $props = $props instanceof Dict ? $props : null;
                    $at = $props !== null ? $this->r->resolve($props->get('ActualText')) : null;
                    $marks[] = [$at instanceof Str ? $at->text() : null, strlen($out)];
                    break;
                case 'EMC':
                    $mk = array_pop($marks);
                    if ($mk !== null && $mk[0] !== null) {
                        $out = substr($out, 0, $mk[1]) . $mk[0];
                    }
                    break;
                case 'Tj':
                    $out .= $this->show($font, $stack[0] ?? null);
                    break;
                case "'":
                    $out .= "\n" . $this->show($font, $stack[0] ?? null);
                    break;
                case '"':
                    $out .= "\n" . $this->show($font, $stack[2] ?? null);
                    break;
                case 'TJ':
                    foreach (is_array($stack[0] ?? null) ? $stack[0] : [] as $el) {
                        if (is_int($el) || is_float($el)) {
                            if ($el < -250) {
                                $out .= ' ';
                            }
                        } else {
                            $out .= $this->show($font, $el);
                        }
                    }
                    break;
                case 'Td':
                case 'TD':
                    $ty = $stack[1] ?? 0;
                    if ((is_int($ty) || is_float($ty)) && abs((float) $ty) * $size > $size * 0.5) {
                        $out .= "\n";
                    }
                    break;
                case 'Tm':
                    $f = $stack[5] ?? null;
                    if (is_int($f) || is_float($f)) {
                        if ($y !== null && abs($y - (float) $f) > $size * 0.5) {
                            $out .= "\n";
                        }
                        $y = (float) $f;
                    }
                    break;
                case 'T*':
                    $out .= "\n";
                    break;
                case 'ET':
                    $out .= ' ';
                    break;
                case 'ID':
                    // inline image data: skip to the EI that follows whitespace
                    if (preg_match('/\sEI(?=\s|$)/', $data, $m, PREG_OFFSET_CAPTURE, $p->pos) === 1) {
                        $p->pos = (int) $m[0][1] + strlen($m[0][0]);
                    } else {
                        $p->pos = $len;
                    }
                    break;
                case 'Do':
                    $name = $stack[0] ?? null;
                    if ($name instanceof Name && $depth < self::MAX_XOBJECT_DEPTH) {
                        $out .= ' ' . $this->form($res, $name->name, $depth) . ' ';
                    }
                    break;
            }
            $stack = [];
        }
        return $out;
    }

    private function form(Dict $res, string $name, int $depth): string
    {
        $xo = $this->r->resolve($res->get('XObject'));
        if (!$xo instanceof Dict) {
            return '';
        }
        $s = $this->r->resolve($xo->get($name));
        if (!$s instanceof Stream || $s->dict->name('Subtype') !== 'Form') {
            return '';
        }
        $fr = $this->r->resolve($s->dict->get('Resources'));
        return $this->run($this->r->streamData($s), $fr instanceof Dict ? $fr : $res, $depth + 1);
    }

    /** @return ?array{cmap: ?array{ranges: list<array{0: string, 1: string}>, map: array<string, string>}, simple: ?array<int, string>} */
    private function font(Dict $res, string $name): ?array
    {
        $fonts = $this->r->resolve($res->get('Font'));
        if (!$fonts instanceof Dict) {
            return null;
        }
        $ref = $fonts->get($name);
        $key = $ref instanceof Ref ? $ref->num : 'n:' . $name . ':' . spl_object_id($fonts);
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }
        $fd = $this->r->resolve($ref);
        $def = ['cmap' => null, 'simple' => null];
        if ($fd instanceof Dict) {
            $tu = $this->r->resolve($fd->get('ToUnicode'));
            if ($tu instanceof Stream) {
                try {
                    $def['cmap'] = self::parseCmap($this->r->streamData($tu));
                } catch (PdfError) {
                    $def['cmap'] = null;
                }
            }
            if ($fd->name('Subtype') !== 'Type0') {
                $def['simple'] = $this->simpleEncoding($fd);
            }
        }
        return $this->fonts[$key] = $def;
    }

    /** @param ?array{cmap: ?array{ranges: list<array{0: string, 1: string}>, map: array<string, string>}, simple: ?array<int, string>} $font */
    private function show(?array $font, mixed $s): string
    {
        if (!$s instanceof Str || $s->bytes === '') {
            return '';
        }
        $b = $s->bytes;
        if ($font === null) {
            return (string) mb_convert_encoding($b, 'UTF-8', 'Windows-1252');
        }
        $cmap = $font['cmap'];
        if ($cmap === null) {
            if ($font['simple'] === null) {
                return ''; // composite font without ToUnicode: codes are glyph ids, nothing to map
            }
            $o = '';
            for ($i = 0, $n = strlen($b); $i < $n; $i++) {
                $o .= $font['simple'][ord($b[$i])] ?? '';
            }
            return $o;
        }
        $o = '';
        $n = strlen($b);
        $i = 0;
        while ($i < $n) {
            $len = self::codeLength($cmap['ranges'], $b, $i);
            $code = substr($b, $i, $len);
            if (isset($cmap['map'][$code])) {
                $o .= $cmap['map'][$code];
            } elseif ($len === 1 && $font['simple'] !== null) {
                $o .= $font['simple'][ord($code)] ?? '';
            }
            $i += $len;
        }
        return $o;
    }

    /** @param list<array{0: string, 1: string}> $ranges */
    private static function codeLength(array $ranges, string $b, int $i): int
    {
        foreach ($ranges as [$lo, $hi]) {
            $l = strlen($lo);
            $c = substr($b, $i, $l);
            if (strlen($c) === $l && strcmp($c, $lo) >= 0 && strcmp($c, $hi) <= 0) {
                return $l;
            }
        }
        return $ranges === [] ? 2 : strlen($ranges[0][0]);
    }

    /**
     * bfchar / bfrange of a ToUnicode CMap → code bytes => UTF-8.
     *
     * @return array{ranges: list<array{0: string, 1: string}>, map: array<string, string>}
     */
    public static function parseCmap(string $data): array
    {
        $ranges = [];
        $map = [];
        $hex = static fn (string $h): string => (string) hex2bin(strlen($h) % 2 === 1 ? $h . '0' : $h);
        $uni = static fn (string $bytes): string => (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        if (preg_match_all('/begincodespacerange(.*?)endcodespacerange/s', $data, $m) > 0) {
            foreach ($m[1] as $blk) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $rr, PREG_SET_ORDER);
                foreach ($rr as $x) {
                    $ranges[] = [$hex($x[1]), $hex($x[2])];
                }
            }
        }
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $data, $m) > 0) {
            foreach ($m[1] as $blk) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $blk, $cc, PREG_SET_ORDER);
                foreach ($cc as $x) {
                    $map[$hex($x[1])] = $uni($hex($x[2]));
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $data, $m) > 0) {
            foreach ($m[1] as $blk) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $blk, $rr, PREG_SET_ORDER);
                foreach ($rr as $x) {
                    $lo = $hex($x[1]);
                    $l = strlen($lo);
                    $a = (int) hexdec($x[1]);
                    $z = (int) hexdec($x[2]);
                    if ($z < $a || $z - $a > 65535) {
                        continue;
                    }
                    if ($x[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $x[3], $dd);
                        foreach ($dd[1] as $k => $d) {
                            if ($a + $k > $z) {
                                break;
                            }
                            $map[self::codeBytes($a + $k, $l)] = $uni($hex($d));
                        }
                        continue;
                    }
                    $dst = $hex(trim($x[3], '<>'));
                    for ($c = $a, $k = 0; $c <= $z; $c++, $k++) {
                        // increment the last byte of the destination (ISO 32000-1 §9.10.3)
                        $d = $dst;
                        if ($d !== '') {
                            $last = strlen($d) - 1;
                            $d[$last] = chr((ord($d[$last]) + $k) & 0xFF);
                        }
                        $map[self::codeBytes($c, $l)] = $uni($d);
                    }
                }
            }
        }
        if ($ranges === []) {
            $len = $map === [] ? 2 : strlen((string) array_key_first($map));
            $ranges[] = [str_repeat("\0", $len), str_repeat("\xFF", $len)];
        }
        usort($ranges, static fn (array $x, array $y): int => strlen($x[0]) <=> strlen($y[0]));
        return ['ranges' => $ranges, 'map' => $map];
    }

    private static function codeBytes(int $code, int $len): string
    {
        return substr(str_pad(pack('N', $code), $len, "\0", STR_PAD_LEFT), -$len);
    }

    /**
     * byte => UTF-8 for a simple font: base encoding (WinAnsi unless MacRoman) + Differences glyph names.
     *
     * @return array<int, string>
     */
    private function simpleEncoding(Dict $fd): array
    {
        $enc = $this->r->resolve($fd->get('Encoding'));
        $base = $enc instanceof Name ? $enc->name : ($enc instanceof Dict ? $enc->name('BaseEncoding') : null);
        $table = [];
        for ($i = 32; $i < 256; $i++) {
            $table[$i] = (string) mb_convert_encoding(chr($i), 'UTF-8', $base === 'MacRomanEncoding' ? 'macintosh' : 'Windows-1252');
        }
        if ($enc instanceof Dict) {
            $diff = $this->r->resolve($enc->get('Differences'));
            $code = 0;
            foreach (is_array($diff) ? $diff : [] as $d) {
                if (is_int($d)) {
                    $code = $d;
                } elseif ($d instanceof Name && $code < 256) {
                    $table[$code++] = self::glyph($d->name);
                }
            }
        }
        return $table;
    }

    private static function glyph(string $name): string
    {
        if (preg_match('/^uni([0-9A-Fa-f]{4})/', $name, $m) === 1 || preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $m) === 1) {
            return (string) mb_chr((int) hexdec($m[1]), 'UTF-8');
        }
        if (strlen($name) === 1 && ctype_alnum($name)) {
            return $name;
        }
        $named = ['space' => ' ', 'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6',
            'seven' => '7', 'eight' => '8', 'nine' => '9', 'period' => '.', 'comma' => ',', 'colon' => ':', 'semicolon' => ';', 'hyphen' => '-',
            'parenleft' => '(', 'parenright' => ')', 'slash' => '/', 'quoteright' => '’', 'quoteleft' => '‘', 'quotedbl' => '"',
            'question' => '?', 'exclam' => '!', 'ampersand' => '&', 'percent' => '%', 'at' => '@', 'underscore' => '_', 'endash' => '–',
            'emdash' => '—', 'bullet' => '•', 'fi' => 'fi', 'fl' => 'fl'];
        return $named[$name] ?? '';
    }
}
