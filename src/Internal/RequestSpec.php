<?php

declare(strict_types=1);

namespace Tmailor\Internal;

/**
 * One logical API call. Retries resend exactly the same bytes and headers.
 *
 * The JSON body can hold an address token, so it is kept as a {@see Secret} (stack traces show only the object).
 *
 * @internal
 */
final class RequestSpec
{
    /** @var array<string, string|int> */
    public readonly array $query;
    /** @var array<string, string> */
    public readonly array $headers;
    public readonly ?Secret $content;

    /**
     * @param array<string, string|int|null> $query      null values are dropped
     * @param array<string, mixed>|null      $jsonBody   encoded once, as a JSON object
     * @param bool                           $safe       true when repeating the request cannot change state twice
     * @param array<string, string>          $headers    extra headers (Idempotency-Key)
     * @param bool                           $auth       false for the public GET /v1/health (no Authorization)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $query = [],
        #[\SensitiveParameter] ?array $jsonBody = null,
        public readonly bool $safe = false,
        array $headers = [],
        public readonly bool $auth = true,
    ) {
        $this->query = array_filter($query, static fn ($v): bool => $v !== null);
        if ($jsonBody !== null) {
            try {
                $json = json_encode((object) $jsonBody, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $jsonBody = null;

                throw new \InvalidArgumentException('Request arguments must be valid UTF-8 strings');
            }
            $jsonBody = null;
            $this->content = Secret::of($json);
            $headers += ['Content-Type' => 'application/json'];
        } else {
            $this->content = null;
        }
        $this->headers = $headers;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'safe' => $this->safe,
            'auth' => $this->auth,
            'body' => $this->content === null ? null : '***',
        ];
    }
}
