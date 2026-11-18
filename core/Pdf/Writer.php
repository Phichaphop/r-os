<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * Builds a new PDF from pages of one or more Readers (objects are copied and renumbered; references to pages that are
 * not copied become null so a link never drags a whole other page along). Output: classic cross-reference table,
 * optionally encrypted with AES-256 (R6). The sources are never modified.
 */
final class Writer
{
    /** @var array<int, mixed> */
    private array $objects = [];
    private int $next = 1;
    /** @var array<int, array<int, int>> reader id => old num => new num */
    private array $maps = [];
    /** @var array<int, array<int, true>> reader id => page object numbers of that reader */
    private array $pageNums = [];
    /** @var array<int, array<int, true>> reader id => source pages already written */
    private array $usedPages = [];
    /** @var list<Ref> pages of the new document, in order */
    private array $kids = [];
    private ?Dict $catalogExtra = null;
    private ?Dict $info = null;
    private string $version = '1.7';

    public function reserve(): int
    {
        return $this->next++;
    }

    public function add(mixed $value): Ref
    {
        $n = $this->reserve();
        $this->objects[$n] = $value;
        return new Ref($n);
    }

    public function set(int $num, mixed $value): void
    {
        $this->objects[$num] = $value;
    }

    public function get(mixed $v): mixed
    {
        $guard = 0;
        while ($v instanceof Ref && $guard++ < 32) {
            $v = $this->objects[$v->num] ?? null;
        }
        return $v;
    }

    public function version(string $v): void
    {
        if (version_compare($v, $this->version, '>')) {
            $this->version = $v;
        }
    }

    /** Appends a page built from scratch (e.g. a picture page). */
    public function addPage(Dict $page): Ref
    {
        $ref = $this->add($page);
        $this->kids[] = $ref;
        return $ref;
    }

    public function pageCount(): int
    {
        return count($this->kids);
    }

    /**
     * Copies pages (0-based indexes, any order, repeats allowed) and appends them.
     *
     * @param list<int> $indexes
     * @param callable(Dict, int): void|null $modify called with each new page dict and its source index
     * @return list<Ref>
     */
    public function importPages(Reader $r, array $indexes, ?callable $modify = null, bool $keepMetadata = true): array
    {
        $rid = spl_object_id($r);
        $pages = $r->pages();
        if (!isset($this->pageNums[$rid])) {
            $this->pageNums[$rid] = [];
            foreach ($pages as [$ref]) {
                $this->pageNums[$rid][$ref->num] = true;
            }
            $this->maps[$rid] = [];
        }
        $new = [];
        foreach ($indexes as $i) { // map every requested page first so links between them survive
            if (!isset($pages[$i])) {
                throw new PdfError('pdf.error.page_range');
            }
            $this->maps[$rid][$pages[$i][0]->num] ??= $this->reserve();
        }
        foreach ($indexes as $i) {
            $old = $pages[$i][0]->num;
            if (!isset($this->usedPages[$rid][$old])) {
                $this->usedPages[$rid][$old] = true;
                $num = $this->maps[$rid][$old];
            } else {
                $num = $this->reserve(); // the same page again → a separate page object
            }
            $page = new Dict();
            foreach ($pages[$i][1]->items as $k => $v) {
                if (in_array($k, ['Parent', 'B', 'StructParents', 'PieceInfo', 'Tabs', 'ID', 'PZ', 'SeparationInfo'], true) || (!$keepMetadata && $k === 'Metadata')) {
                    continue;
                }
                $page->set($k, $this->copy($r, $v));
            }
            $page->set('Type', new Name('Page'));
            if ($modify !== null) {
                $modify($page, $i);
            }
            $this->objects[$num] = $page;
            $ref = new Ref($num);
            $this->kids[] = $ref;
            $new[] = $ref;
        }
        $this->version($r->version());
        return $new;
    }

    /** Copies the catalog entries worth keeping when the whole document is kept (outlines, forms, names …). */
    public function keepCatalog(Reader $r, bool $keepMetadata = true): void
    {
        $cat = $r->catalog();
        if ($cat === null) {
            return;
        }
        $extra = new Dict();
        foreach ($cat->items as $k => $v) {
            if (in_array($k, ['Type', 'Pages', 'Extensions', 'Perms', 'DSS', 'StructTreeRoot', 'MarkInfo'], true) || (!$keepMetadata && $k === 'Metadata')) {
                continue;
            }
            $extra->set($k, $this->copy($r, $v));
        }
        $this->catalogExtra = $extra;
    }

    /** Light catalog entries only (language, viewer preferences) — for merge/split/extract. */
    public function keepBasics(Reader $r): void
    {
        $cat = $r->catalog();
        if ($cat === null || $this->catalogExtra !== null) {
            return;
        }
        $extra = new Dict();
        foreach (['Lang', 'ViewerPreferences', 'PageLayout'] as $k) {
            if ($cat->has($k)) {
                $extra->set($k, $this->copy($r, $cat->get($k)));
            }
        }
        $this->catalogExtra = $extra;
    }

    public function setInfo(?Dict $info): void
    {
        $this->info = $info;
    }

    public function copyInfo(Reader $r): void
    {
        $i = $r->info();
        $this->info = $i === null ? new Dict() : $this->copy($r, $i);
    }

    /** Deep copy of a value of $r into this document. */
    public function copy(Reader $r, mixed $v): mixed
    {
        if ($v instanceof Ref) {
            $rid = spl_object_id($r);
            if (isset($this->maps[$rid][$v->num])) {
                return new Ref($this->maps[$rid][$v->num]);
            }
            if (isset($this->pageNums[$rid][$v->num])) {
                return null; // a page that is not part of the new document
            }
            $num = $this->reserve();
            $this->maps[$rid][$v->num] = $num;
            $this->objects[$num] = null;
            $this->objects[$num] = $this->copy($r, $r->object($v->num));
            return new Ref($num);
        }
        if ($v instanceof Dict) {
            $d = new Dict();
            foreach ($v->items as $k => $x) {
                $d->set($k, $this->copy($r, $x));
            }
            return $d;
        }
        if ($v instanceof Stream) {
            $d = $this->copy($r, $v->dict);
            return new Stream($d instanceof Dict ? $d : new Dict(), $v->data);
        }
        if (is_array($v)) {
            return array_map(fn (mixed $x): mixed => $this->copy($r, $x), $v);
        }
        if ($v instanceof Str) {
            return new Str($v->bytes, $v->hex);
        }
        return $v;
    }

    /**
     * Flate-compresses or re-compresses streams where that makes them smaller (Compress).
     *
     * @return array{0: int, 1: int} streams changed, bytes saved
     */
    public function recompress(): array
    {
        $changed = 0;
        $saved = 0;
        foreach ($this->objects as $n => $o) {
            if (!$o instanceof Stream) {
                continue;
            }
            $chain = Filters::chain($o->dict, fn (mixed $v): mixed => $this->get($v));
            $raw = null;
            if ($chain === []) {
                $raw = $o->data;
            } elseif (count($chain) === 1 && $chain[0][0] === 'FlateDecode' && $chain[0][1] === null) {
                try {
                    $raw = Filters::inflate($o->data, Reader::MAX_STREAM);
                } catch (PdfError) {
                    $raw = null;
                }
            }
            if ($raw === null) {
                continue;
            }
            $z = Filters::deflate($raw);
            if (strlen($z) + 20 < strlen($o->data)) {
                $saved += strlen($o->data) - strlen($z);
                $o->data = $z;
                $o->dict->set('Filter', new Name('FlateDecode'));
                $o->dict->remove('DecodeParms');
                $changed++;
            }
            $this->objects[$n] = $o;
        }
        return [$changed, $saved];
    }

    /** @param array{0: Dict, 1: Security}|null $encryption from Security::createR6 */
    public function output(?array $encryption = null): string
    {
        $pagesNum = $this->reserve();
        foreach ($this->kids as $k) {
            $p = $this->objects[$k->num];
            if ($p instanceof Dict) {
                $p->set('Parent', new Ref($pagesNum));
            }
        }
        $this->objects[$pagesNum] = new Dict(['Type' => new Name('Pages'), 'Kids' => $this->kids, 'Count' => count($this->kids)]);
        $cat = new Dict(['Type' => new Name('Catalog'), 'Pages' => new Ref($pagesNum)]);
        foreach ($this->catalogExtra?->items ?? [] as $k => $v) {
            $cat->set($k, $v);
        }
        $sec = null;
        $encRef = null;
        if ($encryption !== null) {
            [$encDict, $sec] = $encryption;
            $cat->set('Extensions', new Dict(['ADBE' => new Dict(['BaseVersion' => new Name('1.7'), 'ExtensionLevel' => 8])]));
            $encRef = $this->add($encDict);
        }
        $catRef = $this->add($cat);
        $info = $this->info ?? new Dict();
        $info->set('Producer', new Str('Blue OS PDF Toolkit'));
        $infoRef = $this->add($info);

        $out = '%PDF-' . $this->version . "\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $size = $this->next;
        for ($n = 1; $n < $size; $n++) {
            $offsets[$n] = strlen($out);
            $o = $this->objects[$n] ?? null;
            $enc = ($sec !== null && $n !== $encRef?->num) ? $sec : null;
            $out .= $n . " 0 obj\n" . $this->serializeObject($o, $n, $enc) . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . $size . "\n0000000000 65535 f\r\n";
        for ($n = 1; $n < $size; $n++) {
            $out .= sprintf("%010d 00000 n\r\n", $offsets[$n]);
        }
        $id = random_bytes(16);
        $trailer = new Dict(['Size' => $size, 'Root' => $catRef, 'Info' => $infoRef, 'ID' => [new Str($id, true), new Str($id, true)]]);
        if ($encRef !== null) {
            $trailer->set('Encrypt', $encRef);
        }
        $out .= "trailer\n" . self::serialize($trailer, null, 0) . "\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }

    private function serializeObject(mixed $o, int $num, ?Security $sec): string
    {
        if ($o instanceof Stream) {
            $data = $o->data;
            $dict = new Dict($o->dict->items);
            if ($sec !== null) {
                $data = $sec->encryptStream($num, 0, $data);
            }
            $dict->set('Length', strlen($data));
            return self::serialize($dict, $sec, $num) . "\nstream\n" . $data . "\nendstream";
        }
        return self::serialize($o, $sec, $num);
    }

    public static function serialize(mixed $v, ?Security $sec, int $num): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            if (!is_finite($v)) {
                return '0';
            }
            $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
            return $s === '-0' || $s === '' ? '0' : $s;
        }
        if ($v instanceof Name) {
            return '/' . (string) preg_replace_callback('/[^\x21-\x7E]|[#()<>\[\]{}\/%]/', static fn (array $m): string => sprintf('#%02X', ord($m[0])), $v->name);
        }
        if ($v instanceof Ref) {
            return $v->num . ' ' . $v->gen . ' R';
        }
        if ($v instanceof Str) {
            $b = $sec !== null ? $sec->encryptString($num, 0, $v->bytes) : $v->bytes;
            if ($v->hex || $sec !== null || preg_match('/[\x00-\x08\x0B\x0E-\x1F\x80-\xFF]/', $b) === 1) {
                return '<' . bin2hex($b) . '>';
            }
            return '(' . strtr($b, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']) . ')';
        }
        if (is_array($v)) {
            return '[' . implode(' ', array_map(static fn (mixed $x): string => self::serialize($x, $sec, $num), $v)) . ']';
        }
        if ($v instanceof Dict) {
            $parts = [];
            foreach ($v->items as $k => $x) {
                $parts[] = self::serialize(new Name((string) $k), null, $num) . ' ' . self::serialize($x, $sec, $num);
            }
            return '<<' . implode(' ', $parts) . '>>';
        }
        if ($v instanceof Stream) { // a stream must be an indirect object; inline ones cannot be written
            return 'null';
        }
        return 'null';
    }
}
