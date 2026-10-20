<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;

/** `GET /v1/me`: your account, limits and plans. */
final class Me
{
    /**
     * @param list<string>         $ipAllowlist
     * @param list<Subscription>   $subscriptions
     * @param bool                 $ipAllowlistEnforced Review 20b PHP-05 (spec 1.3.0): false when a paid plan lets
     *                                                  this key call from any IP (the allowlist is not checked)
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $userId,
        public readonly KeyInfo $key,
        public readonly string $clientIp,
        public readonly Limits $limits,
        public readonly array $ipAllowlist,
        public readonly array $subscriptions,
        public readonly bool $ipAllowlistEnforced,
        public readonly array $raw = [],
    ) {
    }

    /** @internal */
    public static function fromApi(mixed $data): self
    {
        $d = S::obj($data, '$');
        $k = S::reqObj($d, 'key', '$');
        $subs = [];
        foreach (S::reqList($d, 'subscriptions', '$') as $i => $s) {
            $subs[] = Subscription::fromApi($s, '$.subscriptions[' . $i . ']');
        }

        return new self(
            S::reqInt($d, 'user_id', '$'),
            new KeyInfo(S::reqInt($k, 'key_id', '$.key'), S::reqStr($k, 'prefix', '$.key')),
            S::reqStr($d, 'client_ip', '$'),
            Limits::fromApi(S::reqObj($d, 'limits', '$')),
            S::reqStrList($d, 'ip_allowlist', '$'),
            $subs,
            S::reqBool($d, 'ip_allowlist_enforced', '$'), // Review 20b PHP-05: required boolean in spec 1.3.0
            S::toArray($d),
        );
    }
}
