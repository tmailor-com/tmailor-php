<?php

declare(strict_types=1);

namespace Tmailor\Http;

/**
 * No HTTP response was received. Thrown by a {@see TransportInterface}; the client turns it into
 * ApiConnectionException or ApiTimeoutException (never chained, so request data cannot leak through it).
 *
 * - `connect`: the connection failed before anything was sent (DNS, refused, TLS handshake). Retried for any request.
 * - `read`: the connection broke after the request may have been sent. Retried for safe requests only.
 * - `timeout`: the request timed out. `connectPhase` is true when it timed out before anything was sent.
 *
 * Review 20b PHP-03: the message is for the transport's own logs only. The client never copies it into the errors
 * it raises (a custom transport could put a header or the body in it); it keeps only `kind`, `connectPhase` and
 * the integer `curlErrno`.
 */
final class TransportException extends \RuntimeException
{
    public const CONNECT = 'connect';
    public const READ = 'read';
    public const TIMEOUT = 'timeout';

    private function __construct(
        public readonly string $kind,
        public readonly bool $connectPhase,
        string $message,
        public readonly ?int $curlErrno = null,
    ) {
        parent::__construct($message);
    }

    public static function connect(string $message = 'Connection failed', ?int $curlErrno = null): self
    {
        return new self(self::CONNECT, true, $message, $curlErrno);
    }

    public static function read(string $message = 'Network error', ?int $curlErrno = null): self
    {
        return new self(self::READ, false, $message, $curlErrno);
    }

    public static function timeout(string $message = 'Request timed out', bool $connectPhase = false, ?int $curlErrno = null): self
    {
        return new self(self::TIMEOUT, $connectPhase, $message, $curlErrno);
    }
}
