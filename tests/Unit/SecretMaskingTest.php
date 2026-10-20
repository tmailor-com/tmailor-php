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
