<?php

declare(strict_types=1);

namespace Tmailor\Clock;

/**
 * Time source of the client: a monotonic clock (retry and wait deadlines), the wall clock (X-RateLimit-Reset is a
 * unix time) and sleep. Replace it in tests to run retries and waits without real time passing.
 */
interface ClockInterface
{
    /** Seconds from an arbitrary origin, never going backwards. */
    public function monotonic(): float;

    /** Unix time in seconds. */
    public function wall(): float;

    public function sleep(float $seconds): void;
}
