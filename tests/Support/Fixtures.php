<?php

declare(strict_types=1);

namespace Tmailor\Tests\Support;

use Tmailor\Client;
use Tmailor\Http\Request;
use Tmailor\Http\Response;
use Tmailor\Http\TransportException;

/** Shared test data and client builders (fake secrets only). */
final class Fixtures
{
    public const KEY = 'tmk_live_abcdefabcdef_SUPERSECRETvalue000000000000000000000000000';
    public const TOKEN = 'eyJ.SECRET-ADDRESS-TOKEN.sig';
    public const TRANSFER = 'eyJ.SECRET-TRANSFER.sig';
    public const SECRETS = ['SUPERSECRET', 'SECRET-ADDRESS-TOKEN', 'SECRET-TRANSFER'];

    public static function client(CallbackTransport $t, ?FakeClock $clock = null, int $maxRetries = 2, float $timeout = 30.0): Client
    {
        return new Client(
            self::KEY,
            baseUrl: 'https://x.test',
            timeout: $timeout,
            maxRetries: $maxRetries,
            transport: $t,
            allowUntrustedBaseUrl: true,
            clock: $clock ?? new FakeClock(),
        );
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function mailbox(?string $token = self::TOKEN, array $extra = []): array
    {
        return $extra + [
            'email' => 'a@b.c',
            'domain' => 'b.c',
            'is_user_domain' => false,
            'custom_name' => false,
            'created_at' => 1,
            'token' => $token,
            'web' => $token === null ? null : [
                'accesstoken' => $token,
                'transfertoken' => self::TRANSFER,
                'reuse_url' => 'https://tmailor.com/r#accesstoken/' . $token,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function me(): array
    {
        return [
            'user_id' => 12,
            'key' => ['key_id' => 1, 'prefix' => 'abcdefabcdef'],
            'client_ip' => '203.0.113.10',
            'limits' => [
                'quota_total' => 1, 'quota_used' => 0, 'quota_remaining' => 1, 'mailbox_count' => 0,
                'rate_per_min' => 30, 'daily_req_cap' => 0, 'max_ip' => 1,
            ],
            'ip_allowlist' => ['203.0.113.10'],
            'subscriptions' => [['plan' => 'free', 'expires_at' => 0, 'period_end' => 0, 'quota' => 100, 'used' => 0]],
        ];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function item(array $extra = []): array
    {
        return $extra + [
            'id' => str_repeat('0', 31) . '2',
            'from' => ['name' => 'Shop', 'address' => 'no-reply@shop.example'],
            'to' => 'a@b.c',
            'subject' => 'Code',
            'received_at' => 1760342401,
            'expires_at' => 1760428801,
            'seen' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function emptyPage(): array
    {
        return ['items' => [], 'next_cursor' => null, 'total' => 0];
    }

    /**
     * A fake server on the fake clock: step i takes `delay` seconds; a smaller request timeout cuts it (timeout).
     * With `trickle`, the response arrives after `delay` whatever the timeout.
     *
     * @param list<array{0: float|int, 1: int, 2: array<array-key, mixed>}> $steps
     */
    public static function slowServer(FakeClock $clock, array $steps, bool $trickle = false): CallbackTransport
    {
        $i = 0;

        return new CallbackTransport(static function (Request $r, float $timeout) use (&$i, $steps, $clock, $trickle): Response {
            [$delay, $status, $body] = $steps[$i++] ?? throw new \LogicException('unexpected request');
            if (!$trickle && $delay > $timeout) {
                $clock->now += $timeout;

                throw TransportException::timeout('slow');
            }
            $clock->now += $delay;

            return CallbackTransport::json($status, $body);
        });
    }

    /** @return string path of the request URL */
    public static function path(Request $r): string
    {
        return (string) parse_url($r->url, \PHP_URL_PATH);
    }

    /** @return array<array-key, mixed> */
    public static function body(Request $r): array
    {
        $v = json_decode((string) $r->body, true, 512, \JSON_THROW_ON_ERROR);

        return is_array($v) ? $v : [];
    }
}
