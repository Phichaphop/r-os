<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Support\FrozenClock;
use Blue\Core\Support\Ulid;

final class UlidTest extends TestCase
{
    public function testFormatIsCrockfordBase32With26Chars(): void
    {
        $u = Ulid::generate();
        self::assertSame(26, strlen($u));
        self::assertTrue(Ulid::isValid($u));
        self::assertFalse(Ulid::isValid('not-a-ulid'));
        self::assertFalse(Ulid::isValid(str_repeat('I', 26)), 'I/L/O/U are not in the alphabet');
    }

    public function testEncodesTheClockTime(): void
    {
        $clock = new FrozenClock('2026-11-02T01:02:03.456Z');
        $u = Ulid::generate($clock);
        self::assertSame((int) $clock->now()->format('Uv'), Ulid::timestampMs($u));
    }

    public function testMonotonicWithinTheSameMillisecond(): void
    {
        $clock = new FrozenClock('2026-11-02T00:00:00Z');
        $ids = [];
        for ($i = 0; $i < 500; $i++) {
            $ids[] = Ulid::generate($clock);
        }
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids, 'IDs must sort in creation order');
        self::assertCount(500, array_unique($ids));
    }
}
