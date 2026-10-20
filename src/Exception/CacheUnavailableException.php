<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/**
 * 503 CacheUnavailable: an address delete could not be confirmed (never retried automatically).
 *
 * `secretChanged` is false when nothing changed (retry later) and true when the address was deleted but old
 * tokens may keep working on tmailor.com for up to 10 minutes; null when the API did not say.
 */
class CacheUnavailableException extends ServiceUnavailableException
{
    public readonly ?bool $secretChanged;

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        string $errorCode = 'CacheUnavailable',
        ?int $status = null,
        string $description = '',
        ?string $requestId = null,
        ?int $retryAfter = null,
        array $body = [],
        array $headers = [],
    ) {
        parent::__construct($message, $errorCode, $status, $description, $requestId, $retryAfter, $body, $headers);
        $v = $body['secret_changed'] ?? null;
        $this->secretChanged = is_bool($v) ? $v : null;
    }
}
