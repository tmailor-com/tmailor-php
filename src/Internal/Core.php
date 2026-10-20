<?php

declare(strict_types=1);

namespace Tmailor\Internal;

use Tmailor\Client;
use Tmailor\Exception\ApiException;
use Tmailor\Exception\InvalidResponseException;
use Tmailor\Exception\MissingKeyException;
use Tmailor\Exception\TmailorException;
use Tmailor\Exception\WaitTimeoutException;
use Tmailor\Http\Response;
use Tmailor\RateLimitInfo;

/**
 * Transport-independent rules shared by every Tmailor SDK (see conformance/README.md): retries, error mapping,
 * deadlines, headers.
 *
 * @internal
 */
final class Core
{
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_MAX_RETRIES = 2;
    /** A Retry-After longer than this is not waited for automatically (for example DailyCapReached until midnight). */
    public const DEFAULT_MAX_RETRY_WAIT = 60.0;
    public const BACKOFF_BASE = 0.5;
    public const BACKOFF_MAX = 8.0;
    /** Statuses retried for SAFE requests only (the request may or may not have been processed). */
    public const RETRY_SAFE_STATUSES = [500, 502, 503, 504];
    /** A Retry-After of 0 (or negative, clamped to 0) still waits this long: never a busy retry loop. */
    public const MIN_RETRY_AFTER = 1.0;

    private const KEEP_ERROR_HEADERS = [
        'retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset', 'x-request-id', 'allow',
    ];

    private const REASONS = [
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 408 => 'Request Timeout', 409 => 'Conflict',
        410 => 'Gone', 413 => 'Request Entity Too Large', 415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity', 429 => 'Too Many Requests', 500 => 'Internal Server Error',
        501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    public static function userAgent(): string
    {
        return 'tmailor-php/' . Client::VERSION . ' (php ' . \PHP_VERSION . ')';
    }

    /** The key from the argument or TMAILOR_API_KEY; null when there is none. */
    public static function resolveApiKey(#[\SensitiveParameter] ?string $apiKey): ?Secret
    {
        $key = $apiKey ?? Env::get('TMAILOR_API_KEY') ?? '';
        $apiKey = null;
        $key = trim($key);

        return $key === '' ? null : Secret::of($key);
    }

    public static function requireKey(?Secret $key): Secret
    {
        if ($key === null) {
            throw new MissingKeyException(
                'No API key: pass apiKey: ... or set the TMAILOR_API_KEY environment variable',
                'MissingKey',
            );
        }

        return $key;
    }

    /** `tmk_live_<prefix>_<secret>` -> `tmk_live_<prefix>_***`. The prefix is not secret (GET /v1/me shows it). */
    public static function maskKey(?Secret $key): ?string
    {
        if ($key === null) {
            return null;
        }
        $parts = explode('_', $key->reveal());
        if (count($parts) >= 4 && $parts[0] === 'tmk') {
            return implode('_', array_slice($parts, 0, 3)) . '_***';
        }

        return '***';
    }

    public static function newIdempotencyKey(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $h = bin2hex($b);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }

    /** @return array<string, string> */
    public static function requestHeaders(RequestSpec $spec, ?Secret $apiKey): array
    {
        $headers = ['User-Agent' => self::userAgent(), 'Accept' => 'application/json'];
        if ($spec->auth) {
            $headers['Authorization'] = 'Bearer ' . self::requireKey($apiKey)->reveal();
        }

        return array_merge($headers, $spec->headers);
    }

    public static function intHeader(Response $r, string $name): ?int
    {
        $v = $r->header($name);
        if ($v === null) {
            return null;
        }
        $v = trim($v);
        if (preg_match('/^[+-]?\d{1,18}$/', $v) !== 1) {
            return null;
        }

        return (int) $v;
    }

    public static function rateLimit(Response $r): ?RateLimitInfo
    {
        $lim = self::intHeader($r, 'X-RateLimit-Limit');
        $rem = self::intHeader($r, 'X-RateLimit-Remaining');
        $rst = self::intHeader($r, 'X-RateLimit-Reset');
        if ($lim === null && $rem === null && $rst === null) {
            return null;
        }

        return new RateLimitInfo($lim, $rem, $rst);
    }

    /** @param array<string, mixed> $err */
    public static function retryAfterSeconds(Response $r, array $err): ?int
    {
        $v = self::intHeader($r, 'Retry-After');
        if ($v === null) {
            $ra = $err['retry_after'] ?? null;
            if (is_int($ra)) {
                $v = $ra;
            }
        }
        if ($v !== null && $v < 0) {
            $v = 0;
        }

        return $v;
    }

    /** Turn an error response into a typed exception. Never includes the request body or headers. */
    public static function buildError(Response $r): ApiException
    {
        $status = $r->status;
        $err = [];
        if ($r->body !== '') {
            try {
                $data = json_decode($r->body, false, 512, \JSON_THROW_ON_ERROR);
                if ($data instanceof \stdClass && isset($data->error) && $data->error instanceof \stdClass) {
                    $err = Schema::toArray($data->error);
                }
            } catch (\JsonException) {
                $err = [];
            }
        }
        $code = isset($err['code']) && is_string($err['code']) ? $err['code'] : null;
        $message = isset($err['message']) && is_string($err['message']) ? $err['message'] : '';
        if ($message === '') {
            $reason = $r->reasonPhrase !== '' ? $r->reasonPhrase : (self::REASONS[$status] ?? '');
            $message = trim('HTTP ' . $status . ' ' . $reason);
        }
        $description = isset($err['description']) && is_string($err['description']) ? $err['description'] : '';
        $requestId = isset($err['request_id']) && is_string($err['request_id']) ? $err['request_id'] : $r->header('X-Request-Id');
        $cls = ErrorMap::classFor($code, $status);
        $keep = [];
        foreach ($r->headers as $k => $v) {
            if (in_array($k, self::KEEP_ERROR_HEADERS, true)) {
                $keep[$k] = $v;
            }
        }

        return new $cls(
            $message,
            $code !== null && $code !== '' ? $code : 'HTTP' . $status,
            $status,
            $description,
            $requestId,
            self::retryAfterSeconds($r, $err),
            $err,
            $keep,
        );
    }

    /**
     * Seconds to wait before the next attempt, or null when the request must not be retried.
     *
     * - at most `maxRetries` retries;
     * - 429 RateLimited: the API did not process the request, so ANY request is retried after Retry-After;
     *   DailyCapReached or a Retry-After above `maxRetryWait` is not retried;
     * - 500/502/503/504 and network errors: only SAFE requests, except a failed connection (nothing was sent),
     *   which is retried for any request;
     * - 503 CacheUnavailable is never retried.
     */
    public static function retryDelay(
        RequestSpec $spec,
        int $attempt,
        ?TmailorException $error,
        int $maxRetries,
        float $maxRetryWait,
        bool $connectFailed = false,
    ): ?float {
        if ($attempt >= $maxRetries || $error === null) {
            return null;
        }
        $status = $error->status;
        if ($status === 429) {
            if ($error->errorCode === 'DailyCapReached') {
                return null;
            }
            $ra = $error->retryAfter;
            if ($ra === null) {
                return self::backoff($attempt);
            }
            if ($ra > $maxRetryWait) {
                return null;
            }

            return max((float) $ra, self::MIN_RETRY_AFTER);
        }
        if ($status === null) {
            return $connectFailed || $spec->safe ? self::backoff($attempt) : null;
        }
        if (in_array($status, self::RETRY_SAFE_STATUSES, true) && $spec->safe) {
            if ($error->errorCode === 'CacheUnavailable') {
                return null;
            }
            $ra = $error->retryAfter;
            if ($ra !== null) {
                return $ra <= $maxRetryWait ? max((float) $ra, self::MIN_RETRY_AFTER) : null;
            }

            return self::backoff($attempt);
        }

        return null;
    }

    /** Exponential backoff with full jitter: random in [c/2, c], c = min(8, 0.5 * 2^attempt). */
    public static function backoff(int $attempt): float
    {
        $ceiling = min(self::BACKOFF_MAX, self::BACKOFF_BASE * (2 ** min($attempt, 32)));
        $u = random_int(0, \PHP_INT_MAX) / \PHP_INT_MAX;

        return $ceiling / 2 + $u * ($ceiling / 2);
    }

    /** A retry sleep that would end at or after `deadline` is not taken (null). */
    public static function withinDeadline(?float $delay, float $now, ?float $deadline): ?float
    {
        if ($delay === null || $deadline === null) {
            return $delay;
        }

        return $now + $delay < $deadline ? $delay : null;
    }

    /**
     * The HTTP timeout of one attempt inside a deadline (waitForMessage): `[timeout, deadlineBound]`.
     *
     * Without a deadline it is the client timeout. With one, the time left caps the request: nothing left raises
     * WaitTimeoutException BEFORE anything is sent, and `deadlineBound` is true when the cap is the deadline (a
     * timeout of that request is then a WaitTimeoutException, not an ApiTimeoutException).
     *
     * @return array{0: float, 1: bool}
     */
    public static function requestTimeout(float $timeout, float $now, ?float $deadline): array
    {
        if ($deadline === null) {
            return [$timeout, false];
        }
        $remaining = $deadline - $now;
        if ($remaining <= 0) {
            throw new WaitTimeoutException();
        }
        if ($remaining <= $timeout) {
            return [$remaining, true];
        }

        return [$timeout, false];
    }

    /** True when a request ended after the wait deadline (its result is not used). */
    public static function pastDeadline(float $now, ?float $deadline): bool
    {
        return $deadline !== null && $now > $deadline;
    }

    /** Decode a 2xx body. 204 or an empty body is null; anything that is not JSON raises InvalidResponseException. */
    public static function parseJson(Response $r): mixed
    {
        if ($r->status === 204 || $r->body === '') {
            return null;
        }
        $ok = true;
        $data = null;
        try {
            $data = json_decode($r->body, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $ok = false;
        }
        if (!$ok) {
            // Not chained: the decoder error must not travel with the error.
            throw new InvalidResponseException('The API returned a response that is not JSON', $r->status, $r->header('X-Request-Id'));
        }

        return $data;
    }

    /**
     * Validate a 2xx body with a reader. A schema violation raises InvalidResponseException naming the field only.
     *
     * @template T
     *
     * @param callable(mixed): T $parse
     *
     * @return T
     */
    public static function parseBody(Response $r, mixed $data, callable $parse): mixed
    {
        try {
            return $parse($data);
        } catch (SchemaException $e) {
            $problem = $e->getMessage();
        }
        // The body can hold address tokens: clear the argument so the stack trace of the error cannot show it.
        $data = null;

        throw new InvalidResponseException(
            'The API response does not match the expected format: ' . $problem,
            $r->status,
            $r->header('X-Request-Id'),
        );
    }

    /**
     * Stop paginating when the API hands out a cursor that was already used (loop or cycle).
     *
     * @param array<string, true> $seen
     */
    public static function checkCursor(array &$seen, string $cursor): void
    {
        if (isset($seen[$cursor])) {
            throw new InvalidResponseException('The API returned a pagination cursor that was already used; stopping to avoid a loop');
        }
        $seen[$cursor] = true;
    }
}
