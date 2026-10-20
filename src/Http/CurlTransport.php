<?php

declare(strict_types=1);

namespace Tmailor\Http;

use Tmailor\Internal\BaseUrl;
use Tmailor\Internal\Sealed;

/**
 * Default transport, built on ext-curl. One curl handle is kept per transport so connections are reused.
 *
 * - The timeout is a total limit for the request (CURLOPT_TIMEOUT_MS), also used as the connect timeout.
 * - Redirects are never followed, TLS certificates and host names are always verified, only https is allowed
 *   (and http to a loopback host, for local development), and curl never writes a debug trace.
 * - Extra curl options are limited to an allowlist (proxy, CA bundle, client certificate, DNS / IP resolution,
 *   network interface, TCP and HTTP version settings): see {@see SAFE_OPTIONS}. Any other option is refused.
 * - curl errors map to {@see TransportException}: could not resolve or connect, or a failed TLS handshake =
 *   `connect`; a timeout = `timeout` (connect phase when nothing was sent yet); anything else (connection reset,
 *   empty reply, partial body, ...) = `read`.
 */
final class CurlTransport implements TransportInterface
{
    /**
     * Review 20b PHP-01: the only curl options a caller may set (option number => name). Options that could turn off
     * TLS verification, follow redirects, allow other protocols, print a debug trace (CURLOPT_VERBOSE writes the
     * Authorization header to STDERR) or replace the SDK's callbacks are not in the list. The numbers are fixed by
     * libcurl's ABI, so the list does not depend on which CURLOPT_* constants the local build defines. Aliases
     * (CURLOPT_SSLKEYPASSWD, CURLOPT_KEYPASSWD) share the number of CURLOPT_SSLCERTPASSWD.
     */
    public const SAFE_OPTIONS = [
        10004 => 'CURLOPT_PROXY',
        59 => 'CURLOPT_PROXYPORT',
        101 => 'CURLOPT_PROXYTYPE',
        10006 => 'CURLOPT_PROXYUSERPWD',
        10175 => 'CURLOPT_PROXYUSERNAME',
        10176 => 'CURLOPT_PROXYPASSWORD',
        111 => 'CURLOPT_PROXYAUTH',
        10177 => 'CURLOPT_NOPROXY',
        61 => 'CURLOPT_HTTPPROXYTUNNEL',
        10246 => 'CURLOPT_PROXY_CAINFO',
        10247 => 'CURLOPT_PROXY_CAPATH',
        10262 => 'CURLOPT_PRE_PROXY',
        10065 => 'CURLOPT_CAINFO',
        10097 => 'CURLOPT_CAPATH',
        40309 => 'CURLOPT_CAINFO_BLOB',
        10230 => 'CURLOPT_PINNEDPUBLICKEY',
        10169 => 'CURLOPT_CRLFILE',
        10025 => 'CURLOPT_SSLCERT',
        10086 => 'CURLOPT_SSLCERTTYPE',
        10026 => 'CURLOPT_SSLCERTPASSWD',
        10087 => 'CURLOPT_SSLKEY',
        10088 => 'CURLOPT_SSLKEYTYPE',
        32 => 'CURLOPT_SSLVERSION',
        10083 => 'CURLOPT_SSL_CIPHER_LIST',
        10276 => 'CURLOPT_TLS13_CIPHERS',
        113 => 'CURLOPT_IPRESOLVE',
        10203 => 'CURLOPT_RESOLVE',
        10243 => 'CURLOPT_CONNECT_TO',
        10211 => 'CURLOPT_DNS_SERVERS',
        92 => 'CURLOPT_DNS_CACHE_TIMEOUT',
        10062 => 'CURLOPT_INTERFACE',
        139 => 'CURLOPT_LOCALPORT',
        140 => 'CURLOPT_LOCALPORTRANGE',
        121 => 'CURLOPT_TCP_NODELAY',
        213 => 'CURLOPT_TCP_KEEPALIVE',
        214 => 'CURLOPT_TCP_KEEPIDLE',
        215 => 'CURLOPT_TCP_KEEPINTVL',
        84 => 'CURLOPT_HTTP_VERSION',
        98 => 'CURLOPT_BUFFERSIZE',
        71 => 'CURLOPT_MAXCONNECTS',
        75 => 'CURLOPT_FORBID_REUSE',
        74 => 'CURLOPT_FRESH_CONNECT',
    ];

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
    /** @var Sealed<array<int, mixed>> Review 20b PHP-01: options can hold proxy or key passwords; never dumped */
    private readonly Sealed $options;

    /**
     * @param array<int, mixed> $curlOptions extra curl_setopt() options from {@see SAFE_OPTIONS} (for example
     *                                       CURLOPT_PROXY or CURLOPT_CAINFO)
     *
     * @throws \InvalidArgumentException for an option that is not in the allowlist
     */
    public function __construct(#[\SensitiveParameter] array $curlOptions = [])
    {
        if (!\extension_loaded('curl')) {
            throw new \LogicException('The curl extension is required (ext-curl)');
        }
        $allowed = self::allowedOptions();
        foreach (array_keys($curlOptions) as $opt) {
            if (!isset($allowed[$opt])) {
                // Review 20b PHP-01: refused, not silently dropped (the caller would believe it is applied).
                throw new \InvalidArgumentException(sprintf(
                    'curl option %d is not allowed by Tmailor\Http\CurlTransport (see CurlTransport::SAFE_OPTIONS)',
                    $opt,
                ));
            }
        }
        $this->options = Sealed::of($curlOptions);
        $curlOptions = [];
    }

    /** @return array<int, string> option number => name of every option the constructor accepts */
    public static function allowedOptions(): array
    {
        return self::SAFE_OPTIONS;
    }

    public function send(Request $request, float $timeout): Response
    {
        // Review 20b PHP-01: https only; http only to a loopback host (local development).
        $scheme = strtolower((string) parse_url($request->url, \PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($request->url, \PHP_URL_HOST), '[]'));
        if ($scheme === 'http' && BaseUrl::isLoopback($host)) {
            $protocols = \CURLPROTO_HTTPS | \CURLPROTO_HTTP;
        } elseif ($scheme === 'https') {
            $protocols = \CURLPROTO_HTTPS;
        } else {
            throw new \InvalidArgumentException('Only https:// URLs (or http:// to a loopback host) can be sent');
        }

        $ch = $this->handle ??= curl_init();
        if ($ch === false) {
            throw TransportException::connect('Could not initialize curl');
        }
        curl_reset($ch);

        $headerLines = [];
        foreach ($request->headers() as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // No "Expect: 100-continue" round trip for larger bodies.
        $headerLines[] = 'Expect:';

        $responseHeaders = [];
        $reason = '';
        $ms = max(1, (int) ceil($timeout * 1000));
        $options = $this->options->value();
        $options[\CURLOPT_ENCODING] = '';
        $options[\CURLOPT_URL] = $request->url;
        $options[\CURLOPT_CUSTOMREQUEST] = $request->method;
        $options[\CURLOPT_HTTPHEADER] = $headerLines;
        $options[\CURLOPT_RETURNTRANSFER] = true;
        $options[\CURLOPT_TIMEOUT_MS] = $ms;
        $options[\CURLOPT_CONNECTTIMEOUT_MS] = $ms;
        $options[\CURLOPT_NOSIGNAL] = true;
        // Review 20b PHP-01: always enforced after the caller's options (the allowlist already keeps them out).
        $options[\CURLOPT_SSL_VERIFYPEER] = true;
        $options[\CURLOPT_SSL_VERIFYHOST] = 2;
        $options[\CURLOPT_FOLLOWLOCATION] = false;
        $options[\CURLOPT_VERBOSE] = false;
        $options[\CURLOPT_PROTOCOLS] = $protocols;
        $options[\CURLOPT_REDIR_PROTOCOLS] = \CURLPROTO_HTTPS;
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
        $body = $request->body();
        if ($body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        } elseif ($request->method === 'GET') {
            $options[\CURLOPT_HTTPGET] = true;
        }
        $body = null;
        curl_setopt_array($ch, $options);
        $options = [];

        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno !== 0 || !is_string($out)) {
            throw $this->error($ch, $errno);
        }
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);

        $map = [];
        foreach ($responseHeaders as [$k, $v]) {
            $lk = strtolower($k);
            $map[$lk] = isset($map[$lk]) ? $map[$lk] . ', ' . $v : $v;
        }

        return new Response($status, $map, $out, $reason);
    }

    private function error(\CurlHandle $ch, int $errno): TransportException
    {
        // curl_strerror() is a fixed text per error number: no host, URL or header ends up in the message.
        $text = sprintf('curl error %d: %s', $errno, (string) curl_strerror($errno));
        if ($errno === self::TIMEOUT_ERROR) {
            // Nothing was sent when the transfer never got past the connect / TLS phase.
            $connectPhase = curl_getinfo($ch, \CURLINFO_PRETRANSFER_TIME_T) === 0;

            return TransportException::timeout($text, $connectPhase, $errno);
        }
        if (in_array($errno, self::CONNECT_ERRORS, true)) {
            return TransportException::connect($text, $errno);
        }

        return TransportException::read($text, $errno);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $names = [];
        $allowed = self::allowedOptions();
        foreach (array_keys($this->options->value()) as $opt) {
            $names[] = $allowed[$opt] ?? (string) $opt;
        }

        return ['curlOptions' => $names];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A curl transport cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A curl transport cannot be unserialized');
    }
}
