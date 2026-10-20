<?php

declare(strict_types=1);

namespace Tmailor\Http;

use Tmailor\Internal\Sealed;

/**
 * An HTTP response returned by a {@see TransportInterface}.
 *
 * Review 20b PHP-02: the body (it can hold address tokens) and the headers are not properties: read them with
 * {@see body()}, {@see headers()} and {@see header()}. Dumps and json_encode() show only the body length.
 */
final class Response implements \JsonSerializable
{
    /** @var Sealed<array{headers: array<string, string>, body: string}> */
    private readonly Sealed $sealed;

    /**
     * @param array<string, string> $headers header name => value (any case)
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        #[\SensitiveParameter] string $body = '',
        public readonly string $reasonPhrase = '',
    ) {
        $h = [];
        foreach ($headers as $k => $v) {
            $k = strtolower((string) $k);
            $h[$k] = isset($h[$k]) ? $h[$k] . ', ' . $v : $v;
        }
        $this->sealed = Sealed::of(['headers' => $h, 'body' => $body]);
        $body = '';
    }

    /** @return array<string, string> lower-case header name => value (repeated headers joined with ", ") */
    public function headers(): array
    {
        return $this->sealed->value()['headers'];
    }

    public function header(string $name): ?string
    {
        return $this->headers()[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->sealed->value()['body'];
    }

    /** @return array<string, mixed> */
    private function masked(): array
    {
        return [
            'status' => $this->status,
            'reasonPhrase' => $this->reasonPhrase,
            'headers' => $this->headers(),
            'body' => '<' . strlen($this->body()) . ' bytes>',
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
        throw new \LogicException('A Tmailor HTTP response cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Tmailor HTTP response cannot be unserialized');
    }
}
