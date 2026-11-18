<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/** PDF string (bytes); $hex only affects how the writer prints it. */
final class Str
{
    public function __construct(public string $bytes, public bool $hex = false)
    {
    }

    /** Text string → UTF-8 (UTF-16BE with BOM, UTF-8 with BOM, else PDFDocEncoding ≈ Latin-1). */
    public function text(): string
    {
        $b = $this->bytes;
        if (str_starts_with($b, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($b, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($b, "\xEF\xBB\xBF")) {
            return substr($b, 3);
        }
        return (string) mb_convert_encoding($b, 'UTF-8', 'ISO-8859-1');
    }

    /** UTF-8 → PDF text string (UTF-16BE with BOM when not plain ASCII). */
    public static function fromText(string $utf8): self
    {
        if (preg_match('/^[\x20-\x7E]*$/', $utf8) === 1) {
            return new self($utf8);
        }
        return new self("\xFE\xFF" . mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'), true);
    }
}
