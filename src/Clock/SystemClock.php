<?php

declare(strict_types=1);

namespace Tmailor\Clock;

/** The real clock: hrtime() for the monotonic clock, microtime() for the wall clock, usleep() to sleep. */
final class SystemClock implements ClockInterface
{
    public function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }

    public function wall(): float
    {
        return microtime(true);
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1e6));
        }
    }
}
