<?php

declare(strict_types=1);

namespace Tmailor\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmailor\Exception as E;
use Tmailor\Http\Response;
use Tmailor\Internal\Core;
use Tmailor\Internal\ErrorMap;
use Tmailor\Internal\Ops;
use Tmailor\Internal\RequestSpec;
use Tmailor\Internal\Secret;
use Tmailor\Internal\WaitPlan;
use Tmailor\RateLimitInfo;

final class CoreTest extends TestCase
{
    private static function get(): RequestSpec
    {
        return new RequestSpec('GET', '/v1/me', safe: true);
    }

    private static function post(): RequestSpec
    {
        return new RequestSpec('POST', '/v1/mailbox/delete', jsonBody: ['token' => 't']);
    }

    /** @return iterable<string, array{RequestSpec, E\TmailorException, bool, float|string|null}> */
    public static function retryRules(): iterable
    {
        yield '429 any request after Retry-After' => [self::post(), new E\RateLimitedException('x', 'RateLimited', 429, retryAfter: 7), false, 7.0];
        yield '429 Retry-After above max wait' => [self::post(), new E\RateLimitedException('x', 'RateLimited', 429, retryAfter: 61), false, null];
        yield 'DailyCapReached never' => [self::get(), new E\DailyCapReachedException('x', 'DailyCapReached', 429, retryAfter: 5), false, null];
        yield '500 unsafe not retried' => [self::post(), new E\InternalServerException('x', 'InternalError', 500), false, null];
        yield '500 safe backoff' => [self::get(), new E\InternalServerException('x', 'InternalError', 500), false, 'backoff'];
        yield 'CacheUnavailable never' => [self::get(), new E\CacheUnavailableException('x', 'CacheUnavailable', 503), false, null];
        yield 'network error unsafe' => [self::post(), new E\ApiConnectionException(), false, null];
        yield 'connect failed any request' => [self::post(), new E\ApiConnectionException(), true, 'backoff'];
        yield 'network error safe' => [self::get(), new E\ApiConnectionException(), false, 'backoff'];
        yield '400 never' => [self::get(), new E\TmailorException('x', 'InvalidField', 400), false, null];
    }

    #[DataProvider('retryRules')]
    public function testRetryDelayRules(RequestSpec $spec, E\TmailorException $err, bool $connect, float|string|null $expect): void
    {
        $d = Core::retryDelay($spec, 0, $err, 2, 60.0, $connect);
        if ($expect === 'backoff') {
            self::assertNotNull($d);
            self::assertGreaterThanOrEqual(0.25, $d);
            self::assertLessThanOrEqual(0.5, $d);
        } else {
            self::assertSame($expect, $d);
        }
    }

    public function testRetryDelayStopsAtMax(): void
    {
        $e = new E\RateLimitedException('x', 'RateLimited', 429, retryAfter: 1);
        self::assertNull(Core::retryDelay(self::get(), 2, $e, 2, 60.0));
        self::assertSame(1.0, Core::retryDelay(self::get(), 1, $e, 2, 60.0));
    }

    public function testBackoffBounds(): void
    {
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $ceiling = min(Core::BACKOFF_MAX, Core::BACKOFF_BASE * 2 ** $attempt);
            for ($i = 0; $i < 50; ++$i) {
                $d = Core::backoff($attempt);
                self::assertGreaterThanOrEqual($ceiling / 2, $d);
                self::assertLessThanOrEqual($ceiling, $d);
            }
        }
    }

    public function testRetryAfterHeaderBeatsBodyAndNegativeIsClamped(): void
    {
        self::assertSame(9, Core::retryAfterSeconds(new Response(429, ['Retry-After' => '9']), ['retry_after' => 3]));
        self::assertSame(3, Core::retryAfterSeconds(new Response(429), ['retry_after' => 3]));
        self::assertSame(0, Core::retryAfterSeconds(new Response(429, ['Retry-After' => '-4']), []));
        self::assertNull(Core::retryAfterSeconds(new Response(429, ['Retry-After' => 'soon']), ['retry_after' => true]));
        self::assertNull(Core::retryAfterSeconds(new Response(429, ['Retry-After' => '1.5']), ['retry_after' => '2']));
    }

    public function testRetryAfterZeroGetsMinDelay(): void
    {
        $e = new E\RateLimitedException('x', 'RateLimited', 429, retryAfter: 0);
        self::assertSame(Core::MIN_RETRY_AFTER, Core::retryDelay(self::get(), 0, $e, 2, 60.0));
        $e5 = new E\ServiceUnavailableException('x', 'HTTP503', 503, retryAfter: 0);
        self::assertSame(Core::MIN_RETRY_AFTER, Core::retryDelay(self::get(), 0, $e5, 2, 60.0));
        self::assertSame(Core::MIN_RETRY_AFTER, (new WaitPlan(10, null, 0.0))->rateLimitDelay(0.0, 0));
    }

    public function testWithinDeadline(): void
    {
        self::assertNull(Core::withinDeadline(null, 0, 10));
        self::assertSame(3.0, Core::withinDeadline(3.0, 0, null));
        self::assertSame(3.0, Core::withinDeadline(3.0, 6.0, 10));
        self::assertNull(Core::withinDeadline(4.0, 6.0, 10));
    }

    public function testRequestTimeout(): void
    {
        self::assertSame([30.0, false], Core::requestTimeout(30.0, 0.0, null));
        self::assertSame([30.0, false], Core::requestTimeout(30.0, 0.0, 100.0));
        self::assertSame([30.0, true], Core::requestTimeout(30.0, 0.0, 30.0));
        self::assertSame([2.5, true], Core::requestTimeout(30.0, 7.5, 10.0));
        $this->expectException(E\WaitTimeoutException::class);
        Core::requestTimeout(30.0, 10.0, 10.0);
    }

    public function testWaitPlanGrowthAndCap(): void
    {
        $p = new WaitPlan(1000, null, 0.0);
        $delays = [];
        for ($i = 0; $i < 8; ++$i) {
            $delays[] = $p->nextDelay(0.0, 0.0, null);
        }
        self::assertSame([1.0, 1.5, 2.25, 3.375], array_slice($delays, 0, 4));
        self::assertSame(10.0, max($delays));
    }

    public function testWaitPlanDeadline(): void
    {
        $p = new WaitPlan(5, 3, 0.0);
        self::assertSame(3.0, $p->nextDelay(0.0, 0.0, null));
        self::assertSame(1.0, $p->nextDelay(3.0, 3.0, null));
        self::assertNull($p->nextDelay(4.0, 4.0, null));
        self::assertNull($p->nextDelay(5.0, 5.0, null));
        self::assertNull($p->rateLimitDelay(3.5, 1));
        self::assertSame(2.0, $p->rateLimitDelay(0.0, 2));
        self::assertSame(4.0, $p->rateLimitDelay(0.0, 4));
        self::assertNull($p->rateLimitDelay(0.0, 5));
    }

    public function testWaitPlanSpreadsLowRemaining(): void
    {
        $p = new WaitPlan(1000, null, 0.0);
        // 4 left, keep 2 => 2 polls over 40.5 s
        self::assertEqualsWithDelta(40.5 / 2, $p->nextDelay(0.0, 0.0, new RateLimitInfo(30, 4, 40)), 1e-9);
        // at or below the reserve: wait for the reset (+0.5 s)
        $q = new WaitPlan(1000, null, 0.0);
        self::assertSame(20.5, $q->nextDelay(0.0, 0.0, new RateLimitInfo(30, 2, 20)));
        // the minimum poll interval is 0.5 s
        self::assertSame(0.5, (new WaitPlan(10, 0.1, 0.0))->nextDelay(0.0, 0.0, null));
    }

    public function testWaitPlanValidation(): void
    {
        try {
            new WaitPlan(-1, null, 0);
            self::fail('negative timeout accepted');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new WaitPlan(10, 0, 0);
    }

    public function testCheckCursor(): void
    {
        $seen = [];
        Core::checkCursor($seen, 'CURSOR-ONE');
        Core::checkCursor($seen, 'CURSOR-TWO');
        try {
            Core::checkCursor($seen, 'CURSOR-ONE');
            self::fail('repeated cursor accepted');
        } catch (E\InvalidResponseException $e) {
            self::assertNull($e->status);
            self::assertSame('InvalidResponse', $e->errorCode);
            self::assertStringNotContainsString('CURSOR-ONE', $e->getMessage());
        }
    }

    public function testIdempotencyKeyIsUuid4(): void
    {
        $a = Core::newIdempotencyKey();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
        self::assertNotSame($a, Core::newIdempotencyKey());
    }

    public function testMaskKey(): void
    {
        self::assertSame('tmk_live_abcdefabcdef_***', Core::maskKey(Secret::of('tmk_live_abcdefabcdef_SECRET')));
        self::assertSame('***', Core::maskKey(Secret::of('whatever')));
        self::assertNull(Core::maskKey(null));
    }

    public function testErrorMap(): void
    {
        self::assertSame(E\MailboxNotFoundException::class, ErrorMap::classFor('MailboxNotFound', 404));
        self::assertSame(E\BadRequestException::class, ErrorMap::classFor('SomethingNew', 400));
        self::assertSame(E\BadRequestException::class, ErrorMap::classFor(null, 413));
        self::assertSame(E\InternalServerException::class, ErrorMap::classFor(null, 502));
        self::assertSame(E\InternalServerException::class, ErrorMap::classFor('InternalError', 503));
        self::assertSame(E\ApiException::class, ErrorMap::classFor(null, 418));
        self::assertSame(E\ApiException::class, ErrorMap::classFor('', 418));
    }

    public function testBuildErrorFallbacks(): void
    {
        $e = Core::buildError(new Response(502, ['X-Request-Id' => 'rid', 'Set-Cookie' => 'a=b'], '<html>bad gateway</html>'));
        self::assertInstanceOf(E\InternalServerException::class, $e);
        self::assertSame('HTTP502', $e->errorCode);
        self::assertSame('HTTP 502 Bad Gateway', $e->getMessage());
        self::assertSame('rid', $e->requestId);
        self::assertSame(['x-request-id' => 'rid'], $e->headers);
        self::assertSame(502, $e->getCode());

        $e = Core::buildError(new Response(400, [], '{"error":{"code":"InvalidName","message":"m","request_id":"r","reason":"double_dot"}}'));
        self::assertInstanceOf(E\InvalidNameException::class, $e);
        self::assertSame('double_dot', $e->reason);
        self::assertSame('[InvalidName] HTTP 400 m (request_id=r)', $e->summary());

        $e = Core::buildError(new Response(503, [], '{"error":{"code":"CacheUnavailable","message":"m","secret_changed":true}}'));
        self::assertInstanceOf(E\CacheUnavailableException::class, $e);
        self::assertTrue($e->secretChanged);

        // "error" must be an object: an array or a string is ignored.
        $e = Core::buildError(new Response(404, [], '{"error":["MailboxNotFound"]}'));
        self::assertSame('HTTP404', $e->errorCode);
        self::assertInstanceOf(E\NotFoundException::class, $e);
    }

    public function testOpsTable(): void
    {
        $t = Secret::of('t');
        $cases = [
            [Ops::health(), 'GET', '/v1/health', true, false],
            [Ops::me(), 'GET', '/v1/me', true, true],
            [Ops::domains(), 'GET', '/v1/domains', true, true],
            [Ops::usage(), 'GET', '/v1/usage', true, true],
            [Ops::mailboxesCreate(), 'POST', '/v1/mailboxes', true, true],
            [Ops::mailboxesList(), 'GET', '/v1/mailboxes', true, true],
            [Ops::mailboxInfo($t), 'POST', '/v1/mailbox/info', true, true],
            [Ops::mailboxMessages($t), 'POST', '/v1/mailbox/messages', true, true],
            [Ops::mailboxMessage($t, 'm'), 'POST', '/v1/mailbox/message', true, true],
            [Ops::mailboxMessageDelete($t, 'm'), 'POST', '/v1/mailbox/message/delete', false, true],
            [Ops::mailboxClear($t), 'POST', '/v1/mailbox/messages/clear', false, true],
            [Ops::mailboxDelete($t), 'POST', '/v1/mailbox/delete', false, true],
        ];
        foreach ($cases as [$spec, $method, $path, $safe, $auth]) {
            self::assertSame($method, $spec->method, $path);
            self::assertSame($path, $spec->path);
            self::assertSame($safe, $spec->safe, $path);
            self::assertSame($auth, $spec->auth, $path);
        }
        self::assertSame(['limit' => 50], Ops::mailboxesList()->query);
        self::assertSame('{}', Ops::mailboxesCreate(idempotencyKey: 'k')->content?->reveal());
    }

    public function testInvalidOpArguments(): void
    {
        $bad = [
            static fn () => Ops::mailboxesCreate(idempotencyKey: 'has space'),
            static fn () => Ops::mailboxesCreate(idempotencyKey: str_repeat('x', 129)),
            static fn () => Ops::mailboxesCreate(idempotencyKey: ''),
            static fn () => Ops::mailboxesList(size: 101),
            static fn () => Ops::mailboxesList(size: 0),
            static fn () => Ops::mailboxMessage(Secret::of('t'), ''),
            static fn () => Ops::mailboxMessages(Secret::of('t'), since: \NAN),
        ];
        foreach ($bad as $i => $f) {
            try {
                $f();
                self::fail("case $i accepted");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSinceAndDatesConversion(): void
    {
        $aware = new \DateTimeImmutable('2026-10-17T00:00:00+00:00');
        $spec = Ops::mailboxMessages(Secret::of('t'), since: $aware);
        $body = json_decode($spec->content?->reveal() ?? '', true);
        self::assertIsArray($body);
        self::assertSame($aware->getTimestamp(), $body['since']);
        self::assertSame(1760342400, Ops::unixTime(1760342400.9));
        $u = Ops::usage(new \DateTimeImmutable('2026-10-01'), new \DateTime('2026-10-02 05:00:00'));
        self::assertSame(['from' => '2026-10-01', 'to' => '2026-10-02'], $u->query);
        self::assertSame(['from' => '2026-10-01'], Ops::usage('2026-10-01')->query);
    }
}
