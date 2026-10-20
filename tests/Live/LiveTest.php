<?php

declare(strict_types=1);

namespace Tmailor\Tests\Live;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tmailor\Client;
use Tmailor\Exception as E;

/**
 * Live tests against the real API. Opt-in, never part of the default run:
 *
 *     TMAILOR_API_KEY=tmk_live_... vendor/bin/phpunit --testsuite live
 *
 * - TMAILOR_API_KEY            an API key whose allowlist contains this machine's public IP (required, else skipped)
 * - TMAILOR_BASE_URL           optional, default https://open-api.tmailor.com
 * - TMAILOR_LIVE_SEND_CMD      optional command that delivers ONE email to an address; `{to}` is replaced by the
 *                              (shell-quoted) address. Without it the receive part is skipped.
 * - TMAILOR_LIVE_EXPECT_OTP    optional code that the sent email contains (checked with $msg->otp).
 *
 * Each test creates its own addresses and deletes them at the end (deleting does not return quota).
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    protected function setUp(): void
    {
        $key = getenv('TMAILOR_API_KEY');
        if (!is_string($key) || trim($key) === '') {
            self::markTestSkipped('TMAILOR_API_KEY is not set');
        }
    }

    private static function send(string $to): void
    {
        $cmd = (string) getenv('TMAILOR_LIVE_SEND_CMD');
        exec(str_replace('{to}', escapeshellarg($to), $cmd) . ' > /dev/null 2>&1', $out, $code);
        self::assertSame(0, $code, 'TMAILOR_LIVE_SEND_CMD failed');
    }

    public function testFullFlow(): void
    {
        $client = new Client(maxRetries: 3);
        $me = $client->me();
        self::assertGreaterThan(0, $me->userId);
        self::assertNotNull($client->lastRateLimit()?->limit);
        self::assertGreaterThanOrEqual(0, count($client->domains()));
        self::assertSame('ok', $client->health()->status);
        self::assertNotSame('', $client->usage()->timezone);

        $box = $client->mailboxes->create();
        try {
            self::assertNotNull($box->email);
            self::assertNotNull($box->token);
            self::assertNotNull($box->web);
            self::assertStringNotContainsString($box->token, print_r($box, true));
            $same = $client->mailbox($box->token)->info();
            self::assertSame($box->email, $same->email);
            self::assertSame(0, $same->messageCount);

            $listed = [];
            foreach ($client->mailboxes->list(limit: 200) as $m) {
                if ($m->email === $box->email) {
                    $listed[] = $m;
                }
            }
            self::assertNotEmpty($listed);
            self::assertSame($box->token, $listed[0]->token);
            self::assertSame([], iterator_to_array($box->messages(), false));

            if ((string) getenv('TMAILOR_LIVE_SEND_CMD') !== '') {
                // Pattern 1: the email is triggered BEFORE waiting -> pass since (server time of the creation).
                self::send($box->email);
                $msg = $box->waitForMessage(timeout: 120, since: ($box->createdAt ?? 0) - 1);
                self::assertTrue($msg->bodyAvailable);
                self::assertTrue($msg->hasBody());
                self::assertSame($box->email, $msg->to);
                $expect = getenv('TMAILOR_LIVE_EXPECT_OTP');
                if (is_string($expect) && $expect !== '') {
                    self::assertSame($expect, $msg->otp);
                }
                // Reading a message marks it as read (waitForMessage reads it too).
                self::assertTrue($msg->seen);
                self::assertTrue($box->message($msg->id)->seen);
                $unread = array_map(static fn ($m) => $m->id, iterator_to_array($box->messages(unread: true), false));
                self::assertNotContains($msg->id, $unread);

                $box->deleteMessage($msg);
                try {
                    $box->message($msg->id);
                    self::fail('message still there');
                } catch (E\MessageNotFoundException) {
                    self::addToAssertionCount(1);
                }
                self::assertSame(0, $box->clear());
                self::assertSame([], iterator_to_array($box->messages(), false));
            }
            self::assertSame($same->email, $box->info()->email);
        } finally {
            $box->delete();
        }
        try {
            $box->info();
            self::fail('deleted address still answers');
        } catch (E\MailboxNotFoundException) {
            self::addToAssertionCount(1);
        }
    }

    public function testErrors(): void
    {
        $client = new Client();
        try {
            $client->mailbox('not-a-real-token')->info();
            self::fail('accepted');
        } catch (E\MailboxNotFoundException) {
            self::addToAssertionCount(1);
        }
        try {
            $client->mailboxes->create(domain: 'example.com');
            self::fail('accepted');
        } catch (E\InvalidDomainException $e) {
            self::assertSame(400, $e->status);
            self::assertNotNull($e->requestId);
        }
        try {
            (new Client('tmk_live_aaaaaaaaaaaa_' . str_repeat('b', 43)))->me();
            self::fail('accepted');
        } catch (E\InvalidKeyException) {
            self::addToAssertionCount(1);
        }
        // health is public; a client without a key works for it and refuses the rest locally.
        $anon = new Client('');
        self::assertSame('ok', $anon->health()->status);
        try {
            $anon->me();
            self::fail('accepted');
        } catch (E\MissingKeyException $e) {
            self::assertNull($e->status);
        }
    }
}
