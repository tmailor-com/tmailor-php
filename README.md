# tmailor/tmailor: PHP SDK for the Tmailor Open API

[![CI](https://github.com/tmailor-com/tmailor-php/actions/workflows/ci.yml/badge.svg)](https://github.com/tmailor-com/tmailor-php/actions/workflows/ci.yml)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/tmailor-com/tmailor-php/blob/main/LICENSE)

The official PHP SDK for the Open API of [Tmailor](https://tmailor.com), the temporary email service. Create
receive-only temporary email addresses and read incoming messages from your backend. Use it for end-to-end sign-up
tests, OTP and verification-message checks in QA pipelines, and inbox automation.

- PHP 8.1+, `ext-curl` and `ext-json` only, no runtime dependencies.
- Typed models with read-only properties, typed exceptions, idempotent address creation, automatic retries, and
  rate-limit-aware polling.
- Pagination with generators (`foreach`), named arguments everywhere.
- Version 0.1.0, written for Open API spec 1.3.0. [API reference](https://platform.tmailor.com/docs)

## Install

```bash
composer require tmailor/tmailor
```

## Setup

1. Sign in to [Tmailor Platform](https://platform.tmailor.com), create an API key, and add the caller's public
   IP address to your allowlist.
2. Provide the key:

```bash
export TMAILOR_API_KEY="tmk_live_..."
```

You can also pass it explicitly: `new Tmailor\Client('tmk_live_...')`. A client without a key can call only
`health()`; any other call throws `Tmailor\Exception\MissingKeyException` (code `MissingKey`, no HTTP status)
before sending a request.

## Quickstart

```php
use Tmailor\Client;

$client = new Client();                    // reads TMAILOR_API_KEY
$box = $client->mailboxes->create();       // random address on a shared domain
echo $box->email, "\n";

signUpSomewhere($box->email);              // your code triggers an email

// `since` is exclusive (receivedAt > since); "- 1" also accepts a message received in the creation second.
$msg = $box->waitForMessage(timeout: 120, since: $box->createdAt - 1);
echo $msg->subject, ' ', $msg->otp, ' ', implode(' ', $msg->links), "\n";
$box->delete();
```

## Server-side only

The API accepts requests only from the IP addresses in your account allowlist, and your API key gives full
access to your account. Use this SDK on servers, CI runners, or scripts you control. Never ship the API key or
address tokens to browsers or mobile apps.

## Client options

All options are named arguments of `new Tmailor\Client(...)`.

| Option | Default | Description |
|---|---|---|
| `apiKey` | `$TMAILOR_API_KEY` | `tmk_live_<prefix>_<secret>` |
| `baseUrl` | `$TMAILOR_BASE_URL` or `https://open-api.tmailor.com` | API endpoint (see below) |
| `timeout` | `30` | seconds per HTTP request (a total limit for each attempt) |
| `maxRetries` | `2` | automatic retries (see [Retries](#retries)) |
| `maxRetryWait` | `60` | maximum `Retry-After` delay, in seconds, that is handled automatically |
| `transport` | `new CurlTransport()` | your own `Tmailor\Http\TransportInterface` (proxy, custom HTTP stack, tests) |
| `allowUntrustedBaseUrl` | `false` | required for any host other than `open-api.tmailor.com` |
| `clock` | `new SystemClock()` | a `Tmailor\Clock\ClockInterface` (monotonic clock, wall clock, sleep), for tests |

**Base URL.** Every authenticated request sends your API key in the `Authorization` header. Address operations
also send the address token in the JSON body. The SDK therefore accepts only `https://` URLs without user
information (`user:pass@`), a query string, or a fragment. A host other than `open-api.tmailor.com` (staging,
your own proxy) throws `Tmailor\Exception\BaseUrlException` (an `InvalidArgumentException`) unless you pass
`allowUntrustedBaseUrl: true`, which states that you trust that host with your credentials. This also applies to
`TMAILOR_BASE_URL`. Plain `http://` is accepted only for a loopback host (`127.0.0.1`, `::1`, `localhost`) during
local development, and it also requires `allowUntrustedBaseUrl: true`.

**Transport.** The default `CurlTransport` keeps one curl handle per client so connections are reused, verifies
TLS certificates, never follows redirects, and allows only HTTP and HTTPS. Extra curl options (for example a proxy
or a CA bundle) go to its constructor: `new CurlTransport([CURLOPT_PROXY => 'http://proxy:3128'])`. To use another
HTTP stack, implement `TransportInterface::send(Request $request, float $timeout): Response` and throw
`Tmailor\Http\TransportException::connect()`, `::read()` or `::timeout()` when no HTTP response is received.

## Addresses

```php
$box = $client->mailboxes->create();                                         // random name, shared domain
$box = $client->mailboxes->create(domain: 'mail.example.com', name: 'signup'); // your own verified domain

$box->email; $box->domain; $box->createdAt; $box->token; $box->web;
```

- Creating an address uses 1 unit of your mailbox quota (`402 QuotaExceeded` when the quota is used up).
- Addresses never expire. They stay in your account until you delete them. Deleting an address does not return
  quota.
- Custom names are allowed only on your own domains (`$client->domains()`). Add and verify domains on
  [Tmailor Platform](https://platform.tmailor.com). The system checks your domains' MX records automatically; to
  request an immediate check, select "Check now" on the platform. The API has no domain-check operation.
- A `name` that has already been used to create an address on the [tmailor.com](https://tmailor.com) website or
  mobile app cannot be used through the API. `create()` throws `MailboxExistsException` (409) in that case; pick
  another name.

**The token is the key to the address.** Every operation on an address sends `$box->token` in the JSON body.
Store it if you need the address later:

```php
$box = $client->mailbox($savedToken);  // no request is made
$box->info();                          // loads email, messageCount, current tokens
```

Lost a token? `$client->mailboxes->list()` returns every address your account still owns and its current token
when available. Listing addresses is also how you recover after a token was changed on Tmailor Platform:

```php
foreach ($client->mailboxes->list(limit: 100) as $box) {  // follows next_cursor, up to 100 addresses
    echo $box->email, ' ', $box->messageCount, "\n";
}

$page = $client->mailboxes->listPage(pageSize: 100);       // one page: ->items, ->nextCursor
```

A token and `$box->web` may temporarily be `null`; an address operation without a token throws
`Tmailor\Exception\TmailorException` with `errorCode` `NoToken`.

Delete an address:

```php
$box->delete();  // same as "Delete" on tmailor.com; all tokens stop working
```

The API does not rotate tokens. To replace an address's tokens, use Tmailor Platform. The old token then
produces `MailboxNotFoundException`, and `$client->mailboxes->list()` returns the new token.

When it is available, `$box->web` holds the tokens the tmailor.com website uses (`accesstoken`, `transfertoken`,
`reuseUrl`). Anyone holding them can read the inbox on tmailor.com or, with `transfertoken`, take over the
address. Treat them like passwords. `var_dump()`, `print_r()`, `var_export()`, `json_encode()` and string casts of
SDK objects never show complete tokens or API keys (a client may show the API key's non-secret prefix), and
`serialize()` of a client, mailbox or `WebAccess` is refused.

## Messages

```php
foreach ($box->messages(unread: true, from: 'shop', subject: 'confirm') as $m) {  // newest first, all pages
    echo $m->id, ' ', $m->from->address, ' ', $m->subject, ' ', $m->receivedAt, ' ', var_export($m->seen, true), "\n";
}

$page = $box->messagesPage(pageSize: 20);  // ->items, ->nextCursor, ->total; items do not include bodies

$msg = $box->message($m->id);  // fetches ->text and sanitized ->html; marks the message as read
$box->deleteMessage($msg);
$deleted = $box->clear();      // deletes all messages and returns the count
```

Calling `$box->message()`, or receiving a message from `waitForMessage()`, marks that message as read. It then no
longer appears with `unread: true`. There is no separate "mark as read" call.

Filters run on the server: `unread`, `from` (text in the sender name or address), `subject` (text in the
subject), and `since` (Unix seconds or a `DateTimeInterface`; **exclusive**: only messages with
`receivedAt > since`, in whole seconds). `limit` caps how many messages the generator yields; `pageSize` (1 to
100) sets the page size. Messages are kept for 24 hours. If the API ever returns a pagination cursor it has
already returned, the generators stop with `InvalidResponseException` instead of looping.

### Waiting for a message

```php
$msg = $box->waitForMessage(timeout: 120, from: 'no-reply@shop.example', subjectContains: 'verify');
```

`waitForMessage` returns, with its body, the first matching message that was **not in the inbox when the wait
started**. It first records the IDs of all current messages, without filters; `from` and `subjectContains` apply
only to the polls. If the message may arrive before polling starts (the usual case: you trigger the email, then
wait), pass `since` so that it can still match:

```php
$box = $client->mailboxes->create();
triggerEmail($box->email);
$msg = $box->waitForMessage(since: $box->createdAt - 1);  // server time, no clock-skew issues
```

`since` is exclusive and counts whole seconds, so `since: $box->createdAt` would miss a message received in the
same second the address was created. Subtract 1 as shown above.

The first poll is immediate. After an unsuccessful poll, the delay starts at 1 second and grows to 10 seconds.
`pollInterval:` sets a fixed delay with a minimum of 0.5 seconds; a value of zero or less throws
`InvalidArgumentException`. When `X-RateLimit-Remaining` is low, the SDK spreads the remaining requests until the
minute resets. A `429 RateLimited` response during the wait is handled by sleeping for `Retry-After` (at least
1 second).

`timeout` (seconds) is the deadline of the whole wait. No sleep or retry goes past it, each request (polls and the
final read of the message) gets at most the remaining time as its curl timeout, which is a total limit for the
request, and a poll scheduled after a delay starts only if at least 1 second remains before the deadline. If
nothing arrives in time, or the deadline interrupts a request, it throws `WaitTimeoutException`; `timeout: 0`
throws it without sending a request.

### Codes and links

```php
$msg->otp;    // "482913", or null when there is no confident answer
$msg->codes;  // ["482913"]: every code-like value, use it when otp is null or picks the wrong one
$msg->links;  // ["https://example.com/verify?t=..."]
```

These values are found by the API (fields `otp`, `codes` and `links` of `POST /v1/mailbox/message`, Open API
1.3.0), so every language and plain `curl` get the same answer. They are filled for a message read with its body
(`$box->message($id)` or `waitForMessage()`). A message from the list, or a response from a server without those
fields, has `otp` `null` and empty `codes` and `links`; read it with `$box->message($m->id)` to get them. The SDK
has no local extractor. Always handle `null`.

## Account

```php
$me = $client->me();        // userId, limits (quotaRemaining, ratePerMin, dailyReqCap, ...), ipAllowlist, subscriptions
$doms = $client->domains(); // your own domains (iterable, countable) + $doms->sharedDomainsAvailable
$usage = $client->usage(from: '2026-10-01', to: '2026-10-17');  // daily counters; DateTimeInterface also works
$client->health();          // public status: no key needed and none is sent
$client->lastRateLimit();   // RateLimitInfo(limit, remaining, reset) of the last response, or null
```

Every model keeps the decoded JSON object in `->raw` (an array), so fields added to the API later are still
reachable.

## Errors

API, network, response-validation, and wait failures are subclasses of `Tmailor\Exception\TmailorException` with
`errorCode`, `status`, `getMessage()`, `description`, `requestId`, and `retryAfter` (PHP reserves `getCode()` for an
integer: it returns the HTTP status, or 0). `summary()` gives `[code] HTTP 404 message (request_id=...)`. Invalid
local arguments throw `InvalidArgumentException` (or `TypeError`); `BaseUrlException` is an
`InvalidArgumentException`. Handle errors by class or `errorCode`, not by message text. Include `requestId` when
contacting support.

All classes are in the `Tmailor\Exception` namespace.

| HTTP | Class (code) |
|---|---|
| 400, 413 | `BadRequestException`: `InvalidJsonException`, `InvalidFieldException`, `InvalidDomainException`, `CustomNameNotAllowedException`, `InvalidNameException` (`->reason`) |
| 401 | `AuthenticationException`: `MissingKeyException`, `InvalidKeyException`, `KeyRevokedException` |
| 402 | `QuotaExceededException` |
| 403 | `PermissionDeniedException`: `AccountLockedException`, `IpNotAllowedException` |
| 404 | `NotFoundException`: `MailboxNotFoundException`, `MessageNotFoundException` |
| 405 | `MethodNotAllowedException` |
| 409 | `ConflictException`: `MailboxExistsException` (including a name created on the website or mobile app) |
| 429 | `RateLimitException`: `RateLimitedException`, `DailyCapReachedException` |
| 500, other 5xx | `InternalServerException`, unless a more specific error code applies |
| 503 | `ServiceUnavailableException`: `NoDomainAvailableException`, `IdempotencyUnavailableException`, `CacheUnavailableException` (`->secretChanged`); `InternalServerException` (`InternalError`, reason `cache_hold_failed`); a degraded `health()` (code `HTTP503`) |
| other 4xx | `ApiException` |
| 2xx | `InvalidResponseException` (`InvalidResponse`): body not JSON, a field missing or of the wrong JSON type, or a repeated pagination cursor. The body is never kept in the error |
| none | `MissingKeyException` (no API key configured), `ApiConnectionException`, `ApiTimeoutException` (no HTTP response) |
| none | `WaitTimeoutException` (`waitForMessage`) |

```php
use Tmailor\Exception\IpNotAllowedException;
use Tmailor\Exception\QuotaExceededException;

try {
    $box = $client->mailboxes->create();
} catch (QuotaExceededException) {
    // ...
} catch (IpNotAllowedException $e) {
    echo "Add this server's IP address on platform.tmailor.com ", $e->requestId, "\n";
}
```

`MailboxNotFoundException` is thrown when a token is invalid, was rotated on the platform, belongs to a deleted
address or another account, or was issued by the tmailor.com website (only tokens from the API or the platform
work). `CacheUnavailableException` on `delete()` with `secretChanged === true` means the address was deleted, but
old tokens may still work on tmailor.com for up to 10 minutes.

Exceptions are never chained to the underlying curl or JSON error, and their messages and stack traces never hold
the API key, an address token or a response body.

## Retries

Automatic retries (up to `maxRetries`, default 2) happen only when repeating the request is safe:

- `429 RateLimited`: the API did not process the request, so any request is retried after `Retry-After`
  (at least 1 second, unless it is longer than `maxRetryWait`). `DailyCapReached` is never retried.
- `500/502/503/504` and network errors: retried only for reads (`GET`, `info`, `messages`, `message`) and for
  `mailboxes->create()`. A failed connection (nothing sent) is retried for any request.
- `503 CacheUnavailable` on `delete()` is never retried.
- `message()` marks the message as read. Doing that twice has the same effect, so it is retried like a read.

`mailboxes->create()` always sends an `Idempotency-Key` (a new UUID, or yours via `idempotencyKey:`), and every
retry reuses it with the same body bytes, so a retry can never create a second address or use quota twice. When
there is no `Retry-After`, exponential backoff uses full jitter: the upper bound starts at 0.5 seconds, doubles on
each retry, and is capped at 8 seconds; the actual delay is between half the upper bound and the upper bound.

## Laravel

Register one client in a service provider and read the key from `config/services.php`
(`'tmailor' => ['key' => env('TMAILOR_API_KEY')]`):

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Tmailor\Client;

final class TmailorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static fn () => new Client(
            apiKey: config('services.tmailor.key'),
            timeout: 30,
        ));
    }
}
```

Then type-hint `Tmailor\Client` in a job, a command or a Dusk test. Keep it out of anything that renders to the
browser.

## Development

```bash
composer install
vendor/bin/phpunit                      # unit tests, local loopback HTTP tests and the shared conformance scenarios
vendor/bin/phpstan analyse              # level max, checked as PHP 8.1
TMAILOR_API_KEY=tmk_live_... vendor/bin/phpunit --testsuite live   # real API, from an allowlisted IP address
```

`tests/Conformance/ConformanceTest.php` replays the language-neutral scenarios in `conformance/scenarios/` (one
test per file). Every Tmailor SDK must pass the same scenarios; `TMAILOR_CONFORMANCE_DIR` overrides their location,
and a missing directory fails the run instead of skipping it. `tests/Unit/CurlTransportTest.php` starts
`php -S 127.0.0.1:<port>` and a small TCP server on loopback only to check how curl errors are mapped. The live
suite is never part of the default run; it creates its own addresses and deletes them.

Releases are git tags (`v0.1.0`); Packagist reads the version from the tag. `Tmailor\Client::VERSION` holds the
same version for the `User-Agent` header.

## Links

- [Tmailor](https://tmailor.com): free temporary email service
- [Tmailor Platform](https://platform.tmailor.com): API keys, IP allowlist, domains, and plans
- [API documentation](https://platform.tmailor.com/docs)
- [Issue tracker](https://github.com/tmailor-com/tmailor-php/issues)
- [Support](https://tmailor.com/contact-us)
- [Changelog](https://github.com/tmailor-com/tmailor-php/blob/main/CHANGELOG.md)

## License

MIT. See [LICENSE](https://github.com/tmailor-com/tmailor-php/blob/main/LICENSE).
