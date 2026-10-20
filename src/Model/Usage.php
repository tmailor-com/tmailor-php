<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;

/** `GET /v1/usage`: daily counters (Asia/Ho_Chi_Minh days). */
final class Usage
{
    /**
     * @param list<UsageDay>       $days
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $timezone,
        public readonly array $days,
        public readonly array $raw = [],
    ) {
    }

    /** @internal */
    public static function fromApi(mixed $data): self
    {
        $d = S::obj($data, '$');
        $days = [];
        foreach (S::reqList($d, 'days', '$') as $i => $x) {
            $days[] = UsageDay::fromApi($x, '$.days[' . $i . ']');
        }

        return new self(S::reqStr($d, 'from', '$'), S::reqStr($d, 'to', '$'), S::reqStr($d, 'timezone', '$'), $days, S::toArray($d));
    }
}
