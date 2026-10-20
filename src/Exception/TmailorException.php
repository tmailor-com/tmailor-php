<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/**
 * Base class of every error raised by the SDK (API errors, network errors, invalid responses, wait timeouts).
 *
 * Branch on the class (or on {@see $errorCode}), never on the message text.
 *
 * - `errorCode`: code from the API (for example `QuotaExceeded`), or an SDK code such as `ConnectionError`,
 *   `Timeout`, `WaitTimeout`, `InvalidResponse`, `MissingKey`, `NoToken`. (PHP reserves `getCode()` for an
 *   integer; it returns the HTTP status, or 0.)
 * - `status`: HTTP status, or null when no HTTP response was received.
 * - `description`: longer explanation from the API (may be empty).
 * - `requestId`: request id of the failed request. Quote it when you contact support.
 * - `retryAfter`: seconds to wait before retrying (429 errors), or null.
 * - `body`: the `error` object of the response (never holds an API key or address token).
 * - `headers`: Retry-After, X-RateLimit-*, X-Request-Id and Allow headers of the response (lower-case names).
 *
 * The message never contains the API key, an address token or the request body.
 */
class TmailorException extends \RuntimeException
{
    public readonly string $errorCode;
    public readonly ?int $status;
    public readonly string $description;
    public readonly ?string $requestId;
    public readonly ?int $retryAfter;
    /** @var array<string, mixed> */
    public readonly array $body;
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        string $errorCode = 'Error',
        ?int $status = null,
        string $description = '',
        ?string $requestId = null,
        ?int $retryAfter = null,
        array $body = [],
        array $headers = [],
    ) {
        // Never chained: a previous transport or decoder exception can hold request headers or the body.
        parent::__construct($message, $status ?? 0);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->description = $description;
        $this->requestId = $requestId;
        $this->retryAfter = $retryAfter;
        $this->body = $body;
        $this->headers = $headers;
    }

    /** `[code] HTTP 404 message (request_id=...)`: the same summary as the other Tmailor SDKs. */
    public function summary(): string
    {
        $parts = ['[' . $this->errorCode . ']'];
        if ($this->status !== null) {
            $parts[] = 'HTTP ' . $this->status;
        }
        $parts[] = $this->getMessage();
        if ($this->requestId !== null && $this->requestId !== '') {
            $parts[] = '(request_id=' . $this->requestId . ')';
        }

        return implode(' ', $parts);
    }
}
