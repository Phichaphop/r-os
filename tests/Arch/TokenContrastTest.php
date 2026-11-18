<?php

declare(strict_types=1);

namespace Blue\Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * WCAG 2.2 AA contrast of the shipped tokens (P0-05 §1.2, §7) — light AND dark.
 * Resolves semantic → primitive values straight from the CSS files, so a token edit that
 * breaks contrast fails CI.
 */
final class TokenContrastTest extends TestCase
{
    private const DIR = __DIR__ . '/../../ui/tokens';

    /** [foreground, background, minimum] */
    private const PAIRS = [
        ['text', 'surface', 4.5], ['text', 'bg', 4.5], ['text-muted', 'surface', 4.5], ['text-muted', 'bg', 4.5],
        ['action-primary', 'surface', 4.5], ['on-primary', 'action-primary', 4.5], ['action-primary', 'primary-soft', 4.5],
        ['success', 'surface', 4.5], ['warning', 'surface', 4.5], ['danger', 'surface', 4.5], ['info', 'surface', 4.5],
        ['success', 'success-subtle', 4.5], ['warning', 'warning-subtle', 4.5], ['danger', 'danger-subtle', 4.5], ['info', 'info-subtle', 4.5],
        ['text', 'neutral-subtle', 4.5], ['text', 'warning-subtle', 4.5], ['text', 'danger-subtle', 4.5],
        ['on-navy', 'navy', 4.5], ['navy', 'navy-accent', 4.5], ['on-primary', 'danger', 4.5],
        ['border-input', 'surface', 3.0], ['focus-ring', 'surface', 3.0],
    ];

    /** @return array<string, array{0:string}> */
    public static function themes(): array
    {
        return ['light' => ['semantic-light.css'], 'dark' => ['semantic-dark.css']];
    }

    /** @dataProvider themes */
    public function testContrastPairs(string $file): void
    {
        $prim = $this->vars((string) file_get_contents(self::DIR . '/primitives.css'));
        $sem = $this->vars((string) file_get_contents(self::DIR . '/' . $file));
        $fails = [];
        foreach (self::PAIRS as [$fg, $bg, $min]) {
            $ratio = $this->ratio($this->resolve("--color-{$fg}", $sem, $prim), $this->resolve("--color-{$bg}", $sem, $prim));
            if ($ratio < $min) {
                $fails[] = sprintf('%s on %s = %.2f (< %.1f)', $fg, $bg, $ratio, $min);
            }
        }
        self::assertSame([], $fails);
    }

    /** @return array<string, string> */
    private function vars(string $css): array
    {
        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $css, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $out[$x[1]] = trim($x[2]);
        }
        return $out;
    }

    /** @param array<string,string> $sem @param array<string,string> $prim */
    private function resolve(string $name, array $sem, array $prim): string
    {
        $v = $sem[$name] ?? $prim[$name] ?? null;
        self::assertNotNull($v, "unknown token {$name}");
        if (preg_match('/^var\((--[a-z0-9-]+)\)$/', $v, $m)) {
            return $this->resolve($m[1], $sem, $prim);
        }
        self::assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $v, "{$name} must resolve to an opaque hex colour");
        return $v;
    }

    private function ratio(string $a, string $b): float
    {
        $la = $this->lum($a);
        $lb = $this->lum($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function lum(string $hex): float
    {
        $c = array_map(static function (string $h): float {
            $v = hexdec($h) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
