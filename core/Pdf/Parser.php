<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * Tokenizer + object parser for PDF syntax (ISO 32000-1 §7.2–7.3). Works on the whole file in memory; nesting is
 * limited so a hostile file cannot exhaust the stack.
 */
final class Parser
{
    private const WS = " \t\n\r\f\0";
    private const DELIM = "()<>[]{}/%";
    private const MAX_DEPTH = 64;

    public int $pos = 0;

    public function __construct(public readonly string $s)
    {
    }

    public function skipWs(): void
    {
        $len = strlen($this->s);
        while ($this->pos < $len) {
            $c = $this->s[$this->pos];
            if (str_contains(self::WS, $c)) {
                $this->pos++;
            } elseif ($c === '%') {
                $end = strcspn($this->s, "\r\n", $this->pos);
                $this->pos += $end;
            } else {
                break;
            }
        }
    }

    /** Next regular token (keyword or number) without consuming it as an object. */
    public function token(): string
    {
        $this->skipWs();
        $n = strcspn($this->s, self::WS . self::DELIM, $this->pos);
        $t = substr($this->s, $this->pos, $n);
        $this->pos += $n;
        return $t;
    }

    public function value(int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new PdfError('pdf.error.corrupt', [], 'nesting too deep');
        }
        $this->skipWs();
        $c = $this->s[$this->pos] ?? '';
        if ($c === '') {
            throw new PdfError('pdf.error.corrupt', [], 'unexpected end');
        }
        if ($c === '/') {
            return $this->name();
        }
        if ($c === '(') {
            return $this->literal();
        }
        if ($c === '<') {
            if (($this->s[$this->pos + 1] ?? '') === '<') {
                return $this->dict($depth);
            }
            return $this->hex();
        }
        if ($c === '[') {
            $this->pos++;
            $out = [];
            while (true) {
                $this->skipWs();
                if (($this->s[$this->pos] ?? '') === ']') {
                    $this->pos++;
                    return $out;
                }
                if ($this->pos >= strlen($this->s)) {
                    throw new PdfError('pdf.error.corrupt', [], 'unterminated array');
                }
                $out[] = $this->value($depth + 1);
            }
        }
        if ($c === ']' || $c === '>' || $c === ')' || $c === '{' || $c === '}') {
            $this->pos++;
            throw new PdfError('pdf.error.corrupt', [], 'unexpected ' . $c);
        }
        $start = $this->pos;
        $t = $this->token();
        if ($t === 'true') {
            return true;
        }
        if ($t === 'false') {
            return false;
        }
        if ($t === 'null') {
            return null;
        }
        if (preg_match('/^[+-]?\d+$/', $t) === 1) {
            // "num gen R" ?
            $save = $this->pos;
            $gen = $this->token();
            if (preg_match('/^\d+$/', $gen) === 1) {
                $r = $this->token();
                if ($r === 'R') {
                    return new Ref((int) $t, (int) $gen);
                }
            }
            $this->pos = $save;
            return (int) $t;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)$/', $t) === 1) {
            return (float) $t;
        }
        if ($t === '') {
            $this->pos = $start + 1;
            throw new PdfError('pdf.error.corrupt', [], 'bad token');
        }
        return new Keyword($t);
    }

    private function name(): Name
    {
        $this->pos++;
        $n = strcspn($this->s, self::WS . self::DELIM, $this->pos);
        $raw = substr($this->s, $this->pos, $n);
        $this->pos += $n;
        $name = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $raw);
        return new Name($name);
    }

    private function literal(): Str
    {
        $this->pos++;
        $out = '';
        $depth = 1;
        $len = strlen($this->s);
        while ($this->pos < $len) {
            $c = $this->s[$this->pos++];
            if ($c === '\\') {
                $n = $this->s[$this->pos++] ?? '';
                switch ($n) {
                    case 'n':
                        $out .= "\n";
                        break;
                    case 'r':
                        $out .= "\r";
                        break;
                    case 't':
                        $out .= "\t";
                        break;
                    case 'b':
                        $out .= "\x08";
                        break;
                    case 'f':
                        $out .= "\f";
                        break;
                    case "\r":
                        if (($this->s[$this->pos] ?? '') === "\n") {
                            $this->pos++;
                        }
                        break;
                    case "\n":
                        break;
                    default:
                        if ($n >= '0' && $n <= '7') {
                            $oct = $n;
                            for ($i = 0; $i < 2 && ($this->s[$this->pos] ?? '') >= '0' && ($this->s[$this->pos] ?? '') <= '7'; $i++) {
                                $oct .= $this->s[$this->pos++];
                            }
                            $out .= chr(octdec($oct) & 0xFF);
                        } else {
                            $out .= $n;
                        }
                }
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    return new Str($out);
                }
            }
            $out .= $c;
        }
        throw new PdfError('pdf.error.corrupt', [], 'unterminated string');
    }

    private function hex(): Str
    {
        $this->pos++;
        $end = strpos($this->s, '>', $this->pos);
        if ($end === false) {
            throw new PdfError('pdf.error.corrupt', [], 'unterminated hex string');
        }
        $h = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($this->s, $this->pos, $end - $this->pos));
        $this->pos = $end + 1;
        if (strlen($h) % 2 === 1) {
            $h .= '0';
        }
        return new Str((string) hex2bin($h), true);
    }

    private function dict(int $depth): Dict
    {
        $this->pos += 2;
        $d = new Dict();
        while (true) {
            $this->skipWs();
            if (substr($this->s, $this->pos, 2) === '>>') {
                $this->pos += 2;
                return $d;
            }
            if ($this->pos >= strlen($this->s)) {
                throw new PdfError('pdf.error.corrupt', [], 'unterminated dictionary');
            }
            $k = $this->value($depth + 1);
            if (!$k instanceof Name) {
                throw new PdfError('pdf.error.corrupt', [], 'dictionary key is not a name');
            }
            $this->skipWs();
            if (substr($this->s, $this->pos, 2) === '>>') { // key without value: tolerate
                $d->set($k->name, null);
                continue;
            }
            $d->set($k->name, $this->value($depth + 1));
        }
    }
}
