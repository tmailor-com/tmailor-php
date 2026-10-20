<?php

declare(strict_types=1);

namespace Tmailor;

use Tmailor\Clock\ClockInterface;
use Tmailor\Clock\SystemClock;
use Tmailor\Http\CurlTransport;
use Tmailor\Http\TransportInterface;
use Tmailor\Internal\BaseUrl;
use Tmailor\Internal\Core;
use Tmailor\Internal\Ops;
use Tmailor\Internal\Requester;
use Tmailor\Internal\Secret;
use Tmailor\Model\DomainList;
use Tmailor\Model\Health;
use Tmailor\Model\Me;
use Tmailor\Model\Usage;

/**
 * Client for the Tmailor Open API.
 *
 * ```php
 * $client = new Tmailor\Client();                  // reads TMAILOR_API_KEY
 * $box = $client->mailboxes->create();
 * $msg = $box->waitForMessage(timeout: 120, since: $box->createdAt - 1);
 * echo $msg->otp;
 * ```
 *
 * Use it on a server only: requests must come from an IP in your account allowlist, and the API key must never be
 * shipped to browsers or mobile apps.
 *
 * - `apiKey`: `tmk_live_...` key. Defaults to the TMAILOR_API_KEY environment variable. Only `health()` works
 *   without a key; other calls throw MissingKeyException before sending anything.
 * - `baseUrl`: defaults to TMAILOR_BASE_URL or https://open-api.tmailor.com.
 * - `timeout`: seconds for each HTTP request (a total limit per attempt).
 * - `maxRetries`: automatic retries for 429, 5xx and network errors (see README "Retries").
 * - `maxRetryWait`: longest Retry-After (seconds) that is waited for automatically.
 * - `transport`: your own {@see TransportInterface} (default {@see CurlTransport}).
 * - `allowUntrustedBaseUrl`: required (true) for a base URL whose host is not open-api.tmailor.com (staging, a
 *   proxy, http://127.0.0.1 for local development): the API key and address tokens are sent to that host.
 *   http:// is accepted only for a loopback host. Throws Exception\BaseUrlException otherwise.
 * - `clock`: replace the time source (tests).
 */
final class Client implements \JsonSerializable
{
    public const VERSION = '0.1.0';
    /** Open API spec version this SDK version was written for. */
    public const API_SPEC_VERSION = '1.3.0';
    public const DEFAULT_BASE_URL = BaseUrl::DEFAULT;

    public readonly string $baseUrl;
    public readonly float $timeout;
    public readonly int $maxRetries;
    public readonly float $maxRetryWait;
    public readonly Mailboxes $mailboxes;
    private readonly Requester $requester;

    public function __construct(
        #[\SensitiveParameter] ?string $apiKey = null,
        ?string $baseUrl = null,
        float|int $timeout = Core::DEFAULT_TIMEOUT,
        int $maxRetries = Core::DEFAULT_MAX_RETRIES,
        float|int $maxRetryWait = Core::DEFAULT_MAX_RETRY_WAIT,
        ?TransportInterface $transport = null,
        bool $allowUntrustedBaseUrl = false,
        ?ClockInterface $clock = null,
    ) {
        // The key is wrapped first and the argument cleared, so no stack trace (PHP 8.1 has no
        // #[\SensitiveParameter]) can show it if a later check throws.
        $key = Core::resolveApiKey($apiKey);
        $apiKey = null;
        $this->baseUrl = BaseUrl::resolve($baseUrl, $allowUntrustedBaseUrl);
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be >= 0');
        }
        if (!is_finite((float) $timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be > 0');
        }
        if (is_nan((float) $maxRetryWait) || $maxRetryWait < 0) {
            throw new \InvalidArgumentException('maxRetryWait must be >= 0');
        }
        $this->timeout = (float) $timeout;
        $this->maxRetries = $maxRetries;
        $this->maxRetryWait = (float) $maxRetryWait;
        $this->requester = new Requester(
            $key,
            $this->baseUrl,
            $this->timeout,
            $this->maxRetries,
            $this->maxRetryWait,
            $transport ?? new CurlTransport(),
            $clock ?? new SystemClock(),
        );
        $this->mailboxes = new Mailboxes($this->requester);
    }

    /** `GET /v1/health` (public: no key needed, none is sent). A degraded service throws ServiceUnavailableException (code HTTP503). */
    public function health(): Health
    {
        return $this->requester->call(Ops::health(), Health::fromApi(...));
    }

    /** `GET /v1/me`: account, key prefix, limits, IP allowlist and plans. */
    public function me(): Me
    {
        return $this->requester->call(Ops::me(), Me::fromApi(...));
    }

    /** `GET /v1/domains`: your own domains and whether shared domains are available. */
    public function domains(): DomainList
    {
        return $this->requester->call(Ops::domains(), DomainList::fromApi(...));
    }

    /** `GET /v1/usage`: daily counters. Dates are DateTimeInterface or 'YYYY-MM-DD' strings. */
    public function usage(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null): Usage
    {
        return $this->requester->call(Ops::usage($from, $to), Usage::fromApi(...));
    }

    /** A Mailbox for a token you stored earlier. No request is made; call `info()` to load its fields. */
    public function mailbox(#[\SensitiveParameter] string $token): Mailbox
    {
        $t = trim($token);
        $token = '';
        if ($t === '') {
            throw new \InvalidArgumentException('token is required');
        }

        return new Mailbox($this->requester, Secret::of($t));
    }

    /** X-RateLimit-* values of the last response that had them (null before the first such response). */
    public function lastRateLimit(): ?RateLimitInfo
    {
        return $this->requester->lastRateLimit;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => Core::maskKey($this->requester->apiKey())];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['base_url' => $this->baseUrl, 'api_key' => Core::maskKey($this->requester->apiKey())];
    }

    public function __toString(): string
    {
        return sprintf('Tmailor\Client(baseUrl=%s, apiKey=%s)', $this->baseUrl, Core::maskKey($this->requester->apiKey()) ?? 'null');
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A Tmailor client holds the API key and cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Tmailor client cannot be unserialized');
    }
}
