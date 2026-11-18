<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/** Stream object: dictionary + data as stored in the file (still filtered/encoded; already decrypted). */
final class Stream
{
    public function __construct(public Dict $dict, public string $data)
    {
    }
}
