<?php

declare(strict_types=1);

namespace BlueCollector\Reader;

/**
 * Reads punches from a time clock (or from the data of the vendor program) and hands them to the Collector.
 * The real HIP adapter is written when the HIP program name and its data format are known (DEC-43, BT-02);
 * until then SimulatedReader produces test punches.
 *
 * A reader returns ONLY: device user code, local time on the device, verify method, and the device's own
 * sequence number when it has one. It must never return fingerprint/face templates, photos or ID numbers (DEC-25).
 */
interface ReaderAdapter
{
    /** @return iterable<array{user_code: string, local: string, verify: string, seq?: int}> */
    public function read(): iterable;
}
