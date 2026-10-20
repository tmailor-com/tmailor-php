<?php

declare(strict_types=1);

namespace Tmailor\Http;

/** An HTTP response returned by a {@see TransportInterface}. */
final class Response
{
    /** @var array<string, string> lower-case header name => value (repeated headers joined with ", ") */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers header name => value (any case)
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
        public readonly string $reasonPhrase = '',
    ) {
        $h = [];
        foreach ($headers as $k => $v) {
            $k = strtolower((string) $k);
            $h[$k] = isset($h[$k]) ? $h[$k] . ', ' . $v : $v;
        }
        $this->headers = $h;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'reasonPhrase' => $this->reasonPhrase,
            'headers' => $this->headers,
            'body' => '<' . strlen($this->body) . ' bytes>',
        ];
    }
}
