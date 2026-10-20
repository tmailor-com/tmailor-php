<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;

/** One of your own domains. Shared domains are never listed. */
final class Domain
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $domain,
        public readonly bool $isUserDomain,
        public readonly bool $allowCustomName,
        public readonly string $status,
        public readonly bool $verified,
        public readonly bool $usable,
        public readonly array $raw = [],
    ) {
    }

    /** @internal */
    public static function fromApi(mixed $data, string $path = '$'): self
    {
        $d = S::obj($data, $path);

        return new self(
            S::reqStr($d, 'domain', $path),
            S::reqBool($d, 'is_user_domain', $path),
            S::reqBool($d, 'allow_custom_name', $path),
            S::reqStr($d, 'status', $path),
            S::reqBool($d, 'verified', $path),
            S::reqBool($d, 'usable', $path),
            S::toArray($d),
        );
    }
}
