<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * Reads a PDF: cross-reference tables and streams (incl. hybrid files and /Prev chains), object streams, encrypted
 * files (with the password), and damaged files (the cross-reference is rebuilt by scanning — $repaired = true).
 * Objects are loaded lazily and cached. JavaScript and embedded files are never executed — only reported.
 */
final class Reader
{
    public const MAX_OBJECTS = 300000;
    public const MAX_STREAM = 64 * 1024 * 1024;

    /** @var array<int, array{0: int, 1: int, 2: int}> num => [type 1|2, offset|objstm, gen|index] */
    private array $xref = [];
    /** @var array<int, mixed> */
    private array $cache = [];
    /** @var array<int, array<int, int>> objstm number => [object number => offset] */
    private array $objStreams = [];
    /** @var array<int, string> decoded object stream bodies */
    private array $objStreamData = [];
    /** @var array<int, true> */
    private array $loading = [];
    public Dict $trailer;
    public bool $repaired = false;
    public ?Security $security = null;
    public bool $encrypted = false;
    private ?int $encryptNum = null;
    /** @var list<array{0: Ref, 1: Dict}>|null */
    private ?array $pages = null;

    public function __construct(public readonly string $bytes, string $password = '')
    {
        if (!str_contains(substr($bytes, 0, 1024), '%PDF-')) {
            throw new PdfError('pdf.error.not_pdf');
        }
        try {
            $this->readXref();
            $this->catalog();
        } catch (PdfError $e) {
            if (in_array($e->messageKey, ['pdf.error.password', 'pdf.error.encryption_unsupported', 'pdf.error.too_big'], true)) {
                throw $e;
            }
            $this->repair();
        }
        $enc = $this->trailer->get('Encrypt');
        if ($enc !== null) {
            $this->encrypted = true;
            if ($enc instanceof Ref) {
                $this->encryptNum = $enc->num;
            }
            $encDict = $this->resolve($enc);
            if (!$encDict instanceof Dict) {
                throw new PdfError('pdf.error.corrupt', [], 'bad /Encrypt');
            }
            $id = $this->trailer->get('ID');
            $first = is_array($id) && ($id[0] ?? null) instanceof Str ? $id[0]->bytes : '';
            $this->security = Security::open($encDict, $first, $password);
            $this->cache = []; // drop anything parsed before the key was known
        }
        if (!$this->catalog() instanceof Dict) {
            throw new PdfError('pdf.error.corrupt', [], 'no catalog');
        }
    }

    public function catalog(): ?Dict
    {
        $c = $this->resolve($this->trailer->get('Root'));
        return $c instanceof Dict ? $c : null;
    }

    public function info(): ?Dict
    {
        $i = $this->resolve($this->trailer->get('Info'));
        return $i instanceof Dict ? $i : null;
    }

    public function version(): string
    {
        return preg_match('/%PDF-(\d\.\d)/', substr($this->bytes, 0, 1024), $m) === 1 ? $m[1] : '1.4';
    }

    public function resolve(mixed $v): mixed
    {
        $guard = 0;
        while ($v instanceof Ref) {
            $v = $this->object($v->num);
            if (++$guard > 32) {
                return null;
            }
        }
        return $v;
    }

    /** @return list<int> */
    public function objectNumbers(): array
    {
        return array_keys($this->xref);
    }

    public function object(int $num): mixed
    {
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        $e = $this->xref[$num] ?? null;
        if ($e === null || isset($this->loading[$num])) {
            return null;
        }
        $this->loading[$num] = true;
        try {
            if ($e[0] === 2) {
                $v = $this->fromObjectStream($e[1], $num);
            } else {
                [$n, $g, $v] = $this->parseIndirect($e[1]);
                if ($n !== $num) {
                    $v = null;
                }
                if ($this->security !== null && $num !== $this->encryptNum) {
                    $v = $this->decrypt($v, $num, $g);
                }
            }
        } catch (PdfError $ex) {
            if ($ex->messageKey === 'pdf.error.too_big') {
                throw $ex;
            }
            $v = null; // one broken object should not stop the rest (best effort)
        } finally {
            unset($this->loading[$num]);
        }
        return $this->cache[$num] = $v;
    }

    /**
     * Leaf pages in order, each with inherited attributes (Resources, MediaBox, CropBox, Rotate) made explicit.
     *
     * @return list<array{0: Ref, 1: Dict}>
     */
    public function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $out = [];
        $seen = [];
        $root = $this->catalog()?->get('Pages');
        $walk = function (mixed $node, array $inherit, int $depth) use (&$walk, &$out, &$seen): void {
            if (!$node instanceof Ref || isset($seen[$node->num]) || $depth > 64 || count($out) > 100000) {
                return;
            }
            $seen[$node->num] = true;
            $d = $this->object($node->num);
            if (!$d instanceof Dict) {
                return;
            }
            foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $k) {
                if ($d->has($k)) {
                    $inherit[$k] = $d->get($k);
                }
            }
            $kids = $this->resolve($d->get('Kids'));
            if ($d->name('Type') === 'Pages' || (is_array($kids) && $d->name('Type') !== 'Page')) {
                foreach (is_array($kids) ? $kids : [] as $k) {
                    $walk($k, $inherit, $depth + 1);
                }
                return;
            }
            $page = new Dict($d->items);
            foreach ($inherit as $k => $v) {
                if (!$page->has($k)) {
                    $page->set($k, $v);
                }
            }
            if (!$page->has('MediaBox')) {
                $page->set('MediaBox', [0, 0, 612, 792]);
            }
            $out[] = [$node, $page];
        };
        $walk($root, [], 0);
        return $this->pages = $out;
    }

    /**
     * Things worth warning about before changing the file (S01 6.24.3).
     *
     * @return list<string> translation keys
     */
    public function warnings(): array
    {
        $w = [];
        $cat = $this->catalog();
        if ($cat === null) {
            return $w;
        }
        $acro = $this->resolve($cat->get('AcroForm'));
        if ($acro instanceof Dict) {
            $fields = $this->resolve($acro->get('Fields'));
            $sig = false;
            foreach (is_array($fields) ? array_slice($fields, 0, 500) : [] as $f) {
                $fd = $this->resolve($f);
                if ($fd instanceof Dict && $fd->name('FT') === 'Sig' && $fd->get('V') !== null) {
                    $sig = true;
                }
            }
            $w[] = $sig ? 'pdf.warn.signature' : 'pdf.warn.forms';
        }
        if ($this->resolve($cat->get('Perms')) instanceof Dict) {
            $w[] = 'pdf.warn.signature';
        }
        $names = $this->resolve($cat->get('Names'));
        if ($names instanceof Dict && $names->has('EmbeddedFiles')) {
            $w[] = 'pdf.warn.attachments';
        }
        if ($names instanceof Dict && $names->has('JavaScript') || $cat->has('OpenAction') && $this->isJs($cat->get('OpenAction'))) {
            $w[] = 'pdf.warn.javascript';
        }
        if ($cat->has('Outlines')) {
            $w[] = 'pdf.warn.outlines';
        }
        if ($this->encrypted) {
            $w[] = 'pdf.warn.encrypted';
        }
        if ($this->repaired) {
            $w[] = 'pdf.warn.repaired';
        }
        return array_values(array_unique($w));
    }

    /** Decoded stream data (non-image filters). */
    public function streamData(Stream $s): string
    {
        return Filters::decode($s, self::MAX_STREAM, fn (mixed $v): mixed => $this->resolve($v));
    }

    // ---- cross-reference --------------------------------------------------------------------------

    private function readXref(): void
    {
        $tail = substr($this->bytes, -2048);
        if (preg_match_all('/startxref\s+(\d+)/', $tail, $m) < 1) {
            throw new PdfError('pdf.error.corrupt', [], 'no startxref');
        }
        $offset = (int) end($m[1]);
        $trailer = null;
        $visited = [];
        while ($offset > 0 && !isset($visited[$offset]) && count($visited) < 100) {
            $visited[$offset] = true;
            $t = $this->xrefSection($offset);
            $trailer ??= $t;
            if (is_int($t->get('XRefStm'))) {
                $this->xrefSection((int) $t->get('XRefStm'));
            }
            $prev = $t->get('Prev');
            $offset = is_int($prev) ? $prev : 0;
        }
        if ($trailer === null || $this->xref === []) {
            throw new PdfError('pdf.error.corrupt', [], 'empty xref');
        }
        $this->trailer = $trailer;
    }

    private function xrefSection(int $offset): Dict
    {
        $p = new Parser($this->bytes);
        $p->pos = $offset;
        $p->skipWs();
        if (substr($this->bytes, $p->pos, 4) === 'xref') {
            $p->pos += 4;
            while (true) {
                $p->skipWs();
                if (substr($this->bytes, $p->pos, 7) === 'trailer') {
                    $p->pos += 7;
                    $t = $p->value();
                    if (!$t instanceof Dict) {
                        throw new PdfError('pdf.error.corrupt', [], 'bad trailer');
                    }
                    return $t;
                }
                $start = $p->token();
                $count = $p->token();
                if (!ctype_digit($start) || !ctype_digit($count)) {
                    throw new PdfError('pdf.error.corrupt', [], 'bad xref subsection');
                }
                $p->skipWs();
                for ($i = 0; $i < (int) $count; $i++) {
                    $line = substr($this->bytes, $p->pos, 20);
                    if (preg_match('/^(\d{10}) (\d{5}) ([nf])/', $line, $mm) !== 1) {
                        // tolerate 19-byte lines and stray whitespace
                        if (preg_match('/\G\s*(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->bytes, $mm, 0, $p->pos) !== 1) {
                            throw new PdfError('pdf.error.corrupt', [], 'bad xref entry');
                        }
                        $p->pos += strlen($mm[0]);
                    } else {
                        $p->pos += 20;
                    }
                    $num = (int) $start + $i;
                    if ($mm[3] === 'n' && !isset($this->xref[$num]) && (int) $mm[1] > 0) {
                        $this->xref[$num] = [1, (int) $mm[1], (int) $mm[2]];
                    }
                    if (count($this->xref) > self::MAX_OBJECTS) {
                        throw new PdfError('pdf.error.too_big');
                    }
                }
            }
        }
        [, , $s] = $this->parseIndirect($offset);
        if (!$s instanceof Stream || $s->dict->name('Type') !== 'XRef') {
            throw new PdfError('pdf.error.corrupt', [], 'bad xref stream');
        }
        $data = Filters::decode($s, self::MAX_STREAM);
        $w = $s->dict->get('W');
        if (!is_array($w) || count($w) < 3) {
            throw new PdfError('pdf.error.corrupt', [], 'bad /W');
        }
        $w = array_map('intval', $w);
        $index = $s->dict->get('Index');
        $index = is_array($index) ? array_map('intval', $index) : [0, (int) $s->dict->get('Size')];
        $rowLen = array_sum($w);
        $pos = 0;
        for ($k = 0; $k + 1 < count($index); $k += 2) {
            for ($i = 0; $i < $index[$k + 1]; $i++) {
                if ($pos + $rowLen > strlen($data) || $rowLen === 0) {
                    break 2;
                }
                $f = [];
                $q = $pos;
                foreach ($w as $j => $width) {
                    $val = 0;
                    for ($b = 0; $b < $width; $b++) {
                        $val = ($val << 8) | ord($data[$q++]);
                    }
                    $f[$j] = $width === 0 ? ($j === 0 ? 1 : 0) : $val;
                }
                $pos += $rowLen;
                $num = $index[$k] + $i;
                if (!isset($this->xref[$num]) && ($f[0] === 1 || $f[0] === 2)) {
                    $this->xref[$num] = [$f[0], $f[1], $f[2]];
                }
                if (count($this->xref) > self::MAX_OBJECTS) {
                    throw new PdfError('pdf.error.too_big');
                }
            }
        }
        return $s->dict;
    }

    /** Rebuilds the cross-reference by scanning for "n g obj" (damaged files). */
    private function repair(): void
    {
        $this->xref = [];
        $this->cache = [];
        $this->objStreams = [];
        $this->objStreamData = [];
        $this->pages = null;
        $this->repaired = true;
        if (preg_match_all('/(?<![0-9])(\d{1,10})\s+(\d{1,5})\s+obj\b/', $this->bytes, $m, PREG_OFFSET_CAPTURE) < 1) {
            throw new PdfError('pdf.error.corrupt', [], 'no objects');
        }
        foreach ($m[1] as $i => [$num, $off]) {
            $this->xref[(int) $num] = [1, (int) $off, (int) $m[2][$i][0]];
            if (count($this->xref) > self::MAX_OBJECTS) {
                throw new PdfError('pdf.error.too_big');
            }
        }
        // objects inside object streams
        foreach (array_keys($this->xref) as $num) {
            $e = $this->xref[$num];
            if ($e[0] !== 1) {
                continue;
            }
            try {
                [, , $v] = $this->parseIndirect($e[1]);
            } catch (PdfError) {
                continue;
            }
            if ($v instanceof Stream && $v->dict->name('Type') === 'ObjStm') {
                foreach (array_keys($this->objStreamIndex($num, $v)) as $inner) {
                    $this->xref[$inner] ??= [2, $num, 0];
                }
            }
        }
        $trailer = null;
        if (preg_match_all('/trailer\s*<</', $this->bytes, $tm, PREG_OFFSET_CAPTURE) > 0) {
            foreach (array_reverse($tm[0]) as [, $off]) {
                $p = new Parser($this->bytes);
                $p->pos = $off + 7;
                try {
                    $t = $p->value();
                } catch (PdfError) {
                    continue;
                }
                if ($t instanceof Dict && $t->get('Root') instanceof Ref) {
                    $trailer = $t;
                    break;
                }
            }
        }
        if ($trailer === null) {
            foreach (array_keys($this->xref) as $num) { // xref streams carry the trailer keys
                $o = $this->object($num);
                $d = $o instanceof Stream ? $o->dict : $o;
                if ($d instanceof Dict && $d->name('Type') === 'XRef' && $d->get('Root') instanceof Ref) {
                    $trailer = new Dict($d->items);
                }
            }
        }
        if ($trailer === null) {
            foreach (array_keys($this->xref) as $num) {
                $o = $this->object($num);
                if ($o instanceof Dict && $o->name('Type') === 'Catalog') {
                    $trailer = new Dict(['Root' => new Ref($num)]);
                    break;
                }
            }
        }
        if ($trailer === null) {
            throw new PdfError('pdf.error.corrupt', [], 'no catalog');
        }
        $this->cache = [];
        $this->trailer = $trailer;
    }

    // ---- objects ----------------------------------------------------------------------------------

    /** @return array{0: int, 1: int, 2: mixed} */
    private function parseIndirect(int $offset): array
    {
        $p = new Parser($this->bytes);
        $p->pos = $offset;
        $num = $p->token();
        $gen = $p->token();
        $kw = $p->token();
        if (!ctype_digit($num) || !ctype_digit($gen) || $kw !== 'obj') {
            throw new PdfError('pdf.error.corrupt', [], 'object expected at ' . $offset);
        }
        $v = $p->value();
        $p->skipWs();
        if ($v instanceof Dict && substr($this->bytes, $p->pos, 6) === 'stream') {
            $p->pos += 6;
            if (($this->bytes[$p->pos] ?? '') === "\r") {
                $p->pos++;
            }
            if (($this->bytes[$p->pos] ?? '') === "\n") {
                $p->pos++;
            }
            $start = $p->pos;
            $len = $v->get('Length');
            if ($len instanceof Ref) {
                $len = $this->resolveLength($len->num);
            }
            $data = null;
            if (is_int($len) && $len >= 0 && $start + $len <= strlen($this->bytes)) {
                $after = ltrim(substr($this->bytes, $start + $len, 32), "\r\n \t\0");
                if (str_starts_with($after, 'endstream')) {
                    $data = substr($this->bytes, $start, $len);
                }
            }
            if ($data === null) { // wrong /Length: find endstream
                $end = strpos($this->bytes, 'endstream', $start);
                if ($end === false) {
                    throw new PdfError('pdf.error.corrupt', [], 'no endstream');
                }
                $data = substr($this->bytes, $start, $end - $start);
                if (str_ends_with($data, "\r\n")) {
                    $data = substr($data, 0, -2);
                } elseif (str_ends_with($data, "\n") || str_ends_with($data, "\r")) {
                    $data = substr($data, 0, -1);
                }
            }
            return [(int) $num, (int) $gen, new Stream($v, $data)];
        }
        return [(int) $num, (int) $gen, $v];
    }

    private function resolveLength(int $num): ?int
    {
        $e = $this->xref[$num] ?? null;
        if ($e === null || isset($this->loading[$num])) {
            return null;
        }
        $v = $this->object($num);
        return is_int($v) ? $v : null;
    }

    private function fromObjectStream(int $stmNum, int $num): mixed
    {
        if (!isset($this->objStreams[$stmNum])) {
            $stm = $this->object($stmNum);
            if (!$stm instanceof Stream) {
                return null;
            }
            $this->objStreamIndex($stmNum, $stm);
        }
        $off = $this->objStreams[$stmNum][$num] ?? null;
        if ($off === null) {
            return null;
        }
        $p = new Parser($this->objStreamData[$stmNum]);
        $p->pos = $off;
        return $p->value();
    }

    /** @return array<int, int> */
    private function objStreamIndex(int $stmNum, Stream $stm): array
    {
        if (isset($this->objStreams[$stmNum])) {
            return $this->objStreams[$stmNum];
        }
        $data = $this->streamData($stm);
        $n = (int) $stm->dict->get('N');
        $first = (int) $stm->dict->get('First');
        $p = new Parser($data);
        $map = [];
        for ($i = 0; $i < $n && $i < 100000; $i++) {
            $a = $p->token();
            $b = $p->token();
            if (!ctype_digit($a) || !ctype_digit($b)) {
                break;
            }
            $map[(int) $a] = $first + (int) $b;
        }
        $this->objStreamData[$stmNum] = $data;
        return $this->objStreams[$stmNum] = $map;
    }

    private function decrypt(mixed $v, int $num, int $gen): mixed
    {
        $sec = $this->security;
        if ($sec === null) {
            return $v;
        }
        if ($v instanceof Str) {
            return new Str($sec->decryptString($num, $gen, $v->bytes), $v->hex);
        }
        if (is_array($v)) {
            return array_map(fn (mixed $x): mixed => $this->decrypt($x, $num, $gen), $v);
        }
        if ($v instanceof Dict) {
            $d = new Dict();
            foreach ($v->items as $k => $x) {
                $d->set($k, $this->decrypt($x, $num, $gen));
            }
            return $d;
        }
        if ($v instanceof Stream) {
            $dict = $this->decrypt($v->dict, $num, $gen);
            $type = $v->dict->name('Type');
            if ($type === 'XRef' || ($type === 'Metadata' && !$sec->encryptMetadata) || $this->identityCrypt($v->dict)) {
                return new Stream($dict, $v->data);
            }
            return new Stream($dict, $sec->decryptStream($num, $gen, $v->data));
        }
        return $v;
    }

    private function identityCrypt(Dict $d): bool
    {
        $f = $d->get('Filter');
        $first = is_array($f) ? ($f[0] ?? null) : $f;
        return $first instanceof Name && $first->name === 'Crypt';
    }

    private function isJs(mixed $action): bool
    {
        $a = $this->resolve($action);
        return $a instanceof Dict && in_array($a->name('S'), ['JavaScript', 'Launch'], true);
    }
}
