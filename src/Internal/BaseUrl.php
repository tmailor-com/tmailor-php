<?php

declare(strict_types=1);

namespace Tmailor\Internal;

use Tmailor\Exception\BaseUrlException;

/**
 * Base URL validation. Every authenticated request sends the API key (Authorization) and address tokens (JSON
 * body) to this URL, so:
 *
 * - only `https://` is accepted, except `http://` to a loopback host (127.0.0.0/8, ::1, localhost) for local
 *   development, which also needs `allowUntrusted`;
 * - user info (`user:pass@`), a query string, a fragment, whitespace and control characters are refused;
 * - any host other than `open-api.tmailor.com` (port 443) needs `allowUntrusted`: the caller states that it
 *   trusts that host with the credentials.
 *
 * @internal
 */
final class BaseUrl
{
    public const DEFAULT = 'https://open-api.tmailor.com';
    public const TRUSTED_HOST = 'open-api.tmailor.com';
    private const LOOPBACK_NAMES = ['localhost', 'localhost.localdomain'];

    /** The base URL from the argument, TMAILOR_BASE_URL or the default, validated by {@see check()}. */
    public static function resolve(?string $baseUrl, bool $allowUntrusted): string
    {
        $url = $baseUrl;
        if ($url === null || $url === '') {
            $env = Env::get('TMAILOR_BASE_URL');
            $url = $env !== null && $env !== '' ? $env : self::DEFAULT;
        }

        return self::check($url, $allowUntrusted);
    }

    /**
     * Validate and normalize: returns `scheme://host[:port][/path]` (lower-case scheme and host, no trailing `/`).
     *
     * @throws BaseUrlException
     */
    public static function check(string $url, bool $allowUntrusted = false): string
    {
        $raw = trim($url);
        if ($raw === '') {
            throw new BaseUrlException('base_url is empty');
        }
        if (preg_match('/[\s\p{Z}\p{Cc}]/u', $raw) !== 0) { // also false (invalid UTF-8)
            throw new BaseUrlException('base_url must not contain spaces or control characters');
        }
        if (str_contains($raw, '?') || str_contains($raw, '#')) {
            throw new BaseUrlException('base_url must not contain a query string or a fragment');
        }
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*)://([^/]*)(/.*)?$#s', $raw, $m) !== 1) {
            throw new BaseUrlException('base_url must start with https://');
        }
        $scheme = strtolower($m[1]);
        $netloc = $m[2];
        $path = $m[3] ?? '';
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new BaseUrlException('base_url must start with https://');
        }
        if (str_contains($netloc, '@')) {
            throw new BaseUrlException('base_url must not contain user info (user:password@)');
        }
        if (str_starts_with($netloc, '[')) {
            if (preg_match('#^\[([^\[\]]*)\](?::(\d*))?$#', $netloc, $h) !== 1
                || filter_var($h[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false) {
                throw new BaseUrlException('base_url is not a valid URL');
            }
        } elseif (preg_match('#^([^:\[\]]*)(?::(\d*))?$#', $netloc, $h) !== 1) {
            throw new BaseUrlException('base_url is not a valid URL');
        }
        $host = strtolower($h[1]);
        $portText = $h[2] ?? '';
        $port = null;
        if ($portText !== '') {
            if (strlen($portText) > 5 || (int) $portText > 65535) {
                throw new BaseUrlException('base_url is not a valid URL');
            }
            $port = (int) $portText;
        }
        if ($host === '') {
            throw new BaseUrlException('base_url has no host');
        }
        $trusted = $scheme === 'https' && $host === self::TRUSTED_HOST && ($port === null || $port === 443);
        if ($scheme === 'http') {
            if (!self::isLoopback($host)) {
                throw new BaseUrlException('plain http:// is only allowed for a loopback host (local development); use https://');
            }
            if (!$allowUntrusted) {
                throw new BaseUrlException(
                    'http:// to a loopback host is a development setting: pass allowUntrustedBaseUrl: true'
                );
            }
        } elseif (!$trusted && !$allowUntrusted) {
            throw new BaseUrlException(sprintf(
                "base_url host '%s' is not %s: your API key and address tokens would be sent to it. "
                . 'Pass allowUntrustedBaseUrl: true only if you trust that host.',
                $host,
                self::TRUSTED_HOST,
            ));
        }
        $out = str_contains($host, ':') ? '[' . $host . ']' : $host;
        if ($port !== null) {
            $out .= ':' . $port;
        }

        return $scheme . '://' . $out . rtrim($path, '/');
    }

    public static function isLoopback(string $host): bool
    {
        if (in_array($host, self::LOOPBACK_NAMES, true)) {
            return true;
        }
        if (filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }
        if (filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            return inet_pton($host) === inet_pton('::1');
        }

        return false;
    }
}
