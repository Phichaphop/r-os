<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * A plain A4 text document built from scratch (phase 6B, DEC-84) — titles, lines, label/value pairs, simple tables
 * with left/right aligned columns, rules — in the bundled Thai font (IBM Plex Sans Thai, OFL). Used for payslips;
 * no images, no forms. A character the font cannot draw is replaced by "?" (never a missing glyph).
 */
final class SimpleDocument
{
    private const W = 595.28;
    private const H = 841.89;
    private const M = 46.0;

    private TrueTypeFont $font;
    /** @var list<string> content streams, one per page */
    private array $pages = [''];
    private float $y;

    public function __construct(private readonly string $footer = '')
    {
        $this->font = TrueTypeFont::thai();
        $this->y = self::H - self::M;
    }

    private function clean(string $s): string
    {
        return implode('', array_map(fn (string $ch): string => $this->font->supports($ch) ? $ch : '?', mb_str_split(str_replace(["\r", "\n", "\t"], ' ', $s))));
    }

    private function need(float $h): void
    {
        if ($this->y - $h < self::M + 20) {
            $this->pages[] = '';
            $this->y = self::H - self::M;
        }
    }

    private function put(string $text, float $x, float $size, string $color = '0.1 0.1 0.15', string $align = 'left', ?float $right = null): void
    {
        $t = $this->clean($text);
        if ($t === '') {
            return;
        }
        if ($align === 'right' && $right !== null) {
            $x = $right - $this->font->width($t, $size);
        }
        $this->pages[count($this->pages) - 1] .= sprintf('BT /F1 %s Tf %s rg %s %s Td %s TJ ET ', Toolkit::n($size), $color, Toolkit::n($x), Toolkit::n($this->y), $this->font->encode($t));
    }

    /** big title (+ smaller second line) */
    public function title(string $title, string $sub = ''): self
    {
        $this->need(40);
        $this->y -= 16;
        $this->put($title, self::M, 16, '0.05 0.1 0.25');
        if ($sub !== '') {
            $this->y -= 16;
            $this->put($sub, self::M, 9.5, '0.3 0.32 0.4');
        }
        $this->y -= 12;
        return $this;
    }

    public function heading(string $text): self
    {
        $this->need(28);
        $this->y -= 16;
        $this->put($text, self::M, 11, '0.05 0.1 0.25');
        $this->y -= 6;
        return $this;
    }

    /** a line of text, wrapped to the page width */
    public function text(string $text, float $size = 9.5, string $color = '0.1 0.1 0.15'): self
    {
        $cur = '';
        $lines = [];
        foreach (preg_split('/(?<=\s)/u', $this->clean($text)) ?: [] as $word) {
            if ($cur !== '' && $this->font->width($cur . $word, $size) > self::W - 2 * self::M) {
                $lines[] = rtrim($cur);
                $cur = '';
            }
            $cur .= $word;
        }
        $lines[] = rtrim($cur);
        foreach ($lines as $l) {
            $this->need($size + 5);
            $this->y -= $size + 4;
            $this->put($l, self::M, $size, $color);
        }
        return $this;
    }

    /**
     * label / value pairs in two columns per row
     *
     * @param list<array{0: string, 1: string}> $pairs
     */
    public function pairs(array $pairs): self
    {
        $half = (self::W - 2 * self::M) / 2;
        foreach (array_chunk($pairs, 2) as $row) {
            $this->need(16);
            $this->y -= 14;
            foreach ($row as $i => [$k, $v]) {
                $x = self::M + $i * $half;
                $this->put($k, $x, 8.5, '0.35 0.37 0.45');
                $this->put($v, $x + 92, 9.5);
            }
        }
        $this->y -= 4;
        return $this;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<float> $widths fractions of the text width
     * @param list<string> $align 'left' | 'right' per column
     * @param list<string> $total a last row in bold-ish colour (optional)
     */
    public function table(array $headers, array $rows, array $widths, array $align, array $total = []): self
    {
        $full = self::W - 2 * self::M;
        $xs = [];
        $x = self::M;
        foreach ($widths as $w) {
            $xs[] = [$x, $x + $w * $full];
            $x += $w * $full;
        }
        $line = function (array $cells, float $size, string $color) use ($xs, $align): void {
            $this->need($size + 8);
            $this->y -= $size + 6;
            foreach ($cells as $i => $c) {
                [$x0, $x1] = $xs[$i];
                ($align[$i] ?? 'left') === 'right' ? $this->put((string) $c, $x0, $size, $color, 'right', $x1 - 4) : $this->put((string) $c, $x0 + 2, $size, $color);
            }
        };
        $line($headers, 8.5, '0.35 0.37 0.45');
        $this->rule();
        foreach ($rows as $r) {
            $line($r, 9.5, '0.1 0.1 0.15');
        }
        if ($total !== []) {
            $this->rule();
            $line($total, 10, '0.05 0.1 0.25');
        }
        $this->y -= 6;
        return $this;
    }

    /** one emphasized line: label on the left, value on the right, ruled above */
    public function total(string $label, string $value): self
    {
        $this->need(26);
        $this->rule();
        $this->y -= 16;
        $this->put($label, self::M + 2, 11, '0.05 0.1 0.25');
        $this->put($value, self::M, 11, '0.05 0.1 0.25', 'right', self::W - self::M - 4);
        $this->y -= 8;
        return $this;
    }

    public function rule(): self
    {
        $this->y -= 4;
        $this->pages[count($this->pages) - 1] .= sprintf('q 0.75 0.78 0.84 RG 0.6 w %s %s m %s %s l S Q ', Toolkit::n(self::M), Toolkit::n($this->y), Toolkit::n(self::W - self::M), Toolkit::n($this->y));
        return $this;
    }

    public function space(float $h = 8): self
    {
        $this->y -= $h;
        return $this;
    }

    public function output(): string
    {
        $w = new Writer();
        $fontRef = $this->font->ref($w);
        $n = count($this->pages);
        foreach ($this->pages as $i => $ops) {
            $foot = trim($this->footer . ($n > 1 ? '  ·  ' . ($i + 1) . '/' . $n : ''));
            if ($foot !== '') {
                $ops .= sprintf('BT /F1 7.5 Tf 0.45 0.47 0.55 rg %s %s Td %s TJ ET ', Toolkit::n(self::M), Toolkit::n(self::M - 18), $this->font->encode($this->clean($foot)));
            }
            $c = $w->add(new Stream(new Dict(['Filter' => new Name('FlateDecode')]), Filters::deflate($ops)));
            $w->addPage(new Dict(['Type' => new Name('Page'), 'MediaBox' => [0, 0, self::W, self::H], 'Resources' => new Dict(['Font' => new Dict(['F1' => $fontRef])]), 'Contents' => $c]));
        }
        $this->font->finish($w);
        return $w->output();
    }
}
