<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;

/** One plan of the account. */
final class Subscription
{
    public function __construct(
        public readonly string $plan,
        public readonly int $expiresAt,
        public readonly int $periodEnd,
        public readonly int $quota,
        public readonly int $used,
    ) {
    }

    /** @internal */
    public static function fromApi(mixed $data, string $path = '$.subscriptions[]'): self
    {
        $d = S::obj($data, $path);

        return new self(
            S::reqStr($d, 'plan', $path),
            S::reqInt($d, 'expires_at', $path),
            S::reqInt($d, 'period_end', $path),
            S::reqInt($d, 'quota', $path),
            S::reqInt($d, 'used', $path),
        );
    }
}
