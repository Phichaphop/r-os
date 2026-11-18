<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/** A PDF the engine cannot (or will not) process. $messageKey is a translation key shown to the person. */
final class PdfError extends \RuntimeException
{
    /** @param array<string, string> $params */
    public function __construct(public readonly string $messageKey, public readonly array $params = [], string $detail = '')
    {
        parent::__construct($messageKey . ($detail !== '' ? ': ' . $detail : ''));
    }
}
