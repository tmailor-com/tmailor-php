# Changelog

## 0.1.0 (2026-10-20)

First version, for Open API spec 1.3.0. Ported from the Python SDK 0.2.0 (`tmailor-sdk`) with the same behaviour,
checked by the shared conformance scenarios (83 of 83 pass).

- `Tmailor\Client`: `health()`, `me()`, `domains()`, `usage()`, `mailboxes->create()/list()/listPage()`,
  `mailbox($token)`, `lastRateLimit()`. Options as named arguments: `apiKey` (default `TMAILOR_API_KEY`),
  `baseUrl`, `timeout`, `maxRetries`, `maxRetryWait`, `transport`, `allowUntrustedBaseUrl`, `clock`.
- `Tmailor\Mailbox`: `info()`, `messages()` (generator, follows the cursor), `messagesPage()`, `message()` (marks
  the message as read), `deleteMessage()`, `clear()`, `delete()`, `waitForMessage()`.
- `Message::$otp`, `$codes` and `$links` are the values found by the API (`otp: null` stays `null`). There is no
  local extractor: list items and responses without these fields give `null`, `[]` and `[]`.
- Typed exceptions for every API error code, network errors (`ApiConnectionException`, `ApiTimeoutException`),
  invalid 2xx bodies (`InvalidResponseException`) and wait timeouts (`WaitTimeoutException`).
- Automatic retries for 429, 5xx and network errors on safe requests; `Idempotency-Key` on create, reused with
  the same body bytes on every retry; Retry-After of 0 or less waits 1 second; `maxRetryWait`.
- `waitForMessage()` polls with a growing delay (1 s to 10 s) or a fixed `pollInterval`, slows down when
  X-RateLimit-Remaining is low, and keeps every sleep and HTTP request inside its `timeout`.
- Strict response validation: a missing field, a wrong JSON type (an object is never accepted for an array or the
  reverse) or `null` where the schema does not allow it is an `InvalidResponseException` that names the field only.
- The API key and address tokens never appear in `var_dump()`, `print_r()`, `var_export()`, `json_encode()`,
  string casts, exception messages or stack-trace arguments; `serialize()` of a client, mailbox or `WebAccess` is
  refused.
- Default `CurlTransport` (ext-curl): connection reuse, TLS verification, no redirects, curl errors mapped to
  connect / read / timeout. The timeout is a total limit for each request.

### Review 20b (2026-10-20, before the release)

- Conformance scenarios synced with the Python SDK: 83 files (`me_missing_ip_allowlist_enforced`,
  `redirect_3xx_is_error`, `mailbox_delete_redirect_is_error`; `ip_allowlist_enforced` added to the `me` bodies).
- PHP-01: `CurlTransport` accepts only the curl options of `CurlTransport::SAFE_OPTIONS` (proxy, CA bundle, client
  certificate, DNS / IP resolution, interface, TCP / HTTP version) and refuses any other with
  `InvalidArgumentException`. TLS peer and host verification, no redirects, no `CURLOPT_VERBOSE` and HTTPS-only
  protocols (HTTP to a loopback host) are always enforced. The options are no longer visible in dumps.
- PHP-02: `Http\Request` and `Http\Response` keep headers and body private: read them with `headers()`, `header()`
  and `body()`. `json_encode()`, `var_export()`, `(array)` casts and dumps show them masked; `serialize()` is refused.
- PHP-03: `ApiConnectionException` / `ApiTimeoutException` use a fixed message per kind (`Connection failed`,
  `Network error`, `Request timed out`), with at most the curl error number (`TransportException::$curlErrno`). The
  free-text message of a transport exception is never copied.
- PHP-04: only 2xx is a success. A 3xx (never followed), a 1xx or a status 0 raises `ApiException` with code
  `HTTP<status>` and is not retried, also for `delete()`, `deleteMessage()` and `clear()`.
- PHP-05: `Me::$ipAllowlistEnforced` (required boolean of spec 1.3.0); a response without it, or with a value that
  is not a boolean, is an `InvalidResponseException`.
