<?php

declare(strict_types=1);

namespace Tmailor\Http;

use Tmailor\Internal\Sealed;

/**
 * One HTTP request handed to a {@see TransportInterface}. `url` is absolute and already has its query string.
 *
 * Review 20b PHP-02: the headers (the `Authorization` header holds the API key) and the body (it can hold an address
 * token) are not properties: read them with {@see headers()}, {@see header()} and {@see body()}. var_dump(),
 * print_r(), var_export(), json_encode() and (array) casts never show them, and serialize() is refused.
 */
final class Request implements \JsonSerializable
{
    /** @var Sealed<array{headers: array<string, string>, body: ?string}> */
    private readonly Sealed $sealed;

    /**
     * @param array<string, string> $headers header name => value
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] ?string $body,
    ) {
        $this->sealed = Sealed::of(['headers' => $headers, 'body' => $body]);
        $headers = [];
        $body = null;
    }

    /** @return array<string, string> header name => value */
    public function headers(): array
    {
        return $this->sealed->value()['headers'];
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers() as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }

        return null;
    }

    public function body(): ?string
    {
        return $this->sealed->value()['body'];
    }

    /** @return array<string, mixed> */
    private function masked(): array
    {
        $headers = [];
        foreach ($this->headers() as $k => $v) {
            $headers[$k] = strcasecmp($k, 'Authorization') === 0 ? '***' : $v;
        }
        $body = $this->body();

        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $headers,
            'body' => $body === null ? null : '<' . strlen($body) . ' bytes>',
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->masked();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->masked();
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A Tmailor HTTP request cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Tmailor HTTP request cannot be unserialized');
    }
}
