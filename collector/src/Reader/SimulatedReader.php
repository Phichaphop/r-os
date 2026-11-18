<?php

declare(strict_types=1);

namespace BlueCollector\Reader;

/**
 * Test punches for one or more days (phase 4B — the real HIP adapter waits for BT-02). For every user code and day:
 * an "in" around a start time and an "out" around an end time, with a few minutes of jitter. Options per user:
 *   late    — minutes late in the morning
 *   miss    — "in" or "out": that punch is not produced (forgot to scan)
 *   absent  — no punch that day
 *   double  — the morning punch is scanned twice (one minute apart) — Blue OS must keep the first one
 * A seed makes the output repeatable (tests).
 */
final class SimulatedReader implements ReaderAdapter
{
    /**
     * @param list<string> $dates Y-m-d (device local dates)
     * @param list<string> $users device user codes
     * @param array<string, array{late?: int, miss?: string, absent?: bool, double?: bool}> $options per user code
     */
    public function __construct(
        private readonly array $dates,
        private readonly array $users,
        private readonly array $options = [],
        private readonly string $start = '08:00',
        private readonly string $end = '17:00',
        private readonly int $seed = 0,
        private readonly bool $weekdaysOnly = true,
    ) {
    }

    public function read(): iterable
    {
        mt_srand($this->seed !== 0 ? $this->seed : random_int(1, PHP_INT_MAX));
        $methods = ['finger', 'finger', 'finger', 'face', 'card'];
        foreach ($this->dates as $date) {
            $dow = (int) (new \DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('UTC')))->format('N');
            if ($this->weekdaysOnly && $dow >= 6) {
                continue;
            }
            foreach ($this->users as $u) {
                $o = $this->options[$u] ?? [];
                if (!empty($o['absent'])) {
                    continue;
                }
                $verify = $methods[mt_rand(0, count($methods) - 1)];
                if (($o['miss'] ?? '') !== 'in') {
                    $in = self::at($date, $this->start, -mt_rand(3, 20) + (int) ($o['late'] ?? 0), mt_rand(0, 59));
                    yield ['user_code' => $u, 'local' => $in, 'verify' => $verify];
                    if (!empty($o['double'])) {
                        yield ['user_code' => $u, 'local' => self::plus($in, 60), 'verify' => $verify];
                    }
                }
                if (($o['miss'] ?? '') !== 'out') {
                    yield ['user_code' => $u, 'local' => self::at($date, $this->end, mt_rand(1, 25), mt_rand(0, 59)), 'verify' => $verify];
                }
            }
        }
    }

    private static function at(string $date, string $hm, int $minutes, int $seconds): string
    {
        $t = new \DateTimeImmutable($date . ' ' . $hm . ':00', new \DateTimeZone('UTC'));
        return $t->modify(($minutes >= 0 ? '+' : '') . $minutes . ' minutes')->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }

    private static function plus(string $local, int $seconds): string
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('UTC')))->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }
}
