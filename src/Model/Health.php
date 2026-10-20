<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;

/** `GET /v1/health`: public service status. */
final class Health
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $status,
        public readonly int $time,
        public readonly string $version,
        public readonly array $raw = [],
    ) {
    }

    /** @internal */
    public static function fromApi(mixed $data): self
    {
        $d = S::obj($data, '$');

        return new self(S::reqStr($d, 'status', '$'), S::reqInt($d, 'time', '$'), S::reqStr($d, 'version', '$'), S::toArray($d));
    }
}
