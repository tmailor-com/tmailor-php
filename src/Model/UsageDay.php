<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;

/** Daily counters of `GET /v1/usage`. */
final class UsageDay
{
    public function __construct(
        public readonly string $date,
        public readonly int $reqTotal,
        public readonly int $req4xx,
        public readonly int $req5xx,
        public readonly int $req429,
        public readonly int $reqIpDenied,
        public readonly int $mailboxCreated,
        public readonly int $msgList,
        public readonly int $msgRead,
    ) {
    }

    /** @internal */
    public static function fromApi(mixed $data, string $path = '$.days[]'): self
    {
        $d = S::obj($data, $path);

        return new self(
            S::reqStr($d, 'date', $path),
            S::reqInt($d, 'req_total', $path),
            S::reqInt($d, 'req_4xx', $path),
            S::reqInt($d, 'req_5xx', $path),
            S::reqInt($d, 'req_429', $path),
            S::reqInt($d, 'req_ip_denied', $path),
            S::reqInt($d, 'mailbox_created', $path),
            S::reqInt($d, 'msg_list', $path),
            S::reqInt($d, 'msg_read', $path),
        );
    }
}
