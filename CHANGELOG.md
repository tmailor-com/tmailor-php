# Changelog

## 0.1.0 (2026-10-20)

First version, for Open API spec 1.3.0. Ported from the Python SDK 0.2.0 (`tmailor-sdk`) with the same behaviour,
checked by the shared conformance scenarios (80 of 80 pass).

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
