<?php

declare(strict_types=1);

namespace Tmailor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tmailor\Client;
use Tmailor\Exception as E;
use Tmailor\Http\Request;
use Tmailor\Http\Response;
use Tmailor\Internal\Ops;
use Tmailor\Internal\Secret;
use Tmailor\Mailbox;
use Tmailor\Tests\Conformance\ConformanceTest;
use Tmailor\Tests\Support\CallbackTransport as T;
use Tmailor\Tests\Support\Fixtures as F;

/** The API key and address tokens never show up in dumps, JSON, string forms, errors or stack traces. */
final class SecretMaskingTest extends TestCase
{
    private static function dump(mixed $v): string
    {
        ob_start();
        var_dump($v);
        $d = (string) ob_get_clean();

        return $d . "\n" . print_r($v, true) . "\n" . var_export($v, true) . "\n" . json_encode($v) . "\n" . print_r((array) $v, true);
    }

    private static function assertClean(string $text): void
    {
        foreach (F::SECRETS as $s) {
            self::assertStringNotContainsString($s, $text);
        }
    }

    /** @return array{Client, Mailbox, E\MailboxNotFoundException} */
    private static function scenario(): array
    {
        $t = T::sequence([
            T::json(201, F::mailbox()),
            T::json(404, ['error' => ['code' => 'MailboxNotFound', 'message' => 'Mailbox not found', 'description' => '', 'request_id' => 'r']]),
        ]);
        $c = F::client($t);
        $box = $c->mailboxes->create();
        try {
            $box->info();
        } catch (E\MailboxNotFoundException $e) {
            // The test transport records the requests it saw (with the Authorization header); a real transport
            // keeps nothing, so drop them before dumping the client.
            $t->requests = [];

            return [$c, $box, $e];
        }
        self::fail('no MailboxNotFoundException');
    }

    public function testRedactionEverywhere(): void
    {
        [$c, $box, $e] = self::scenario();
        $texts = [
            self::dump($c),
            (string) $c,
            self::dump($box),
            (string) $box,
            self::dump($box->web),
            $e->getMessage(),
            $e->summary(),
            (string) $e,
            $e->getTraceAsString(),
            print_r($e->body, true),
            print_r($e->headers, true),
            print_r(ConformanceTest::sdkFrames($e), true),
        ];
        self::assertClean(implode("\n", $texts));
        self::assertStringContainsString('tmk_live_abcdefabcdef_***', (string) $c);
        self::assertStringContainsString('tmk_live_abcdefabcdef_***', print_r($c, true));
        // still usable
        self::assertSame(F::TOKEN, $box->token);
        $web = $box->web;
        self::assertNotNull($web);
        self::assertSame(F::TRANSFER, $web->transfertoken);
        self::assertSame('https://tmailor.com/r#accesstoken/' . F::TOKEN, $web->reuseUrl);
        self::assertSame(F::TOKEN, $web->accesstoken);
        self::assertStringContainsString('"token":"***"', (string) json_encode($box));
    }

    public function testSerializeIsRefused(): void
    {
        [$c, $box] = self::scenario();
        foreach ([$c, $box, $box->web, Secret::of(F::TOKEN), new Request('GET', 'https://x.test', ['Authorization' => 'Bearer ' . F::KEY], null)] as $v) {
            try {
                serialize($v);
                self::fail('serialized ' . get_debug_type($v));
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSecretStoreIsReleasedAndIdsAreNotMixedUp(): void
    {
        // CI run 39384425331: static store keyed by spl_object_id() (no WeakMap, PHP 8.1 segfault). A freed secret must
        // drop its entry, so a new object that reuses the id never sees the old value, and the store does not grow.
        $prop = new \ReflectionProperty(Secret::class, 'values');
        $before = \count((array) $prop->getValue());
        for ($i = 0; $i < 2000; ++$i) {
            $a = Secret::of('value-' . $i);
            self::assertSame('value-' . $i, $a->reveal());
            unset($a);
        }
        self::assertSame($before, \count((array) $prop->getValue()));
        $x = Secret::of('first');
        $id = spl_object_id($x);
        unset($x);
        $y = Secret::of('second');
        self::assertSame('second', $y->reveal(), 'id ' . $id . ' reused without the old value');
    }

    public function testSecretIsNotAProperty(): void
    {
        $s = Secret::of(F::TOKEN);
        self::assertSame(F::TOKEN, $s->reveal());
        self::assertTrue($s->equals(F::TOKEN));
        self::assertFalse($s->equals('other'));
        self::assertSame('***', (string) $s);
        self::assertClean(self::dump($s) . var_export((array) $s, true));
        self::assertSame([], get_object_vars($s));
    }

    public function testRequestAndResponseDumps(): void
    {
        $spec = Ops::mailboxInfo(Secret::of(F::TOKEN));
        $req = new Request('POST', 'https://x.test/v1/mailbox/info', ['Authorization' => 'Bearer ' . F::KEY, 'Accept' => 'application/json'], $spec->content?->reveal());
        self::assertClean(print_r($req, true) . self::dump($spec));
        ob_start();
        var_dump($req);
        self::assertClean((string) ob_get_clean());
        $resp = new Response(200, [], (string) json_encode(F::mailbox()));
        self::assertClean(print_r($resp, true));
    }

    public function testRequestAndResponseHideHeadersAndBody(): void
    {
        // Review 20b PHP-02: no public headers/body; every way of dumping them is masked, serialize() is refused.
        $req = new Request('POST', 'https://x.test/v1/mailbox/info', ['Authorization' => 'Bearer ' . F::KEY], '{"token":"' . F::TOKEN . '"}');
        $resp = new Response(200, ['X-Request-Id' => 'r'], (string) json_encode(F::mailbox()));
        foreach ([$req, $resp] as $v) {
            self::assertClean(self::dump($v) . var_export([$v], true) . json_encode([$v]) . print_r(get_object_vars($v), true));
            try {
                serialize($v);
                self::fail('serialized ' . $v::class);
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('Bearer ' . F::KEY, $req->header('authorization'));
        self::assertSame(['Authorization' => 'Bearer ' . F::KEY], $req->headers());
        self::assertSame('{"token":"' . F::TOKEN . '"}', $req->body());
        self::assertStringContainsString(F::TOKEN, $resp->body());
        self::assertSame('r', $resp->header('X-REQUEST-ID'));
        self::assertSame(['x-request-id' => 'r'], $resp->headers());
        $json = json_decode((string) json_encode($req), true);
        self::assertIsArray($json);
        self::assertSame(['Authorization' => '***'], $json['headers']);
        self::assertSame(['method', 'url'], array_keys(get_object_vars($req)));
        self::assertSame(['status', 'reasonPhrase'], array_keys(get_object_vars($resp)));
    }

    public function testArgumentErrorsHideTheKeyInTraces(): void
    {
        // The key is cleared from the constructor arguments before the base URL check can throw (PHP 8.1 has no
        // #[\SensitiveParameter]; on 8.2+ the attribute also redacts it).
        try {
            new Client(F::KEY, baseUrl: 'https://evil.example');
            self::fail('accepted');
        } catch (E\BaseUrlException $e) {
            self::assertClean((string) $e . print_r($e->getTrace()[0]['args'] ?? [], true) . $e->getMessage());
        }
    }

    public function testMissingTokenErrorHasNoSecret(): void
    {
        $t = T::sequence([T::json(200, ['items' => [F::mailbox(null)], 'next_cursor' => null])]);
        $c = F::client($t);
        foreach ($c->mailboxes->list() as $box) {
            try {
                $box->delete();
                self::fail('accepted');
            } catch (E\TmailorException $e) {
                self::assertSame('NoToken', $e->errorCode);
                self::assertClean((string) $e . print_r(ConformanceTest::sdkFrames($e), true));
            }
        }
    }

    public function testMessageDumpIsShort(): void
    {
        $t = T::sequence([T::json(200, F::item(['seen' => true, 'text' => str_repeat('x', 5000), 'html' => null, 'body_available' => true]))]);
        $m = F::client($t)->mailbox(F::TOKEN)->message('m');
        self::assertStringContainsString('<5000 bytes>', print_r($m, true));
    }
}
