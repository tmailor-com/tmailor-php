<?php

declare(strict_types=1);

namespace Tmailor\Tests\Support;

use Tmailor\Clock\ClockInterface;

/** Every sleep advances the monotonic clock; wall time = wallStart + monotonic time. */
final class FakeClock implements ClockInterface
{
    public float $now = 0.0;
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(public float $wallStart = 1760342400.0)
    {
    }

    public function monotonic(): float
    {
        return $this->now;
    }

    public function wall(): float
    {
        return $this->wallStart + $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->now += $seconds;
    }
}
