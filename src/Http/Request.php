<?php

declare(strict_types=1);

namespace Tmailor\Http;

/**
 * One HTTP request handed to a {@see TransportInterface}. `url` is absolute and already has its query string.
 *
 * The `Authorization` header holds the API key and the body can hold an address token: var_dump()/print_r() show
 * neither.
 */
final class Request
{
    /**
     * @param array<string, string> $headers header name => value
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $headers = [];
        foreach ($this->headers as $k => $v) {
            $headers[$k] = strcasecmp($k, 'Authorization') === 0 ? '***' : $v;
        }

        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $headers,
            'body' => $this->body === null ? null : '<' . strlen($this->body) . ' bytes>',
        ];
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
