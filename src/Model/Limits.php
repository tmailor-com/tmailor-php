<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;

/** Quota and rate limits of the account (`GET /v1/me`). */
final class Limits
{
    public function __construct(
        public readonly int $quotaTotal,
        public readonly int $quotaUsed,
        public readonly int $quotaRemaining,
        public readonly int $mailboxCount,
        public readonly int $ratePerMin,
        public readonly int $dailyReqCap,
        public readonly int $maxIp,
    ) {
    }

    /** @internal */
    public static function fromApi(mixed $data, string $path = '$.limits'): self
    {
        $d = S::obj($data, $path);

        return new self(
            S::reqInt($d, 'quota_total', $path),
            S::reqInt($d, 'quota_used', $path),
            S::reqInt($d, 'quota_remaining', $path),
            S::reqInt($d, 'mailbox_count', $path),
            S::reqInt($d, 'rate_per_min', $path),
            S::reqInt($d, 'daily_req_cap', $path),
            S::reqInt($d, 'max_ip', $path),
        );
    }
}
