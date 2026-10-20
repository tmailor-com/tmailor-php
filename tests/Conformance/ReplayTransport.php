<?php

declare(strict_types=1);

namespace Tmailor\Tests\Conformance;

use PHPUnit\Framework\Assert;
use Tmailor\Http\Request;
use Tmailor\Http\Response;
use Tmailor\Http\TransportException;
use Tmailor\Http\TransportInterface;
use Tmailor\Tests\Support\FakeClock;

/**
 * Checks each request against the next exchange of a conformance scenario and returns its canned response.
 * Format: conformance/README.md.
 */
final class ReplayTransport implements TransportInterface
{
    private int $i = 0;
    /** @var array<string, string> */
    private array $captures = [];

    /**
     * @param list<array<array-key, mixed>> $exchanges     decoded as associative arrays (for the checks)
     * @param list<mixed>                $responseJson  `exchanges[i].response.json` decoded as objects, so `{}` and `[]`
     *                                                  are encoded back exactly
     */
    public function __construct(
        private readonly string $sid,
        private readonly array $exchanges,
        private readonly array $responseJson,
        private readonly FakeClock $clock,
    ) {
    }

    public function send(Request $request, float $timeout): Response
    {
        Assert::assertLessThan(
            count($this->exchanges),
            $this->i,
            sprintf('%s: unexpected extra request %s %s', $this->sid, $request->method, $request->url),
        );
        $ex = $this->exchanges[$this->i];
        $raw = $this->responseJson[$this->i];
        ++$this->i;
        $n = $this->i;
        $exp = self::arr($ex['request'] ?? null);
        $where = $this->sid . '#' . $n;

        Assert::assertSame($exp['method'], $request->method, "$where: method");
        $parts = parse_url($request->url);
        Assert::assertIsArray($parts, "$where: url");
        Assert::assertSame($exp['path'], $parts['path'] ?? '', "$where: path");
        $query = self::parseQuery($parts['query'] ?? '');
        $expQuery = self::arr($exp['query'] ?? []);
        ksort($query);
        ksort($expQuery);
        Assert::assertSame($expQuery, $query, "$where: query");
        // A token must never appear in the URL.
        Assert::assertArrayNotHasKey('token', $query, "$where: token in query string");
        Assert::assertStringNotContainsString('conformance-token', $request->url, "$where: token in URL");

        foreach (self::arr($exp['headers'] ?? []) as $name => $matcher) {
            $this->checkHeader($where, (string) $name, $request->header((string) $name), $matcher);
        }

        if (($exp['json'] ?? null) === null) {
            Assert::assertTrue($request->body() === null || $request->body() === '', "$where: expected no body");
        } else {
            Assert::assertNotNull($request->body(), "$where: expected a JSON body");
            Assert::assertStringStartsWith('{', $request->body(), "$where: body must be a JSON object");
            $got = json_decode($request->body(), true, 512, \JSON_THROW_ON_ERROR);
            Assert::assertEquals($exp['json'], $got, "$where: body");
            Assert::assertSame(self::canonical($exp['json']), self::canonical($got), "$where: body (types)");
        }

        $resp = self::arr($ex['response'] ?? null);
        // "delay" = seconds the server takes. The fake clock advances by it, or by the request's timeout (raising a
        // read timeout) when the SDK gave the request less time than that.
        $delay = $resp['delay'] ?? null;
        if (is_int($delay) || is_float($delay)) {
            if ($delay > $timeout) {
                $this->clock->now += $timeout;

                throw TransportException::timeout('read timed out');
            }
            $this->clock->now += $delay;
        }
        if (isset($resp['network_error'])) {
            return match ($resp['network_error']) {
                'connect' => throw TransportException::connect('connection refused'),
                'timeout' => throw TransportException::timeout('read timed out'),
                default => throw TransportException::read('connection reset'),
            };
        }
        $headers = [];
        foreach (self::arr($resp['headers'] ?? []) as $k => $v) {
            $headers[(string) $k] = is_scalar($v) ? (string) $v : '';
        }
        if (array_key_exists('json', $resp)) {
            $body = json_encode($raw, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES);
            $hasType = false;
            foreach ($headers as $k => $_) {
                $hasType = $hasType || strcasecmp($k, 'Content-Type') === 0;
            }
            if (!$hasType) {
                $headers['Content-Type'] = 'application/json';
            }
        } else {
            $b = $resp['body'] ?? '';
            $body = is_scalar($b) ? (string) $b : '';
        }
        $status = $resp['status'] ?? 0;
        Assert::assertIsInt($status);

        return new Response($status, $headers, $body);
    }

    private function checkHeader(string $where, string $name, ?string $got, mixed $m): void
    {
        $where .= ': header ' . $name;
        if (is_string($m)) {
            Assert::assertSame($m, $got, $where);

            return;
        }
        $m = self::arr($m);
        if (!empty($m['$absent'])) {
            Assert::assertNull($got, "$where must be absent");
        } elseif (!empty($m['$present'])) {
            Assert::assertNotEmpty($got, "$where must be present");
        } elseif (isset($m['$regex']) && is_string($m['$regex'])) {
            Assert::assertNotNull($got, "$where must be present");
            $re = '~' . str_replace('~', '\~', $m['$regex']) . '~';
            Assert::assertSame(1, preg_match($re, $got), "$where: '$got' !~ {$m['$regex']}");
        } elseif (isset($m['$capture']) && is_string($m['$capture'])) {
            Assert::assertNotEmpty($got, "$where must be present");
            $this->captures[$m['$capture']] = (string) $got;
        } elseif (isset($m['$equals']) && is_string($m['$equals'])) {
            Assert::assertArrayHasKey($m['$equals'], $this->captures, "$where: nothing captured");
            Assert::assertSame($this->captures[$m['$equals']], $got, "$where: differs from captured value");
        } else {
            Assert::fail("$where: unknown matcher");
        }
    }

    public function assertDone(): void
    {
        Assert::assertSame(count($this->exchanges), $this->i, sprintf('%s: %d exchange(s) not used', $this->sid, count($this->exchanges) - $this->i));
    }

    /** @return array<string, string> */
    private static function parseQuery(string $q): array
    {
        $out = [];
        if ($q === '') {
            return $out;
        }
        foreach (explode('&', $q) as $pair) {
            $kv = explode('=', $pair, 2);
            $out[urldecode($kv[0])] = urldecode($kv[1] ?? '');
        }

        return $out;
    }

    /** JSON with sorted keys: same structure AND same scalar types (1 is not "1", true is not 1). */
    private static function canonical(mixed $v): string
    {
        $sort = static function (mixed $x) use (&$sort): mixed {
            if (is_array($x)) {
                if (!array_is_list($x)) {
                    ksort($x);
                }

                return array_map($sort, $x);
            }

            return $x;
        };

        return json_encode($sort($v), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<array-key, mixed> */
    private static function arr(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }
}
