<?php

declare(strict_types=1);

namespace Tmailor\Internal;

use Tmailor\Clock\ClockInterface;
use Tmailor\Exception\ApiConnectionException;
use Tmailor\Exception\ApiTimeoutException;
use Tmailor\Exception\WaitTimeoutException;
use Tmailor\Http\Request;
use Tmailor\Http\Response;
use Tmailor\Http\TransportException;
use Tmailor\Http\TransportInterface;
use Tmailor\RateLimitInfo;

/**
 * Sends RequestSpecs with the retry rules. Shared by Client, Mailboxes and Mailbox.
 *
 * @internal
 */
final class Requester
{
    public ?RateLimitInfo $lastRateLimit = null;

    public function __construct(
        private readonly ?Secret $apiKey,
        public readonly string $baseUrl,
        public readonly float $timeout,
        public readonly int $maxRetries,
        public readonly float $maxRetryWait,
        private readonly TransportInterface $transport,
        public readonly ClockInterface $clock,
    ) {
    }

    public function apiKey(): ?Secret
    {
        return $this->apiKey;
    }

    /**
     * @template T
     *
     * @param callable(mixed): T $parse
     *
     * @return T
     */
    public function call(RequestSpec $spec, callable $parse, ?float $deadline = null, bool $retry429 = true): mixed
    {
        [$data, $resp] = $this->request($spec, $deadline, $retry429);

        return Core::parseBody($resp, $data, $parse);
    }

    /**
     * Send `spec` with the retry rules.
     *
     * `deadline` (monotonic) bounds every retry sleep and every HTTP attempt: no time left raises
     * WaitTimeoutException before sending, the attempt timeout is min(timeout, time left), and a timeout caused by
     * the deadline raises WaitTimeoutException. `retry429: false` leaves 429 to the caller (waitForMessage
     * handles it with its own deadline-aware plan).
     *
     * @return array{0: mixed, 1: Response}
     */
    public function request(RequestSpec $spec, ?float $deadline = null, bool $retry429 = true): array
    {
        $url = $this->baseUrl . $spec->path;
        if ($spec->query !== []) {
            $url .= '?' . http_build_query($spec->query, '', '&', \PHP_QUERY_RFC3986);
        }
        $request = new Request($spec->method, $url, Core::requestHeaders($spec, $this->apiKey), $spec->content?->reveal());
        $clock = $this->clock;
        $attempt = 0;
        while (true) {
            $connectFailed = false;
            [$timeout, $deadlineBound] = Core::requestTimeout($this->timeout, $clock->monotonic(), $deadline);
            $resp = null;
            $failure = null;
            try {
                $resp = $this->transport->send($request, $timeout);
            } catch (TransportException $e) {
                // Review 20b PHP-03: never the transport's free-text message (a custom transport could put a header
                // or the body in it): a fixed text per kind, plus the curl error number when there is one.
                $errno = $e->curlErrno;
                $failure = [$e->kind, $e->connectPhase, $errno !== null && $errno > 0 ? ' (curl error ' . $errno . ')' : ''];
                unset($e); // never chained: the transport exception's trace holds the request
            }
            if ($failure !== null) {
                [$kind, $connectPhase, $detail] = $failure;
                if ($kind === TransportException::TIMEOUT) {
                    if ($deadlineBound || Core::pastDeadline($clock->monotonic(), $deadline)) {
                        throw new WaitTimeoutException(); // the wait deadline ended this request
                    }
                    $err = new ApiTimeoutException('Request timed out' . $detail);
                    $connectFailed = $connectPhase;
                } elseif ($kind === TransportException::CONNECT) {
                    $err = new ApiConnectionException('Connection failed' . $detail);
                    $connectFailed = true;
                } else {
                    $err = new ApiConnectionException('Network error' . $detail);
                }
            } else {
                // A request that ENDED after the wait deadline is a WaitTimeoutException (its result is dropped).
                if (Core::pastDeadline($clock->monotonic(), $deadline)) {
                    throw new WaitTimeoutException();
                }
                $rl = Core::rateLimit($resp);
                if ($rl !== null) {
                    $this->lastRateLimit = $rl;
                }
                // Review 20b PHP-04: only 2xx is success. Redirects are never followed (the Authorization header must not
                // reach another host), so a 3xx, a 1xx or a status 0 is an error, never a silent success (a void call
                // such as delete() would look done). buildError() gives it the class of its status (ApiException).
                if ($resp->status >= 200 && $resp->status < 300) {
                    return [Core::parseJson($resp), $resp];
                }
                $err = Core::buildError($resp);
            }
            $delay = null;
            if ($retry429 || $err->status !== 429) {
                $delay = Core::retryDelay($spec, $attempt, $err, $this->maxRetries, $this->maxRetryWait, $connectFailed);
                $delay = Core::withinDeadline($delay, $clock->monotonic(), $deadline);
            }
            if ($delay === null) {
                throw $err;
            }
            $clock->sleep($delay);
            ++$attempt;
        }
    }
}
