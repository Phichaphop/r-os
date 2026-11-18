<?php

declare(strict_types=1);

namespace Blue\Modules\Rooms;

use Blue\Core\Error\BusinessException;

/** A booking that cannot be made, with the items to list next to a field (dates that clash, names not found). */
final class BookingProblem extends BusinessException
{
    /** @param list<string> $items */
    public function __construct(public readonly string $field, string $key, public readonly array $items)
    {
        parent::__construct($key, [], 422);
    }
}
