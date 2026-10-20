<?php

// Pattern for an end-to-end sign-up test with PHPUnit: one address per test, always deleted afterwards.
// triggerSignup() stands for your own code that makes your application send the verification email.

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use Tmailor\Client;
use Tmailor\Exception\MailboxNotFoundException;
use Tmailor\Mailbox;

final class SignupTest extends TestCase
{
    private static ?Client $client = null;
    private ?Mailbox $inbox = null;

    protected function setUp(): void
    {
        if (getenv('TMAILOR_API_KEY') === false) {
            self::markTestSkipped('TMAILOR_API_KEY is not set');
        }
        self::$client ??= new Client();
        $this->inbox = self::$client->mailboxes->create();
    }

    protected function tearDown(): void
    {
        try {
            $this->inbox?->delete();
        } catch (MailboxNotFoundException) {
            // already deleted
        }
    }

    private function triggerSignup(string $email): void // replace with a call to your application
    {
        throw new \LogicException('not implemented: send the sign-up email to ' . $email);
    }

    public function testSignupSendsCode(): void
    {
        $inbox = $this->inbox;
        self::assertNotNull($inbox);
        $this->triggerSignup((string) $inbox->email);
        // since is exclusive (receivedAt > since): "- 1" keeps a message received in the creation second.
        $msg = $inbox->waitForMessage(timeout: 90, subjectContains: 'verify', since: ($inbox->createdAt ?? 0) - 1);
        self::assertNotNull($msg->otp, 'no code found in the message');
    }
}
