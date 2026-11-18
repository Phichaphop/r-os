<?php

declare(strict_types=1);

namespace Blue\Core\Ui;

/**
 * System fonts people can choose (0.6.1). All are self-hosted in ui/fonts (SIL OFL 1.1) — no font CDN (P0-02 §11).
 * "plex" is the design-system default: choosing it adds no CSS. An organization may also import its own font from a
 * link (FontImporter); that one is "custom".
 */
final class FontCatalog
{
    /** key => [CSS family, label] */
    public const FONTS = [
        'plex' => ['IBM Plex Sans Thai', 'IBM Plex Sans Thai'],
        'sarabun' => ['Sarabun', 'Sarabun (สารบรรณ)'],
        'noto_sans_thai' => ['Noto Sans Thai', 'Noto Sans Thai'],
        'prompt' => ['Prompt', 'Prompt'],
        'kanit' => ['Kanit', 'Kanit'],
        'anuphan' => ['Anuphan', 'Anuphan'],
        'chakra_petch' => ['Chakra Petch', 'Chakra Petch'],
        'bai_jamjuree' => ['Bai Jamjuree', 'Bai Jamjuree'],
        'system' => ['', 'System'],
    ];

    private const FALLBACK = "system-ui, -apple-system, 'Segoe UI', 'Leelawadee UI', 'Thonburi', sans-serif";

    public static function valid(string $key): bool
    {
        return isset(self::FONTS[$key]);
    }

    /** CSS font-family value for a catalog key, or for a custom family name. */
    public static function stack(string $key, ?string $customFamily = null): string
    {
        if ($key === 'custom' && $customFamily !== null && $customFamily !== '') {
            return self::quote($customFamily) . ", 'IBM Plex Sans Thai', " . self::FALLBACK;
        }
        $family = self::FONTS[$key][0] ?? 'IBM Plex Sans Thai';
        return $family === '' ? self::FALLBACK : self::quote($family) . ', ' . self::FALLBACK;
    }

    /**
     * Token overrides for a chosen font (body text and headings use the same family). '' for the default.
     */
    public static function css(string $key, ?string $customFamily = null): string
    {
        if ($key === 'plex' || (!self::valid($key) && $key !== 'custom') || ($key === 'custom' && ($customFamily ?? '') === '')) {
            return '';
        }
        $stack = self::stack($key, $customFamily);
        return ":root {\n  --font-sans: {$stack};\n  --font-display: {$stack};\n}\n";
    }

    /** A family name we can safely put in CSS: letters, digits, spaces, - and _. */
    public static function cleanFamily(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', $name)));
    }

    private static function quote(string $family): string
    {
        return "'" . self::cleanFamily($family) . "'";
    }
}
