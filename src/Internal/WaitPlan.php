<?php

declare(strict_types=1);

namespace Tmailor\Internal;

use Tmailor\RateLimitInfo;

/**
 * Decides how long `waitForMessage` sleeps between two polls.
 *
 * - Without `pollInterval` the delay starts at 1 s and grows by 1.5x up to 10 s.
 * - With `pollInterval` the delay is fixed (at least 0.5 s).
 * - Rate limits: when X-RateLimit-Remaining is at or below 2 the poll waits for X-RateLimit-Reset; when it is low
 *   it spreads the remaining requests until the reset.
 * - Never sleeps past the deadline: the last poll starts at least LAST_POLL_RESERVE (1 s) before it, and every
 *   request is capped by the time left, so the whole wait ends by the deadline.
 *
 * @internal
 */
final class WaitPlan
{
    public const FIRST_POLL = 1.0;
    public const GROWTH = 1.5;
    public const MAX_POLL = 10.0;
    public const MIN_POLL = 0.5;
    /** Keep this many requests of the current minute for the rest of your program. */
    public const RESERVE = 2;
    public const LAST_POLL_RESERVE = 1.0;

    public readonly float $deadline;
    private readonly bool $fixed;
    private float $interval;

    public function __construct(float $timeout, ?float $pollInterval, float $now)
    {
        if (is_nan($timeout) || $timeout < 0) {
            throw new \InvalidArgumentException('timeout must be >= 0');
        }
        if ($pollInterval !== null && (is_nan($pollInterval) || $pollInterval <= 0)) {
            throw new \InvalidArgumentException('pollInterval must be > 0');
        }
        $this->deadline = $now + $timeout;
        $this->fixed = $pollInterval !== null;
        $this->interval = $pollInterval !== null ? max(self::MIN_POLL, $pollInterval) : self::FIRST_POLL;
    }

    public function remaining(float $now): float
    {
        return $this->deadline - $now;
    }

    /** Seconds to sleep before the next poll, or null when less than LAST_POLL_RESERVE is left. */
    public function nextDelay(float $now, float $wallNow, ?RateLimitInfo $rl): ?float
    {
        $left = $this->deadline - $now - self::LAST_POLL_RESERVE;
        if ($left <= 0) {
            return null;
        }
        $delay = $this->interval;
        if (!$this->fixed) {
            $this->interval = min($this->interval * self::GROWTH, self::MAX_POLL);
        }
        if ($rl !== null && $rl->remaining !== null && $rl->reset !== null) {
            $untilReset = max(0.0, $rl->reset - $wallNow) + 0.5;
            if ($rl->remaining <= self::RESERVE) {
                $delay = max($delay, min($untilReset, 61.0));
            } elseif ($rl->limit !== null && $rl->limit !== 0 && $rl->remaining < max(5, intdiv($rl->limit, 5))) {
                $delay = max($delay, min($untilReset / max(1, $rl->remaining - self::RESERVE), 61.0));
            }
        }

        return min($delay, $left);
    }

    /** Sleep after a 429 inside the wait loop, or null when it does not fit before the deadline. */
    public function rateLimitDelay(float $now, ?int $retryAfter): ?float
    {
        $left = $this->deadline - $now - self::LAST_POLL_RESERVE;
        $wait = $retryAfter !== null ? max((float) $retryAfter, Core::MIN_RETRY_AFTER) : $this->interval;
        if ($wait > $left) {
            return null;
        }

        return $wait;
    }
}
