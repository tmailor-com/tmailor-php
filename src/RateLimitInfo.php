<?php

declare(strict_types=1);

namespace Tmailor;

/** Values of the X-RateLimit-* headers of the last response that had them (`reset` is a unix time). */
final class RateLimitInfo
{
    public function __construct(
        public readonly ?int $limit,
        public readonly ?int $remaining,
        public readonly ?int $reset,
    ) {
    }
}
