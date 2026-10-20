<?php

declare(strict_types=1);

namespace Tmailor\Http;

/**
 * Default transport, built on ext-curl. One curl handle is kept per transport so connections are reused.
 *
 * - The timeout is a total limit for the request (CURLOPT_TIMEOUT_MS), also used as the connect timeout.
 * - Redirects are not followed; only http and https are allowed.
 * - TLS certificates and host names are verified.
 * - curl errors map to {@see TransportException}: could not resolve or connect, or a failed TLS handshake =
 *   `connect`; a timeout = `timeout` (connect phase when nothing was sent yet); anything else (connection reset,
 *   empty reply, partial body, ...) = `read`.
 */
final class CurlTransport implements TransportInterface
{
    /** curl errors raised before anything was sent. */
    private const CONNECT_ERRORS = [
        5,   // CURLE_COULDNT_RESOLVE_PROXY
        6,   // CURLE_COULDNT_RESOLVE_HOST
        7,   // CURLE_COULDNT_CONNECT
        35,  // CURLE_SSL_CONNECT_ERROR
        51,  // CURLE_PEER_FAILED_VERIFICATION (older libcurl)
        58,  // CURLE_SSL_CERTPROBLEM
        59,  // CURLE_SSL_CIPHER
        60,  // CURLE_SSL_CACERT / PEER_FAILED_VERIFICATION
        77,  // CURLE_SSL_CACERT_BADFILE
        83,  // CURLE_SSL_ISSUER_ERROR
        90,  // CURLE_SSL_PINNEDPUBKEYNOTMATCH
        91,  // CURLE_SSL_INVALIDCERTSTATUS
    ];
    private const TIMEOUT_ERROR = 28; // CURLE_OPERATION_TIMEDOUT

    private ?\CurlHandle $handle = null;

    /**
     * @param array<int, mixed> $curlOptions extra curl_setopt() options (for example CURLOPT_PROXY or
     *                                       CURLOPT_CAINFO). They cannot turn on redirects or other protocols.
     */
    public function __construct(private readonly array $curlOptions = [])
    {
        if (!\extension_loaded('curl')) {
            throw new \LogicException('The curl extension is required (ext-curl)');
        }
    }

    public function send(Request $request, float $timeout): Response
    {
        $ch = $this->handle ??= curl_init();
        if ($ch === false) {
            throw TransportException::connect('Could not initialize curl');
        }
        curl_reset($ch);

        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // No "Expect: 100-continue" round trip for larger bodies.
        $headerLines[] = 'Expect:';

        $responseHeaders = [];
        $reason = '';
        $ms = max(1, (int) ceil($timeout * 1000));
        $options = $this->curlOptions + [
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_ENCODING => '',
        ];
        $options[\CURLOPT_URL] = $request->url;
        $options[\CURLOPT_CUSTOMREQUEST] = $request->method;
        $options[\CURLOPT_HTTPHEADER] = $headerLines;
        $options[\CURLOPT_RETURNTRANSFER] = true;
        $options[\CURLOPT_FOLLOWLOCATION] = false;
        $options[\CURLOPT_PROTOCOLS] = \CURLPROTO_HTTP | \CURLPROTO_HTTPS;
        $options[\CURLOPT_TIMEOUT_MS] = $ms;
        $options[\CURLOPT_CONNECTTIMEOUT_MS] = $ms;
        $options[\CURLOPT_NOSIGNAL] = true;
        $options[\CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$responseHeaders, &$reason): int {
            $len = strlen($line);
            $line = rtrim($line, "\r\n");
            if (preg_match('#^HTTP/\S+\s+\d{3}(?:\s+(.*))?$#', $line, $m) === 1) {
                // A new status line (100 Continue, then the final response): start over.
                $responseHeaders = [];
                $reason = trim($m[1] ?? '');
            } elseif ($line !== '' && str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[] = [trim($k), trim($v)];
            }

            return $len;
        };
        if ($request->body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $request->body;
        } elseif ($request->method === 'GET') {
            $options[\CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno !== 0 || !is_string($body)) {
            throw $this->error($ch, $errno);
        }
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);

        $map = [];
        foreach ($responseHeaders as [$k, $v]) {
            $lk = strtolower($k);
            $map[$lk] = isset($map[$lk]) ? $map[$lk] . ', ' . $v : $v;
        }

        return new Response($status, $map, $body, $reason);
    }

    private function error(\CurlHandle $ch, int $errno): TransportException
    {
        // curl_strerror() is a fixed text per error number: no host, URL or header ends up in the message.
        $text = sprintf('curl error %d: %s', $errno, (string) curl_strerror($errno));
        if ($errno === self::TIMEOUT_ERROR) {
            // Nothing was sent when the transfer never got past the connect / TLS phase.
            $connectPhase = curl_getinfo($ch, \CURLINFO_PRETRANSFER_TIME_T) === 0;

            return TransportException::timeout($text, $connectPhase);
        }
        if (in_array($errno, self::CONNECT_ERRORS, true)) {
            return TransportException::connect($text);
        }

        return TransportException::read($text);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['curlOptions' => array_keys($this->curlOptions)];
    }
}
