<?php

declare(strict_types=1);

namespace Tmailor\Internal;

use Tmailor\Internal\Schema as S;
use Tmailor\Model\WebAccess;

/**
 * Validated fields of the Mailbox schema.
 *
 * @internal
 */
final class MailboxFields
{
    /** `raw`: the JSON of the response object (it holds the token, so it is kept as a Secret). */
    private function __construct(
        public readonly string $email,
        public readonly string $domain,
        public readonly bool $isUserDomain,
        public readonly bool $customName,
        public readonly int $createdAt,
        public readonly ?int $messageCount,
        public readonly ?Secret $token,
        public readonly ?WebAccess $web,
        public readonly Secret $raw,
    ) {
    }

    public static function fromApi(mixed $data, string $path = '$'): self
    {
        $d = S::obj($data, $path);
        $tokenValue = S::reqStrOrNull($d, 'token', $path);
        $w = S::reqObjOrNull($d, 'web', $path);
        $web = $w !== null ? WebAccess::fromApi($w, $path . '.web') : null;
        // token and web are null together, and token is the same value as web.accesstoken (OpenAPI Mailbox).
        if (($tokenValue === null) !== ($web === null)) {
            throw new SchemaException($path . '.token and ' . $path . '.web must both be null or both be set');
        }
        if ($tokenValue !== null && $web !== null && !$web->accesstokenSecret()->equals($tokenValue)) {
            throw new SchemaException($path . '.token must equal ' . $path . '.web.accesstoken');
        }
        $token = Secret::ofNullable($tokenValue);
        $tokenValue = null;

        return new self(
            S::reqStr($d, 'email', $path),
            S::reqStr($d, 'domain', $path),
            S::reqBool($d, 'is_user_domain', $path),
            S::reqBool($d, 'custom_name', $path),
            S::reqInt($d, 'created_at', $path),
            S::optInt($d, 'message_count', $path),
            $token,
            $web,
            Secret::of(json_encode(S::toArray($d), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)),
        );
    }
}
