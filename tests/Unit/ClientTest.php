<?php

declare(strict_types=1);

namespace Tmailor\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmailor\Client;
use Tmailor\Exception as E;
use Tmailor\Http\Request;
use Tmailor\Http\Response;
use Tmailor\Http\TransportException;
use Tmailor\Internal\Schema;
use Tmailor\Internal\SchemaException;
use Tmailor\Mailbox;
use Tmailor\Model\Me;
use Tmailor\Model\Message;
use Tmailor\Tests\Conformance\ConformanceTest;
use Tmailor\Tests\Support\CallbackTransport as T;
use Tmailor\Tests\Support\FakeClock;
use Tmailor\Tests\Support\Fixtures as F;

final class ClientTest extends TestCase
{
    private static function healthOk(): Response
    {
        return T::json(200, ['status' => 'ok', 'time' => 1, 'version' => 'v1']);
    }

    public function testApiKeyFromEnvironment(): void
    {
        $prev = getenv('TMAILOR_API_KEY');
        try {
            putenv('TMAILOR_API_KEY= ' . F::KEY . ' ');
            $t = T::sequence([T::json(200, F::me())]);
            $c = new Client(baseUrl: 'https://x.test', transport: $t, allowUntrustedBaseUrl: true);
            $c->me();
            self::assertSame('Bearer ' . F::KEY, $t->requests[0]->header('Authorization'));
        } finally {
            putenv($prev === false ? 'TMAILOR_API_KEY' : 'TMAILOR_API_KEY=' . $prev);
        }
    }

    public function testMissingApiKeyIsDeferred(): void
    {
        $prev = getenv('TMAILOR_API_KEY');
        putenv('TMAILOR_API_KEY');
        try {
            $t = new T(static fn (): Response => self::healthOk());
            $c = new Client('  ', baseUrl: 'https://x.test', transport: $t, allowUntrustedBaseUrl: true);
            self::assertStringContainsString('apiKey=null', (string) $c);
            self::assertSame('ok', $c->health()->status);
            self::assertCount(1, $t->requests);
            self::assertNull($t->requests[0]->header('Authorization'));
            foreach ([static fn () => $c->me(), static fn () => $c->mailboxes->create(), static fn () => $c->usage()] as $f) {
                try {
                    $f();
                    self::fail('no MissingKeyException');
                } catch (E\MissingKeyException $e) {
                    self::assertSame('MissingKey', $e->errorCode);
                    self::assertNull($e->status);
                }
            }
            self::assertCount(1, $t->requests);
        } finally {
            putenv($prev === false ? 'TMAILOR_API_KEY' : 'TMAILOR_API_KEY=' . $prev);
        }
    }

    public function testHealthNeverSendsKey(): void
    {
        $t = new T(static fn (): Response => self::healthOk());
        F::client($t)->health();
        self::assertNull($t->requests[0]->header('Authorization'));
        self::assertSame('GET', $t->requests[0]->method);
        self::assertNull($t->requests[0]->body);
    }

    public function testUserAgentAndHeaders(): void
    {
        $t = new T(static fn (): Response => self::healthOk());
        try {
            F::client($t)->me(); // the health body is not a Me: rejected (headers are still captured)
            self::fail('no InvalidResponseException');
        } catch (E\InvalidResponseException) {
        }
        $r = $t->requests[0];
        self::assertStringStartsWith('tmailor-php/' . Client::VERSION . ' (php ', (string) $r->header('User-Agent'));
        self::assertSame('Bearer ' . F::KEY, $r->header('Authorization'));
        self::assertSame('application/json', $r->header('Accept'));
        self::assertSame('https://x.test/v1/me', $r->url);
    }

    public function testInvalidClientArguments(): void
    {
        $cases = [
            static fn () => new Client(F::KEY, maxRetries: -1),
            static fn () => new Client(F::KEY, timeout: 0),
            static fn () => new Client(F::KEY, timeout: \INF),
            static fn () => new Client(F::KEY, maxRetryWait: -1),
            static fn () => (new Client(F::KEY))->mailbox(' '),
        ];
        foreach ($cases as $i => $f) {
            try {
                $f();
                self::fail("case $i accepted");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $c = new Client(F::KEY, timeout: 5, maxRetries: 0, maxRetryWait: 10);
        self::assertSame([5.0, 0, 10.0], [$c->timeout, $c->maxRetries, $c->maxRetryWait]);
    }

    public function testNoTokenMailbox(): void
    {
        $t = T::sequence([T::json(200, ['items' => [F::mailbox(null)], 'next_cursor' => null])]);
        $boxes = iterator_to_array(F::client($t)->mailboxes->list(), false);
        self::assertCount(1, $boxes);
        self::assertNull($boxes[0]->token);
        self::assertNull($boxes[0]->web);
        try {
            $boxes[0]->info();
            self::fail('no NoToken error');
        } catch (E\TmailorException $e) {
            self::assertSame('NoToken', $e->errorCode);
        }
        self::assertCount(1, $t->requests);
    }

    public function testMailboxFieldsAndRefresh(): void
    {
        $t = T::sequence([
            T::json(201, F::mailbox(extra: ['message_count' => 0])),
            T::json(200, F::mailbox('eyJ.NEW.sig', ['message_count' => 3])),
            T::json(200, F::mailbox(null)),
        ]);
        $c = F::client($t);
        $box = $c->mailboxes->create(domain: 'b.c', name: 'a', idempotencyKey: 'k-1');
        self::assertSame(['domain' => 'b.c', 'name' => 'a'], F::body($t->requests[0]));
        self::assertSame('k-1', $t->requests[0]->header('Idempotency-Key'));
        self::assertSame(['a@b.c', 'b.c', false, false, 1, 0], [$box->email, $box->domain, $box->isUserDomain, $box->customName, $box->createdAt, $box->messageCount]);
        self::assertSame(F::TOKEN, $box->token);
        self::assertSame(F::TRANSFER, $box->web?->transfertoken);
        self::assertSame(F::TOKEN, $box->raw['token'] ?? null);
        self::assertTrue(isset($box->email));
        self::assertSame($box, $box->info());
        self::assertSame(['token' => F::TOKEN], F::body($t->requests[1]));
        self::assertSame('eyJ.NEW.sig', $box->token);
        self::assertSame(3, $box->messageCount);
        // message_count absent: the last known value is kept; token null: never keep the older token.
        $box->info();
        self::assertSame(3, $box->messageCount);
        self::assertNull($box->token);
        self::assertFalse(isset($box->token));
        try {
            $box->__set('email', 'x'); // the runtime guard of the read-only magic properties
            self::fail('write accepted');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
    }

    public function testClientMailboxMakesNoRequest(): void
    {
        $t = T::sequence([]);
        $box = F::client($t)->mailbox('  ' . F::TOKEN . ' ');
        self::assertSame(F::TOKEN, $box->token);
        self::assertNull($box->email);
        self::assertSame([], $t->requests);
    }

    public function testNonJsonSuccessRaises(): void
    {
        $t = T::sequence([new Response(200, ['X-Request-Id' => 'rid'], '<html>')]);
        try {
            F::client($t)->me();
            self::fail('accepted');
        } catch (E\InvalidResponseException $e) {
            self::assertSame(['InvalidResponse', 200, 'rid'], [$e->errorCode, $e->status, $e->requestId]);
            self::assertNull($e->getPrevious());
        }
    }

    public function testRequestIdFromHeaderWhenBodyMissing(): void
    {
        $t = T::sequence([new Response(418, ['X-Request-Id' => 'abc'], '')]);
        try {
            F::client($t)->me();
            self::fail('accepted');
        } catch (E\ApiException $e) {
            self::assertSame(['abc', 'HTTP418', 418], [$e->requestId, $e->errorCode, $e->status]);
            self::assertSame(E\ApiException::class, $e::class);
        }
    }

    public function testMailboxesIteratorAndPage(): void
    {
        $page = ['items' => [F::mailbox('t1')], 'next_cursor' => null];
        $t = T::sequence([T::json(200, $page), T::json(200, $page)]);
        $c = F::client($t);
        $emails = [];
        foreach ($c->mailboxes as $mb) {
            $emails[] = $mb->email;
        }
        self::assertSame(['a@b.c'], $emails);
        $p = $c->mailboxes->listPage(pageSize: 100);
        self::assertNull($p->nextCursor);
        self::assertSame('t1', $p->items[0]->token);
        self::assertStringContainsString('limit=100', $t->requests[1]->url);
        self::assertStringNotContainsString('t1', $t->requests[1]->url);
    }

    public function testListLimitSetsThePageSize(): void
    {
        $t = T::sequence([T::json(200, ['items' => [F::mailbox('t1'), F::mailbox('t2')], 'next_cursor' => 'c2'])]);
        $got = iterator_to_array(F::client($t)->mailboxes->list(limit: 1), false);
        self::assertCount(1, $got);
        self::assertSame('https://x.test/v1/mailboxes?limit=1', $t->requests[0]->url);
    }

    public function testMessagesFiltersInBody(): void
    {
        $t = T::sequence([T::json(200, F::emptyPage())]);
        $since = new \DateTimeImmutable('@1760342000');
        $list = iterator_to_array(F::client($t)->mailbox(F::TOKEN)->messages(unread: false, from: 'shop', subject: '', since: $since, limit: 5), false);
        self::assertSame([], $list);
        self::assertSame(['token' => F::TOKEN, 'unread' => false, 'from' => 'shop', 'since' => 1760342000, 'limit' => 5], F::body($t->requests[0]));
        self::assertSame('https://x.test/v1/mailbox/messages', $t->requests[0]->url);
    }

    public function testModelsStrictOk(): void
    {
        $me = Me::fromApi(json_decode((string) json_encode(F::me())));
        self::assertSame(12, $me->userId);
        self::assertSame(1, $me->limits->maxIp);
        self::assertSame(100, $me->subscriptions[0]->quota);
        self::assertSame('abcdefabcdef', $me->key->prefix);
        self::assertSame(['203.0.113.10'], $me->ipAllowlist);
        self::assertSame(12, $me->raw['user_id']);
    }

    /** @return iterable<string, array{list<string>, mixed}> */
    public static function badMe(): iterable
    {
        $drop = new \stdClass();
        yield 'int as string' => [['user_id'], '12'];
        yield 'bool is not int' => [['user_id'], true];
        yield 'float is not int' => [['user_id'], 1.0];
        yield 'not nullable' => [['user_id'], null];
        yield 'required' => [['key'], $drop];
        yield 'nested type' => [['limits', 'max_ip'], 'x'];
        yield 'list null' => [['ip_allowlist'], null];
        yield 'list of int' => [['ip_allowlist'], [1]];
        yield 'object instead of list' => [['ip_allowlist'], ['a' => '203.0.113.10']];
        yield 'empty object instead of list' => [['subscriptions'], $drop];
        yield 'partial item' => [['subscriptions'], [['plan' => 'free']]];
        yield 'list instead of object' => [['limits'], [1, 2]];
        yield 'empty list instead of object' => [['key'], []];
    }

    /** @param list<string> $path */
    #[DataProvider('badMe')]
    public function testModelsStrictReject(array $path, mixed $value): void
    {
        $json = json_encode(F::me(), \JSON_THROW_ON_ERROR);
        $d = json_decode($json);
        self::assertInstanceOf(\stdClass::class, $d);
        $cur = $d;
        foreach (array_slice($path, 0, -1) as $k) {
            $cur = $cur->{$k};
            self::assertInstanceOf(\stdClass::class, $cur);
        }
        $last = $path[count($path) - 1];
        if ($value instanceof \stdClass && $last === 'key') {
            unset($cur->{$last});
        } elseif ($value instanceof \stdClass) {
            $cur->{$last} = new \stdClass(); // {} where an array is expected
        } else {
            // Encode then decode so arrays become JSON objects or lists exactly as on the wire.
            $cur->{$last} = json_decode(json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
        }
        try {
            Me::fromApi($d);
            self::fail('accepted');
        } catch (SchemaException $e) {
            self::assertStringContainsString($last, $e->getMessage());
            if (is_string($value)) {
                self::assertStringNotContainsString($value, str_replace($last, '', $e->getMessage()));
            }
        }
    }

    public function testObjectVersusArrayTrap(): void
    {
        // `{}` decoded with assoc=false is a stdClass, never an array; `[]` is never an object.
        $page = json_decode('{"items":{},"next_cursor":null,"total":0}');
        $this->expectException(SchemaException::class);
        \Tmailor\Model\MessagePage::fromApi($page);
    }

    public function testEmptyObjectIsNotAnEmptyMailboxWeb(): void
    {
        $t = T::sequence([new Response(201, [], (string) json_encode(F::mailbox(extra: ['web' => []])))]);
        $this->expectException(E\InvalidResponseException::class);
        F::client($t)->mailboxes->create();
    }

    public function testToArrayKeepsNesting(): void
    {
        $o = json_decode('{"a":{"b":[1,{"c":null}]},"e":{}}');
        self::assertInstanceOf(\stdClass::class, $o);
        self::assertSame(['a' => ['b' => [1, ['c' => null]]], 'e' => []], Schema::toArray($o));
    }

    public function testInvalid2xxBodyNeverKeptInError(): void
    {
        $t = T::sequence([T::json(200, F::mailbox(extra: ['created_at' => 'yesterday']), ['X-Request-Id' => 'rid-x'])]);
        try {
            F::client($t, maxRetries: 0)->mailbox(F::TOKEN)->info();
            self::fail('accepted');
        } catch (E\InvalidResponseException $e) {
            self::assertSame(['InvalidResponse', 200, 'rid-x'], [$e->errorCode, $e->status, $e->requestId]);
            self::assertStringContainsString('created_at', $e->getMessage());
            self::assertSame([], $e->body);
            self::assertNull($e->getPrevious());
            $rendered = (string) $e . print_r(ConformanceTest::sdkFrames($e), true);
            self::assertStringNotContainsString('yesterday', $rendered);
            foreach (F::SECRETS as $s) {
                self::assertStringNotContainsString($s, $rendered);
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badMailboxes(): iterable
    {
        yield 'token without web' => [F::mailbox('eyJ.TOKEN-A.sig', ['web' => null])];
        yield 'web without token' => [F::mailbox(null, ['web' => ['accesstoken' => 'a', 'transfertoken' => 'b', 'reuse_url' => 'c']])];
        yield 'token differs from web' => [F::mailbox('eyJ.TOKEN-A.sig', ['web' => ['accesstoken' => 'eyJ.OTHER.sig', 'transfertoken' => 'b', 'reuse_url' => 'c']])];
        yield 'message_count null' => [F::mailbox('eyJ.TOKEN-A.sig', ['message_count' => null])];
        yield 'string false' => [F::mailbox('eyJ.TOKEN-A.sig', ['is_user_domain' => 'false'])];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('badMailboxes')]
    public function testMailboxSchemaRelations(array $body): void
    {
        $t = T::sequence([T::json(201, $body)]);
        $this->expectException(E\InvalidResponseException::class);
        F::client($t)->mailboxes->create();
    }

    /** @return iterable<string, array{int}> */
    public static function statuses(): iterable
    {
        yield '200' => [200];
        yield '201' => [201];
    }

    #[DataProvider('statuses')]
    public function testMalformedSuccessJsonHasNoChain(int $status): void
    {
        $cut = '{"email":"a@b.c","api_key":"' . F::KEY . '","token":"' . F::TOKEN . '","web":{"transfertoken":"' . F::TRANSFER . '"';
        $t = T::sequence([new Response($status, ['X-Request-Id' => 'rid-cut'], $cut)]);
        $c = F::client($t);
        try {
            $status === 201 ? $c->mailboxes->create() : $c->mailbox(F::TOKEN)->info();
            self::fail('accepted');
        } catch (E\InvalidResponseException $e) {
            self::assertSame(['InvalidResponse', $status, 'rid-cut'], [$e->errorCode, $e->status, $e->requestId]);
            self::assertNull($e->getPrevious());
            $texts = implode("\n", [(string) $e, $e->summary(), print_r($e->body, true), print_r($e->headers, true), print_r(ConformanceTest::sdkFrames($e), true)]);
            foreach (F::SECRETS as $s) {
                self::assertStringNotContainsString($s, $texts);
            }
        }
    }

    public function testNetworkErrorHasNoChain(): void
    {
        $t = T::sequence([TransportException::read('boom')]);
        try {
            F::client($t, maxRetries: 0)->mailbox(F::TOKEN)->delete();
            self::fail('accepted');
        } catch (E\ApiConnectionException $e) {
            self::assertNull($e->getPrevious());
            self::assertNull($e->status);
            self::assertSame('ConnectionError', $e->errorCode);
            self::assertSame('Network error (boom)', $e->getMessage());
        }
    }

    public function testConnectTimeoutIsRetriedForAnyRequest(): void
    {
        $clock = new FakeClock();
        $t = T::sequence([TransportException::timeout('t', connectPhase: true), new Response(200, [], '')]);
        F::client($t, $clock)->mailbox(F::TOKEN)->delete();
        self::assertCount(2, $t->requests);
        self::assertCount(1, $clock->sleeps);
        // A read timeout of an unsafe request is not retried.
        $t = T::sequence([TransportException::timeout('t')]);
        $this->expectException(E\ApiTimeoutException::class);
        F::client($t, new FakeClock())->mailbox(F::TOKEN)->delete();
    }

    public function testRetrySendsTheSameBytes(): void
    {
        $clock = new FakeClock();
        $t = T::sequence([
            TransportException::connect('refused'),
            T::json(503, ['error' => ['code' => 'IdempotencyUnavailable', 'message' => 'm']]),
            T::json(201, F::mailbox()),
        ]);
        F::client($t, $clock)->mailboxes->create(domain: 'b.c');
        self::assertCount(3, $t->requests);
        self::assertSame($t->requests[0]->body, $t->requests[2]->body);
        self::assertSame($t->requests[0]->header('Idempotency-Key'), $t->requests[2]->header('Idempotency-Key'));
        self::assertCount(2, $clock->sleeps);
    }

    /** @return iterable<string, array{float, int, int, float}> */
    public static function wait429(): iterable
    {
        yield 'Retry-After beyond the deadline' => [5, 30, 1, 0.0];
        yield 'Retry-After 0' => [3, 0, 3, 2.0];
        yield 'negative Retry-After' => [10, -5, 10, 9.0];
    }

    #[DataProvider('wait429')]
    public function testWait429Bounded(float $timeout, int $retryAfter, int $maxCalls, float $maxSlept): void
    {
        $clock = new FakeClock();
        $t = new T(static fn (): Response => T::json(
            429,
            ['error' => ['code' => 'RateLimited', 'message' => 'm', 'description' => '', 'request_id' => 'r', 'retry_after' => $retryAfter]],
            ['Retry-After' => (string) $retryAfter],
        ));
        try {
            F::client($t, $clock)->mailbox(F::TOKEN)->waitForMessage(timeout: $timeout, since: 1);
            self::fail('no WaitTimeoutException');
        } catch (E\WaitTimeoutException $e) {
            self::assertSame('WaitTimeout', $e->errorCode);
        }
        self::assertLessThanOrEqual($maxCalls, count($t->requests));
        self::assertLessThanOrEqual($maxSlept, array_sum($clock->sleeps));
        foreach ($clock->sleeps as $s) {
            self::assertGreaterThan(0, $s);
        }
        self::assertLessThanOrEqual($timeout, $clock->now);
    }

    public function testWait5xxRetryNeverPassesDeadline(): void
    {
        $clock = new FakeClock();
        $t = new T(static fn (): Response => T::json(503, ['error' => ['code' => 'HTTP503', 'message' => 'm']], ['Retry-After' => '30']));
        try {
            F::client($t, $clock)->mailbox(F::TOKEN)->waitForMessage(timeout: 5, since: 1);
            self::fail('accepted');
        } catch (E\ServiceUnavailableException) {
            self::addToAssertionCount(1);
        }
        self::assertCount(1, $t->requests);
        self::assertSame([], $clock->sleeps); // a 30 s retry does not fit in 5 s: raised, not slept
    }

    /** @return iterable<string, array{float, list<array{0: float|int, 1: int, 2: array<array-key, mixed>}>, list<float>, list<string>}> */
    public static function slowCases(): iterable
    {
        yield 'slow poll cut at the deadline' => [3, [[10, 200, F::emptyPage()]], [3.0], ['/v1/mailbox/messages']];
        yield 'slow final read cut at the deadline' => [
            5,
            [[2, 200, ['items' => [F::item()], 'next_cursor' => null, 'total' => 1]], [10, 200, []]],
            [5.0, 3.0],
            ['/v1/mailbox/messages', '/v1/mailbox/message'],
        ];
        yield 'timeout 0 sends nothing' => [0, [], [], []];
    }

    /**
     * @param list<array{0: float|int, 1: int, 2: array<array-key, mixed>}> $steps
     * @param list<float>                                                   $timeouts
     * @param list<string>                                                  $paths
     */
    #[DataProvider('slowCases')]
    public function testWaitDeadlineBoundsRequests(float $timeout, array $steps, array $timeouts, array $paths): void
    {
        $clock = new FakeClock();
        $t = F::slowServer($clock, $steps);
        try {
            F::client($t, $clock)->mailbox(F::TOKEN)->waitForMessage(timeout: $timeout, since: 1);
            self::fail('no WaitTimeoutException');
        } catch (E\WaitTimeoutException $e) {
            self::assertNull($e->getPrevious());
        }
        self::assertSame($paths, array_map(F::path(...), $t->requests));
        self::assertCount(count($timeouts), $t->timeouts);
        foreach ($timeouts as $i => $want) {
            self::assertEqualsWithDelta($want, $t->timeouts[$i], 1e-9);
        }
        self::assertLessThanOrEqual($timeout + 1e-9, $clock->now);
    }

    public function testWaitUsesClientTimeoutWhenDeadlineIsFar(): void
    {
        $ok = ['items' => [F::item()], 'next_cursor' => null, 'total' => 1];
        $full = F::item(['seen' => true, 'text' => 'Your code is 123456', 'html' => null, 'body_available' => true, 'otp' => '123456', 'codes' => ['123456'], 'links' => []]);
        $clock = new FakeClock();
        $t = F::slowServer($clock, [[31, 200, $ok], [1, 200, $ok], [1, 200, $full]]);
        $msg = F::client($t, $clock, timeout: 30)->mailbox(F::TOKEN)->waitForMessage(timeout: 120, since: 1);
        self::assertSame('123456', $msg->otp);
        self::assertSame(30.0, $t->timeouts[0]); // cut by the client timeout: ApiTimeout, retried (safe list)
        self::assertCount(3, $t->requests);
        self::assertLessThanOrEqual(120, $clock->now);
    }

    public function testSlowRequestOutsideWaitIsApiTimeout(): void
    {
        $clock = new FakeClock();
        $t = F::slowServer($clock, [[31, 200, []]]);
        try {
            F::client($t, $clock, maxRetries: 0, timeout: 30)->mailbox(F::TOKEN)->info();
            self::fail('accepted');
        } catch (E\ApiTimeoutException $e) {
            self::assertSame('Timeout', $e->errorCode);
        }
        self::assertSame([30.0], $t->timeouts);
    }

    public function testWaitTrickleAfterDeadlineIsWaitTimeout(): void
    {
        $clock = new FakeClock();
        $t = F::slowServer($clock, [[10, 200, ['items' => [F::item()], 'next_cursor' => null, 'total' => 1]]], trickle: true);
        try {
            F::client($t, $clock)->mailbox(F::TOKEN)->waitForMessage(timeout: 3, since: 1);
            self::fail('accepted');
        } catch (E\WaitTimeoutException) {
            self::addToAssertionCount(1);
        }
        // The page that ended after the deadline is not used (no read of the message follows).
        self::assertSame(['/v1/mailbox/messages'], array_map(F::path(...), $t->requests));
        self::assertSame([3.0], $t->timeouts);
    }

    public function testWaitTimeoutEqualToClientTimeoutIsWaitTimeout(): void
    {
        $clock = new FakeClock();
        $t = F::slowServer($clock, [[40, 200, F::emptyPage()]]);
        try {
            F::client($t, $clock, timeout: 30)->mailbox(F::TOKEN)->waitForMessage(timeout: 30, since: 1);
            self::fail('accepted');
        } catch (E\WaitTimeoutException) {
            self::addToAssertionCount(1);
        }
        self::assertSame([30.0], $t->timeouts);
        self::assertSame(30.0, $clock->now);
    }

    public function testWaitPicksTheOldestNewMessage(): void
    {
        $a = F::item(['id' => 'b', 'received_at' => 10]);
        $b = F::item(['id' => 'a', 'received_at' => 10]);
        $c = F::item(['id' => '0', 'received_at' => 11]);
        $full = F::item(['id' => 'a', 'received_at' => 10, 'seen' => true, 'text' => 't', 'html' => null, 'body_available' => true]);
        $t = T::sequence([
            T::json(200, ['items' => [$c, $a, $b], 'next_cursor' => null, 'total' => 3]),
            T::json(200, $full),
        ]);
        $msg = F::client($t)->mailbox(F::TOKEN)->waitForMessage(since: 1, pollInterval: 1);
        self::assertSame('a', $msg->id);
        self::assertSame(['token' => F::TOKEN, 'message_id' => 'a'], F::body($t->requests[1]));
        // Fields missing on an older server: no otp, no codes, no links.
        self::assertNull($msg->otp);
        self::assertSame([], $msg->codes);
        self::assertSame([], $msg->links);
        self::assertTrue($msg->hasBody());
    }

    public function testWaitValidation(): void
    {
        $box = F::client(T::sequence([]))->mailbox(F::TOKEN);
        try {
            $box->waitForMessage(timeout: -1);
            self::fail('accepted');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $box->waitForMessage(pollInterval: 0);
    }

    public function testReadMessageMarksItAsRead(): void
    {
        $body = F::item(['seen' => true, 'text' => 'Code 482913', 'html' => null, 'body_available' => true, 'otp' => '482913', 'codes' => ['482913'], 'links' => []]);
        $t = new T(static fn (): Response => T::json(200, $body));
        $box = F::client($t)->mailbox(F::TOKEN);
        $m = $box->message(str_repeat('0', 31) . '2');
        self::assertTrue($m->seen);
        self::assertSame('482913', $m->otp);
        self::assertSame(['/v1/mailbox/message'], array_map(F::path(...), $t->requests));
        // a Message object is accepted too
        $box->message($m);
        $box->deleteMessage($m);
        self::assertSame(['token' => F::TOKEN, 'message_id' => $m->id], F::body($t->requests[2]));
    }

    public function testClearReturnsTheCount(): void
    {
        $t = T::sequence([T::json(200, ['deleted' => 4])]);
        self::assertSame(4, F::client($t)->mailbox(F::TOKEN)->clear());
    }

    public function testListItemsHaveNoCodes(): void
    {
        $t = T::sequence([T::json(200, ['items' => [F::item(['otp' => '1', 'text' => 'x'])], 'next_cursor' => null, 'total' => 1])]);
        $items = iterator_to_array(F::client($t)->mailbox(F::TOKEN)->messages(), false);
        self::assertInstanceOf(Message::class, $items[0]);
        self::assertNull($items[0]->otp);
        self::assertNull($items[0]->text);
        self::assertFalse($items[0]->hasBody());
        self::assertSame('Shop', $items[0]->from->name);
    }

    public function testRemovedOperationsAreGone(): void
    {
        // Removed from the Open API on 2026-10-17 (domain check, token rotation, mark as seen); no local extractor.
        $absent = [
            [Client::class, 'domainCheck'],
            [Mailbox::class, 'rotateToken'],
            [Mailbox::class, 'markSeen'],
            [Message::class, 'extractOtp'],
            [Message::class, 'otp'],
        ];
        foreach ($absent as [$class, $method]) {
            self::assertFalse((new \ReflectionClass($class))->hasMethod($method), "$class::$method");
        }
        $names = array_map(static fn (string $f): string => basename($f, '.php'), glob(\dirname(__DIR__, 2) . '/src/*/*.php') ?: []);
        self::assertNotContains('DomainCheck', $names);
        self::assertNotContains('Extract', $names);
    }

    public function testNameUsedOnWebsiteIsMailboxExists(): void
    {
        $msg = 'This address was created on the website or mobile app and cannot be used through the API. Pick another name';
        $t = T::sequence([T::json(409, ['error' => ['code' => 'MailboxExists', 'message' => $msg, 'description' => 'd', 'request_id' => 'r']])]);
        try {
            F::client($t)->mailboxes->create(domain: 'mail.example.com', name: 'signup');
            self::fail('accepted');
        } catch (E\MailboxExistsException $e) {
            self::assertInstanceOf(E\ConflictException::class, $e);
            self::assertSame(409, $e->status);
            self::assertSame($msg, $e->getMessage());
            self::assertSame('d', $e->description);
        }
    }

    public function testRateLimitInfoIsExposed(): void
    {
        $t = T::sequence([T::json(200, F::me(), ['X-RateLimit-Limit' => '120', 'X-RateLimit-Remaining' => '7', 'X-RateLimit-Reset' => '1760342460'])]);
        self::assertNull(F::client(T::sequence([]))->lastRateLimit());
        $c = F::client($t);
        $c->me();
        $rl = $c->lastRateLimit();
        self::assertNotNull($rl);
        self::assertSame([120, 7, 1760342460], [$rl->limit, $rl->remaining, $rl->reset]);
    }

    public function testDomainsAndUsage(): void
    {
        $t = T::sequence([
            T::json(200, ['items' => [['domain' => 'm.example', 'is_user_domain' => true, 'allow_custom_name' => true, 'status' => 'verified', 'verified' => true, 'usable' => true]], 'shared_domains_available' => true]),
            T::json(200, ['from' => '2026-10-01', 'to' => '2026-10-02', 'timezone' => 'Asia/Ho_Chi_Minh', 'days' => []]),
        ]);
        $c = F::client($t);
        $d = $c->domains();
        self::assertCount(1, $d);
        foreach ($d as $dom) {
            self::assertSame('m.example', $dom->domain);
        }
        self::assertTrue($d->sharedDomainsAvailable);
        $u = $c->usage(from: '2026-10-01', to: new \DateTimeImmutable('2026-10-02'));
        self::assertSame('Asia/Ho_Chi_Minh', $u->timezone);
        self::assertSame('https://x.test/v1/usage?from=2026-10-01&to=2026-10-02', $t->requests[1]->url);
    }

    public function testCustomTransportOtherExceptionsPropagate(): void
    {
        $t = new T(static function (Request $r): Response {
            throw new \DomainException('bug in the transport');
        });
        $this->expectException(\DomainException::class);
        F::client($t)->health();
    }
}
