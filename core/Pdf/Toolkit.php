<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * S01 operations built on Reader/Writer. Every operation returns NEW bytes (a derivative) and never touches its input.
 * Result: ['bytes' => string, 'pages' => int, 'warnings' => list<key>, 'notes' => list<array{0: key, 1: params}>,
 *          'parts' => list<array{name: string, bytes: string, pages: int}> (split only)].
 */
final class Toolkit
{
    public const VERSION = 'Blue OS PDF engine 1.0';
    public const POSITIONS = ['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right'];
    public const PAGE_SIZES = ['fit' => null, 'A4' => [595.28, 841.89], 'A4L' => [841.89, 595.28], 'Letter' => [612.0, 792.0]];

    // ---- information --------------------------------------------------------------------------------

    /** @return array{pages: int, version: string, encrypted: bool, method: ?string, repaired: bool, warnings: list<string>, metadata: array<string, string>, xmp: bool, sizes: list<string>} */
    public static function info(string $bytes, string $password = ''): array
    {
        $r = new Reader($bytes, $password);
        $meta = [];
        foreach ($r->info()?->items ?? [] as $k => $v) {
            $v = $r->resolve($v);
            if ($v instanceof Str) {
                $meta[(string) $k] = mb_substr(self::clean($v->text()), 0, 300);
            } elseif ($v instanceof Name) {
                $meta[(string) $k] = $v->name;
            }
        }
        $sizes = [];
        foreach ($r->pages() as [, $p]) {
            [$w, $h] = self::visualSize($r, $p);
            $sizes[] = self::sizeName($w, $h);
        }
        $cat = $r->catalog();
        return ['pages' => count($r->pages()), 'version' => $r->version(), 'encrypted' => $r->encrypted, 'method' => $r->security?->method(),
            'repaired' => $r->repaired, 'warnings' => $r->warnings(), 'metadata' => $meta, 'xmp' => $cat?->has('Metadata') ?? false, 'sizes' => $sizes];
    }

    /**
     * "1-3, 5, 8-" → 0-based indexes (empty / "all" = every page). Throws pdf.error.page_range on anything outside.
     *
     * @return list<int>
     */
    public static function pageList(string $spec, int $count): array
    {
        $spec = trim(str_replace([' ', '–', '—'], ['', '-', '-'], mb_strtolower($spec)));
        if ($spec === '' || $spec === 'all') {
            return range(0, $count - 1);
        }
        $out = [];
        foreach (explode(',', $spec) as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d+)?-(\d+)?$/', $part, $m) === 1 && ($m[1] ?? '') . ($m[2] ?? '') !== '') {
                $a = ($m[1] ?? '') === '' ? 1 : (int) $m[1];
                $b = ($m[2] ?? '') === '' ? $count : (int) $m[2];
            } elseif (ctype_digit($part)) {
                $a = $b = (int) $part;
            } else {
                throw new PdfError('pdf.error.page_range');
            }
            if ($a < 1 || $b > $count || $a > $b) {
                throw new PdfError('pdf.error.page_range');
            }
            for ($i = $a; $i <= $b; $i++) {
                $out[] = $i - 1;
            }
        }
        if ($out === []) {
            throw new PdfError('pdf.error.page_range');
        }
        return $out;
    }

    // ---- page operations ---------------------------------------------------------------------------

    /**
     * @param list<array{0: string, 1: string}> $files [bytes, password]
     * @return array<string, mixed>
     */
    public static function merge(array $files): array
    {
        if (count($files) < 2) {
            throw new PdfError('pdf.error.need_two');
        }
        $w = new Writer();
        $warn = [];
        foreach ($files as $i => [$bytes, $pw]) {
            $r = new Reader($bytes, $pw);
            $w->importPages($r, array_keys($r->pages()));
            if ($i === 0) {
                $w->keepBasics($r);
            }
            $warn = array_merge($warn, $r->warnings());
        }
        return self::result($w->output(), $w->pageCount(), $warn, [['pdf.note.dropped_extras', []]]);
    }

    /**
     * @param string $mode every | ranges | chunk
     * @return array<string, mixed>
     */
    public static function split(string $bytes, string $pw, string $mode, string $ranges = '', int $chunk = 1): array
    {
        $r = new Reader($bytes, $pw);
        $n = count($r->pages());
        $groups = [];
        if ($mode === 'every') {
            foreach (range(0, $n - 1) as $i) {
                $groups[] = [$i];
            }
        } elseif ($mode === 'chunk') {
            if ($chunk < 1) {
                throw new PdfError('pdf.error.page_range');
            }
            $groups = array_chunk(range(0, $n - 1), $chunk);
        } else {
            foreach (explode(',', $ranges) as $part) {
                if (trim($part) !== '') {
                    $groups[] = self::pageList($part, $n);
                }
            }
            if ($groups === []) {
                throw new PdfError('pdf.error.page_range');
            }
        }
        if (count($groups) > 500) {
            throw new PdfError('pdf.error.too_many_parts');
        }
        $parts = [];
        foreach ($groups as $g) {
            $w = new Writer();
            $w->importPages($r, $g);
            $w->keepBasics($r);
            $first = $g[0] + 1;
            $last = $g[count($g) - 1] + 1;
            $parts[] = ['name' => $first === $last ? 'p' . $first : 'p' . $first . '-' . $last, 'bytes' => $w->output(), 'pages' => count($g)];
        }
        return self::result('', array_sum(array_column($parts, 'pages')), $r->warnings(), [['pdf.note.dropped_extras', []]], $parts);
    }

    /** @return array<string, mixed> */
    public static function extract(string $bytes, string $pw, string $spec): array
    {
        $r = new Reader($bytes, $pw);
        $w = new Writer();
        $w->importPages($r, self::pageList($spec, count($r->pages())));
        $w->keepBasics($r);
        return self::result($w->output(), $w->pageCount(), $r->warnings(), [['pdf.note.extract_not_redact', []], ['pdf.note.dropped_extras', []]]);
    }

    /**
     * New order with per-page rotation; pages left out are removed.
     *
     * @param list<array{0: int, 1: int}> $order [0-based source index, extra rotation 0/90/180/270]
     * @return array<string, mixed>
     */
    public static function organize(string $bytes, string $pw, array $order): array
    {
        $r = new Reader($bytes, $pw);
        $n = count($r->pages());
        if ($order === []) {
            throw new PdfError('pdf.error.page_range');
        }
        $w = new Writer();
        $idx = [];
        $rot = [];
        foreach ($order as $k => [$i, $deg]) {
            if ($i < 0 || $i >= $n || $deg % 90 !== 0) {
                throw new PdfError('pdf.error.page_range');
            }
            $idx[] = $i;
            $rot[$k] = $deg;
        }
        $pos = 0;
        $w->importPages($r, $idx, static function (Dict $page) use (&$pos, $rot): void {
            self::addRotation($page, $rot[$pos++] ?? 0);
        });
        $w->keepBasics($r);
        return self::result($w->output(), $w->pageCount(), $r->warnings(), [['pdf.note.dropped_extras', []]]);
    }

    /** @return array<string, mixed> */
    public static function rotate(string $bytes, string $pw, int $degrees, string $spec): array
    {
        if (!in_array($degrees, [90, 180, 270], true)) {
            throw new PdfError('pdf.error.rotation');
        }
        $r = new Reader($bytes, $pw);
        $sel = array_flip(self::pageList($spec, count($r->pages())));
        $w = new Writer();
        $w->importPages($r, array_keys($r->pages()), static function (Dict $page, int $i) use ($sel, $degrees): void {
            if (isset($sel[$i])) {
                self::addRotation($page, $degrees);
            }
        });
        $w->keepCatalog($r);
        $w->copyInfo($r);
        return self::result($w->output(), $w->pageCount(), $r->warnings());
    }

    // ---- whole-document operations -------------------------------------------------------------------

    /**
     * Best effort: unused objects dropped, streams (re)compressed where smaller, optionally metadata removed.
     *
     * @return array<string, mixed>
     */
    public static function compress(string $bytes, string $pw, bool $stripMetadata): array
    {
        $r = new Reader($bytes, $pw);
        $w = new Writer();
        $w->importPages($r, array_keys($r->pages()), null, !$stripMetadata);
        $w->keepCatalog($r, !$stripMetadata);
        if ($stripMetadata) {
            $w->setInfo(new Dict());
        } else {
            $w->copyInfo($r);
        }
        [$changed] = $w->recompress();
        $out = $w->output();
        $notes = [['pdf.note.size', ['before' => self::kb(strlen($bytes)), 'after' => self::kb(strlen($out))]], ['pdf.note.streams', ['n' => (string) $changed]]];
        if (strlen($out) >= strlen($bytes)) {
            $notes[] = ['pdf.note.not_smaller', []];
        }
        $notes[] = ['pdf.note.images_unchanged', []];
        return self::result($out, $w->pageCount(), $r->warnings(), $notes);
    }

    /**
     * Rebuild from what can be read (damaged cross-reference, wrong lengths).
     *
     * @return array<string, mixed>
     */
    public static function repair(string $bytes, string $pw): array
    {
        $r = new Reader($bytes, $pw);
        $w = new Writer();
        $w->importPages($r, array_keys($r->pages()));
        $w->keepCatalog($r);
        $w->copyInfo($r);
        $notes = [[$r->repaired ? 'pdf.note.repaired' : 'pdf.note.no_repair_needed', []], ['pdf.note.repair_best_effort', []]];
        return self::result($w->output(), $w->pageCount(), $r->warnings(), $notes);
    }

    /** @return array<string, mixed> */
    public static function stripMetadata(string $bytes, string $pw): array
    {
        $r = new Reader($bytes, $pw);
        $w = new Writer();
        $w->importPages($r, array_keys($r->pages()), null, false);
        $w->keepCatalog($r, false);
        $w->setInfo(new Dict());
        return self::result($w->output(), $w->pageCount(), $r->warnings(), [['pdf.note.metadata_removed', []]]);
    }

    /** @return array<string, mixed> */
    public static function protect(string $bytes, string $pw, string $userPassword, string $ownerPassword, int $permissions): array
    {
        if (mb_strlen($userPassword) < 6) {
            throw new PdfError('pdf.error.password_short');
        }
        $r = new Reader($bytes, $pw);
        $w = new Writer();
        $w->importPages($r, array_keys($r->pages()));
        $w->keepCatalog($r);
        $w->copyInfo($r);
        return self::result(
            $w->output(Security::createR6($userPassword, $ownerPassword, $permissions)),
            $w->pageCount(),
            $r->warnings(),
            [['pdf.note.protected', []], ['pdf.note.permissions_advisory', []]]
        );
    }

    /**
     * Needs the password — there is no other way in.
     *
     * @return array<string, mixed>
     */
    public static function unlock(string $bytes, string $pw): array
    {
        $r = new Reader($bytes, $pw);
        if (!$r->encrypted) {
            throw new PdfError('pdf.error.not_encrypted');
        }
        $w = new Writer();
        $w->importPages($r, array_keys($r->pages()));
        $w->keepCatalog($r);
        $w->copyInfo($r);
        return self::result($w->output(), $w->pageCount(), array_values(array_diff($r->warnings(), ['pdf.warn.encrypted'])), [['pdf.note.unlocked', []]]);
    }

    // ---- stamping ------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function watermark(string $bytes, string $pw, string $text, float $size, float $opacity, int $angle, string $spec): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 120) {
            throw new PdfError('pdf.error.text');
        }
        $font = TrueTypeFont::thai();
        if (!$font->supports($text)) {
            throw new PdfError('pdf.error.font_chars');
        }
        $size = max(8.0, min(200.0, $size));
        $opacity = max(0.05, min(1.0, $opacity));
        return self::stamp($bytes, $pw, $spec, $font, static function (float $vw, float $vh) use ($font, $text, $size, $angle): string {
            $tw = $font->width($text, $size);
            $s = min($size, $size * ($angle === 0 ? $vw : hypot($vw, $vh)) * 0.85 / max($tw, 1)); // fit the page
            $tw = $font->width($text, $s);
            $rad = deg2rad($angle);
            return sprintf(
                "1 0 0 1 %s %s cm %s %s %s %s 0 0 cm BT /F_ros %s Tf 0.55 0.55 0.6 rg %s %s Td %s TJ ET",
                self::n($vw / 2),
                self::n($vh / 2),
                self::n(cos($rad)),
                self::n(sin($rad)),
                self::n(-sin($rad)),
                self::n(cos($rad)),
                self::n($s),
                self::n(-$tw / 2),
                self::n(-$s * 0.35),
                $font->encode($text)
            );
        }, $opacity, [['pdf.note.stamped', []]]);
    }

    /**
     * Page numbers / header / footer text: {n} = page number, {total} = page count.
     *
     * @return array<string, mixed>
     */
    public static function pageNumbers(string $bytes, string $pw, string $template, string $position, int $start, float $size, string $spec): array
    {
        $template = trim($template) === '' ? '{n}' : trim($template);
        if (mb_strlen($template) > 120) {
            throw new PdfError('pdf.error.text');
        }
        if (!in_array($position, self::POSITIONS, true)) {
            throw new PdfError('pdf.error.position');
        }
        $font = TrueTypeFont::thai();
        if (!$font->supports(str_replace(['{n}', '{total}'], '0', $template))) {
            throw new PdfError('pdf.error.font_chars');
        }
        $size = max(6.0, min(48.0, $size));
        $r = new Reader($bytes, $pw);
        $total = count(self::pageList($spec, count($r->pages())));
        $k = 0;
        return self::stamp($bytes, $pw, $spec, $font, static function (float $vw, float $vh) use ($font, $template, $position, $start, $size, $total, &$k): string {
            $text = str_replace(['{n}', '{total}'], [(string) ($start + $k), (string) ($start + $total - 1)], $template);
            $k++;
            $tw = $font->width($text, $size);
            $m = 28.0;
            [$vert, $hor] = explode('-', $position);
            $x = match ($hor) {
                'left' => $m,
                'right' => $vw - $m - $tw,
                default => ($vw - $tw) / 2,
            };
            $y = $vert === 'top' ? $vh - $m - $size * 0.8 : $m;
            return sprintf('BT /F_ros %s Tf 0.15 0.15 0.18 rg %s %s Td %s TJ ET', self::n($size), self::n($x), self::n($y), $font->encode($text));
        }, 1.0, [['pdf.note.stamped', []]]);
    }

    /**
     * M20 (DEC-81): signature boxes stamped where the sender placed them + evidence page(s) appended at the end.
     * A box is text only — the typed name, role, time and reference — never a picture of a hand-drawn signature.
     *
     * @param list<array{page: int, anchor: string, slot: int, lines: list<string>}> $boxes page is 1-based; anchor one of POSITIONS
     * @param list<string> $evidence lines of the evidence page (wrapped to the page width)
     * @return array<string, mixed>
     */
    public static function signedCopy(string $bytes, string $pw, array $boxes, string $evidenceTitle, array $evidence): array
    {
        $font = TrueTypeFont::thai();
        foreach ($boxes as $b) {
            if (!in_array($b['anchor'], self::POSITIONS, true)) {
                throw new PdfError('pdf.error.position');
            }
            foreach ($b['lines'] as $line) {
                if (!$font->supports($line)) {
                    throw new PdfError('pdf.error.font_chars');
                }
            }
        }
        $byPage = [];
        foreach ($boxes as $b) {
            $byPage[$b['page'] - 1][] = $b;
        }
        $spec = implode(',', array_map(static fn (int $p): string => (string) ($p + 1), array_keys($byPage)));
        $draw = static function (float $vw, float $vh, int $i) use ($byPage, $font): string {
            $ops = '';
            foreach ($byPage[$i] ?? [] as $b) {
                [$bw, $bh, $m, $gap] = [176.0, 64.0, 30.0, 6.0];
                [$vert, $hor] = explode('-', $b['anchor']);
                $x = match ($hor) {
                    'left' => $m,
                    'right' => $vw - $m - $bw,
                    default => ($vw - $bw) / 2,
                };
                $y = $vert === 'top' ? $vh - $m - $bh - $b['slot'] * ($bh + $gap) : $m + $b['slot'] * ($bh + $gap);
                $ops .= sprintf('q 0.45 0.5 0.6 RG 0.7 w %s %s %s %s re S Q ', self::n($x), self::n($y), self::n($bw), self::n($bh));
                $ty = $y + $bh - 14;
                foreach (array_slice($b['lines'], 0, 5) as $k => $line) {
                    $size = $k === 0 ? 10.0 : 7.5;
                    while ($size > 5 && $font->width($line, $size) > $bw - 10) {
                        $size -= 0.5;
                    }
                    $ops .= sprintf('BT /F_ros %s Tf %s rg %s %s Td %s TJ ET ', self::n($size), $k === 0 ? '0.05 0.1 0.25' : '0.2 0.2 0.25', self::n($x + 5), self::n($ty), $font->encode($line));
                    $ty -= $k === 0 ? 13 : 10;
                }
            }
            return $ops;
        };
        $after = static function (Writer $w, Ref $fontRef) use ($font, $evidenceTitle, $evidence): void {
            [$W, $H, $m] = [595.28, 841.89, 50.0];
            $wrapped = [];
            foreach ($evidence as $line) {
                $line = implode('', array_map(static fn (string $ch): string => $font->supports($ch) ? $ch : '?', mb_str_split($line))); // never a missing glyph
                $cur = '';
                foreach (preg_split('/(?<=\s)/u', $line) ?: [] as $word) {
                    if ($cur !== '' && $font->width($cur . $word, 9.5) > $W - 2 * $m) {
                        $wrapped[] = rtrim($cur);
                        $cur = '';
                    }
                    $cur .= $word;
                }
                $wrapped[] = rtrim($cur);
            }
            $chunks = array_chunk($wrapped, 52);
            foreach ($chunks === [] ? [[]] : $chunks as $n => $chunk) {
                $ops = sprintf('BT /F_ros 14 Tf 0.05 0.1 0.25 rg %s %s Td %s TJ ET ', self::n($m), self::n($H - $m - 10), $font->encode($evidenceTitle . ($n > 0 ? ' (' . ($n + 1) . ')' : '')));
                $y = $H - $m - 40;
                foreach ($chunk as $line) {
                    if ($line !== '') {
                        $ops .= sprintf('BT /F_ros 9.5 Tf 0.1 0.1 0.15 rg %s %s Td %s TJ ET ', self::n($m), self::n($y), $font->encode($line));
                    }
                    $y -= 14;
                }
                $cref = $w->add(new Stream(new Dict(['Filter' => new Name('FlateDecode')]), Filters::deflate($ops)));
                $w->addPage(new Dict(['Type' => new Name('Page'), 'MediaBox' => [0, 0, $W, $H], 'Resources' => new Dict(['Font' => new Dict(['F_ros' => $fontRef])]), 'Contents' => $cref]));
            }
        };
        return self::stamp($bytes, $pw, $spec, $font, $draw, 1.0, [['pdf.note.stamped', []]], $after);
    }

    // ---- conversion ----------------------------------------------------------------------------------

    /**
     * @param list<string> $images
     * @return array<string, mixed>
     */
    public static function imagesToPdf(array $images, string $pageSize, float $margin): array
    {
        if ($images === []) {
            throw new PdfError('pdf.error.no_input');
        }
        if (!array_key_exists($pageSize, self::PAGE_SIZES)) {
            $pageSize = 'A4';
        }
        $margin = max(0.0, min(72.0, $margin));
        $w = new Writer();
        $kids = [];
        foreach ($images as $b) {
            [$x, $pw, $ph] = Images::xobject($w, $b);
            $iw = $pw * 0.75; // 96 dpi → points
            $ih = $ph * 0.75;
            $size = self::PAGE_SIZES[$pageSize];
            if ($size === null) {
                [$W, $H] = [$iw + 2 * $margin, $ih + 2 * $margin];
                $scale = 1.0;
            } else {
                [$W, $H] = $size;
                if ($pageSize === 'A4' && $iw > $ih) {
                    [$W, $H] = [$H, $W]; // landscape picture → landscape page
                }
                $scale = min(($W - 2 * $margin) / $iw, ($H - 2 * $margin) / $ih); // fit inside the margins
            }
            $dw = $iw * $scale;
            $dh = $ih * $scale;
            $content = sprintf("q %s 0 0 %s %s %s cm /Im0 Do Q", self::n($dw), self::n($dh), self::n(($W - $dw) / 2), self::n(($H - $dh) / 2));
            $cref = $w->add(new Stream(new Dict(['Filter' => new Name('FlateDecode')]), Filters::deflate($content)));
            $kids[] = new Dict(['Type' => new Name('Page'), 'MediaBox' => [0, 0, round($W, 2), round($H, 2)],
                'Resources' => new Dict(['XObject' => new Dict(['Im0' => $x]), 'ProcSet' => [new Name('PDF'), new Name('ImageC'), new Name('ImageB'), new Name('ImageI')]]), 'Contents' => $cref]);
        }
        $refs = [];
        foreach ($kids as $k) {
            $refs[] = $w->addPage($k);
        }
        return self::result($w->output(), count($refs), [], [['pdf.note.images', ['n' => (string) count($refs)]]]);
    }

    // ---- helpers ----------------------------------------------------------------------------------------

    /**
     * @param callable(float, float, int): string $draw content in visual coordinates (origin bottom-left of the page as seen); gets the 0-based page index too
     * @param list<array{0: string, 1: array<string, string>}> $notes
     * @param (callable(Writer, Ref): void)|null $after adds pages at the end (before the font is written)
     * @return array<string, mixed>
     */
    private static function stamp(string $bytes, string $pw, string $spec, TrueTypeFont $font, callable $draw, float $opacity, array $notes, ?callable $after = null): array
    {
        $r = new Reader($bytes, $pw);
        $sel = array_flip(self::pageList($spec, count($r->pages())));
        $w = new Writer();
        $fontRef = $font->ref($w);
        $gs = $w->add(new Dict(['Type' => new Name('ExtGState'), 'ca' => $opacity, 'CA' => $opacity]));
        $w->importPages($r, array_keys($r->pages()), static function (Dict $page, int $i) use ($w, $sel, $draw, $fontRef, $gs): void {
            if (!isset($sel[$i])) {
                return;
            }
            $res = $w->get($page->get('Resources'));
            $res = $res instanceof Dict ? new Dict($res->items) : new Dict();
            foreach (['Font' => ['F_ros', $fontRef], 'ExtGState' => ['GS_ros', $gs]] as $cat => [$key, $ref]) {
                $sub = $w->get($res->get($cat));
                $sub = $sub instanceof Dict ? new Dict($sub->items) : new Dict();
                $sub->set($key, $ref);
                $res->set($cat, $sub);
            }
            $page->set('Resources', $res);
            [$x0, $y0, $x1, $y1] = self::box($w->get($page->get('CropBox')) ?? $w->get($page->get('MediaBox')));
            $rot = (((int) ($w->get($page->get('Rotate')) ?? 0) % 360) + 360) % 360;
            $m = match ($rot) {
                90 => [0, 1, -1, 0, $x1, $y0],
                180 => [-1, 0, 0, -1, $x1, $y1],
                270 => [0, -1, 1, 0, $x0, $y1],
                default => [1, 0, 0, 1, $x0, $y0],
            };
            [$vw, $vh] = $rot % 180 === 0 ? [$x1 - $x0, $y1 - $y0] : [$y1 - $y0, $x1 - $x0];
            $ops = "Q q /GS_ros gs " . implode(' ', array_map([self::class, 'n'], $m)) . " cm " . $draw($vw, $vh, $i) . " Q\n";
            $contents = $page->get('Contents');
            $list = [];
            $resolved = $w->get($contents);
            if (is_array($resolved)) {
                $list = $resolved;
            } elseif ($contents instanceof Ref) {
                $list = [$contents];
            }
            $open = $w->add(new Stream(new Dict(), "q\n"));
            $close = $w->add(new Stream(new Dict(['Filter' => new Name('FlateDecode')]), Filters::deflate($ops)));
            $page->set('Contents', [$open, ...$list, $close]);
        });
        if ($after !== null) {
            $after($w, $fontRef);
        }
        $font->finish($w);
        $w->keepCatalog($r);
        $w->copyInfo($r);
        $warn = $r->warnings();
        return self::result($w->output(), $w->pageCount(), $warn, $notes);
    }

    private static function addRotation(Dict $page, int $deg): void
    {
        $cur = (int) ($page->get('Rotate') ?? 0);
        $page->set('Rotate', ((($cur + $deg) % 360) + 360) % 360);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function box(mixed $b): array
    {
        if (!is_array($b) || count($b) < 4) {
            return [0.0, 0.0, 612.0, 792.0];
        }
        $v = array_map(static fn (mixed $x): float => is_int($x) || is_float($x) ? (float) $x : 0.0, array_slice($b, 0, 4));
        return [min($v[0], $v[2]), min($v[1], $v[3]), max($v[0], $v[2]), max($v[1], $v[3])];
    }

    /** @return array{0: float, 1: float} */
    private static function visualSize(Reader $r, Dict $p): array
    {
        [$x0, $y0, $x1, $y1] = self::box($r->resolve($p->get('CropBox')) ?? $r->resolve($p->get('MediaBox')));
        $rot = (int) ($r->resolve($p->get('Rotate')) ?? 0);
        return $rot % 180 === 0 ? [$x1 - $x0, $y1 - $y0] : [$y1 - $y0, $x1 - $x0];
    }

    private static function sizeName(float $w, float $h): string
    {
        foreach (['A4' => [595, 842], 'A3' => [842, 1191], 'A5' => [420, 595], 'Letter' => [612, 792], 'Legal' => [612, 1008]] as $name => [$a, $b]) {
            if (abs($w - $a) < 3 && abs($h - $b) < 3) {
                return $name;
            }
            if (abs($w - $b) < 3 && abs($h - $a) < 3) {
                return $name . ' ⟳';
            }
        }
        return sprintf('%d × %d mm', (int) round($w / 72 * 25.4), (int) round($h / 72 * 25.4));
    }

    public static function n(float|int $v): string
    {
        $s = rtrim(rtrim(sprintf('%.4F', (float) $v), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }

    private static function kb(int $b): string
    {
        return number_format($b / 1024, 1) . ' KB';
    }

    private static function clean(string $s): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    }

    /**
     * @param list<string> $warnings
     * @param list<array{0: string, 1: array<string, string>}> $notes
     * @param list<array{name: string, bytes: string, pages: int}> $parts
     * @return array<string, mixed>
     */
    private static function result(string $bytes, int $pages, array $warnings, array $notes = [], array $parts = []): array
    {
        return ['bytes' => $bytes, 'pages' => $pages, 'warnings' => array_values(array_unique($warnings)), 'notes' => $notes, 'parts' => $parts];
    }
}
