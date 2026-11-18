<?php

declare(strict_types=1);

namespace Blue\Core\Backup;

use RuntimeException;

/** The backup was made with a different APP_KEY (fingerprint shown — never the key itself). */
final class KeyMismatch extends RuntimeException
{
    public function __construct(public readonly string $fileFingerprint)
    {
        parent::__construct('This backup was encrypted with another key (fingerprint ' . $fileFingerprint . ')');
    }
}
