<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;
use Tmailor\Internal\Secret;

/**
 * Tokens to use the address on tmailor.com. Treat them like passwords: anyone holding them can read the inbox
 * (accesstoken, reuseUrl) or take over the address (transfertoken). var_dump(), print_r(), var_export() and
 * json_encode() never show them.
 *
 * @property-read string $accesstoken
 * @property-read string $transfertoken
 * @property-read string $reuseUrl
 */
final class WebAccess implements \JsonSerializable
{
    private readonly Secret $accesstokenSecret;
    private readonly Secret $transfertokenSecret;
    private readonly Secret $reuseUrlSecret;

    public function __construct(
        #[\SensitiveParameter] string $accesstoken,
        #[\SensitiveParameter] string $transfertoken,
        #[\SensitiveParameter] string $reuseUrl,
    ) {
        $this->accesstokenSecret = Secret::of($accesstoken);
        $this->transfertokenSecret = Secret::of($transfertoken);
        $this->reuseUrlSecret = Secret::of($reuseUrl);
        $accesstoken = $transfertoken = $reuseUrl = '';
    }

    /** @internal */
    public static function fromApi(mixed $data, string $path = '$.web'): self
    {
        $d = S::obj($data, $path);

        return new self(S::reqStr($d, 'accesstoken', $path), S::reqStr($d, 'transfertoken', $path), S::reqStr($d, 'reuse_url', $path));
    }

    /** @internal */
    public function accesstokenSecret(): Secret
    {
        return $this->accesstokenSecret;
    }

    public function __get(string $name): string
    {
        return match ($name) {
            'accesstoken' => $this->accesstokenSecret->reveal(),
            'transfertoken' => $this->transfertokenSecret->reveal(),
            'reuseUrl' => $this->reuseUrlSecret->reveal(),
            default => throw new \LogicException('Undefined property ' . self::class . '::$' . $name),
        };
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['accesstoken', 'transfertoken', 'reuseUrl'], true);
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \LogicException(self::class . ' is read-only');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['accesstoken' => '***', 'transfertoken' => '***', 'reuseUrl' => '***'];
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['accesstoken' => '***', 'transfertoken' => '***', 'reuse_url' => '***'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('WebAccess holds secrets and cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('WebAccess holds secrets and cannot be unserialized');
    }
}
