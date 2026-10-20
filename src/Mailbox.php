<?php

declare(strict_types=1);

namespace Tmailor;

use Tmailor\Exception\RateLimitedException;
use Tmailor\Exception\WaitTimeoutException;
use Tmailor\Internal\Core;
use Tmailor\Internal\MailboxFields;
use Tmailor\Internal\Ops;
use Tmailor\Internal\Requester;
use Tmailor\Internal\Schema as S;
use Tmailor\Internal\Secret;
use Tmailor\Internal\WaitPlan;
use Tmailor\Model\Message;
use Tmailor\Model\MessagePage;
use Tmailor\Model\WebAccess;

/**
 * One address. Every call sends `token` in the JSON body (never in the URL or a header).
 *
 * The fields are read-only. They are null on a mailbox built with `$client->mailbox($token)` until `info()`.
 * `token` and `web` can be null when the API has no token for the address right now: get it again with
 * `$client->mailboxes->list()`. var_dump(), print_r(), var_export() and json_encode() never show the tokens.
 *
 * @property-read ?string                $email
 * @property-read ?string                $domain
 * @property-read ?bool                  $isUserDomain
 * @property-read ?bool                  $customName
 * @property-read ?int                   $createdAt
 * @property-read ?int                   $messageCount
 * @property-read ?string                $token
 * @property-read ?WebAccess             $web
 * @property-read array<string, mixed>   $raw
 */
final class Mailbox implements \JsonSerializable
{
    private const PROPERTIES = ['email', 'domain', 'isUserDomain', 'customName', 'createdAt', 'messageCount', 'token', 'web', 'raw'];

    private ?string $email = null;
    private ?string $domain = null;
    private ?bool $isUserDomain = null;
    private ?bool $customName = null;
    private ?int $createdAt = null;
    private ?int $messageCount = null;
    private ?WebAccess $web = null;
    private ?Secret $raw = null;

    /** @internal use `$client->mailbox($token)` or `$client->mailboxes` */
    public function __construct(
        private readonly Requester $requester,
        private ?Secret $token = null,
    ) {
    }

    /** @internal */
    public static function fromFields(Requester $requester, MailboxFields $f): self
    {
        $mb = new self($requester);
        $mb->setFields($f);

        return $mb;
    }

    private function setFields(MailboxFields $f): void
    {
        $this->email = $f->email;
        $this->domain = $f->domain;
        $this->isUserDomain = $f->isUserDomain;
        $this->customName = $f->customName;
        $this->createdAt = $f->createdAt;
        if ($f->messageCount !== null) {
            $this->messageCount = $f->messageCount;
        }
        // Always the value of the response, also when it is null (never keep an older token).
        $this->token = $f->token;
        $this->web = $f->web;
        $this->raw = $f->raw;
    }

    /**
     * Reload the address (messageCount, current tokens). Updates this object and returns it.
     *
     * @phpstan-impure
     */
    public function info(): self
    {
        $this->setFields($this->requester->call(Ops::mailboxInfo($this->token), static fn(mixed $d): MailboxFields => MailboxFields::fromApi($d)));

        return $this;
    }

    /** One page of messages (newest first) with `total` and `nextCursor`. Items have no body. */
    public function messagesPage(
        ?bool $unread = null,
        ?string $from = null,
        ?string $subject = null,
        \DateTimeInterface|int|float|null $since = null,
        ?int $pageSize = null,
        ?string $cursor = null,
    ): MessagePage {
        $spec = Ops::mailboxMessages($this->token, $unread, $from, $subject, $since, $pageSize, $cursor);

        return $this->requester->call($spec, static fn(mixed $d): MessagePage => MessagePage::fromApi($d));
    }

    /**
     * Messages of the last 24 hours, newest first, following the cursor.
     *
     * Filters run on the server: `unread` (only not seen), `from` (text in sender name or address), `subject`
     * (text in subject), `since` (unix seconds or DateTimeInterface; EXCLUSIVE: only messages with
     * receivedAt > since). `limit` caps the total number of messages yielded. Items have no body: use
     * `message($id)`. A cursor the API already returned throws InvalidResponseException instead of looping.
     *
     * @return \Generator<int, Message, mixed, void>
     */
    public function messages(
        ?bool $unread = null,
        ?string $from = null,
        ?string $subject = null,
        \DateTimeInterface|int|float|null $since = null,
        ?int $limit = null,
        ?int $pageSize = null,
    ): \Generator {
        $cursor = null;
        $seen = [];
        $n = 0;
        $size = $pageSize ?? ($limit !== null && $limit !== 0 ? min($limit, Ops::MAX_PAGE) : 50);
        while (true) {
            $page = $this->messagesPage($unread, $from, $subject, $since, $size, $cursor);
            foreach ($page->items as $m) {
                if ($limit !== null && $n >= $limit) {
                    return;
                }
                yield $m;
                ++$n;
            }
            if ($page->nextCursor === null || ($limit !== null && $n >= $limit)) {
                return;
            }
            Core::checkCursor($seen, $page->nextCursor);
            $cursor = $page->nextCursor;
        }
    }

    /**
     * Read one message with `text`, sanitized `html` and the `otp` / `codes` / `links` found by the API. This
     * marks the message as read (`seen` is true in the result and in later lists).
     */
    public function message(string|Message $messageId): Message
    {
        return $this->requester->call(Ops::mailboxMessage($this->token, self::id($messageId)), static fn(mixed $d): Message => Message::fromApiWithBody($d));
    }

    public function deleteMessage(string|Message $messageId): void
    {
        $this->requester->request(Ops::mailboxMessageDelete($this->token, self::id($messageId)));
    }

    /** Delete every message. Returns how many were deleted. */
    public function clear(): int
    {
        return $this->requester->call(
            Ops::mailboxClear($this->token),
            static fn (mixed $data): int => S::reqInt(S::obj($data, '$'), 'deleted', '$'),
        );
    }

    /** Delete the address like "Delete" on tmailor.com. Quota is not returned; all tokens stop working. */
    public function delete(): void
    {
        $this->requester->request(Ops::mailboxDelete($this->token));
    }

    /**
     * Poll until a NEW matching message arrives and return it with its body.
     *
     * "New" means not in the inbox when the wait started (the ids of ALL current messages are recorded first,
     * without filters). Pass `since` (unix seconds or DateTimeInterface) instead to accept every message received
     * strictly after it; to include a message that arrives in the same second the address was created use
     * `since: $box->createdAt - 1`. `from` and `subjectContains` filter the polls on the server
     * (case-insensitive substring).
     *
     * The delay grows from 1 s to 10 s (or is fixed with `pollInterval`, at least 0.5 s) and slows down when
     * X-RateLimit-Remaining gets low or the API answers 429. No sleep or request goes past `timeout` (seconds).
     * Throws WaitTimeoutException after `timeout`. The returned message is read with POST /v1/mailbox/message, so
     * it is marked as read.
     */
    public function waitForMessage(
        float|int $timeout = 120,
        ?string $from = null,
        ?string $subjectContains = null,
        float|int|null $pollInterval = null,
        \DateTimeInterface|int|float|null $since = null,
    ): Message {
        $clock = $this->requester->clock;
        $plan = new WaitPlan((float) $timeout, $pollInterval === null ? null : (float) $pollInterval, $clock->monotonic());
        $sinceTs = Ops::unixTime($since);
        $baseline = [];
        if ($sinceTs === null) {
            // The baseline lists every current message WITHOUT from/subject.
            $cursor = null;
            $seen = [];
            while (true) {
                $page = $this->waitPage($plan, null, null, null, $cursor);
                foreach ($page->items as $m) {
                    $baseline[$m->id] = true;
                }
                if ($page->nextCursor === null) {
                    break;
                }
                Core::checkCursor($seen, $page->nextCursor);
                $cursor = $page->nextCursor;
            }
        }
        while (true) {
            $page = $this->waitPage($plan, $from, $subjectContains, $sinceTs, null);
            $first = null;
            foreach ($page->items as $m) {
                if (isset($baseline[$m->id])) {
                    continue;
                }
                // The oldest new message: smallest (receivedAt, id), ids compared as plain strings.
                if ($first === null || $m->receivedAt < $first->receivedAt
                    || ($m->receivedAt === $first->receivedAt && strcmp($m->id, $first->id) < 0)) {
                    $first = $m;
                }
            }
            if ($first !== null) {
                $spec = Ops::mailboxMessage($this->token, $first->id);

                return $this->requester->call($spec, static fn(mixed $d): Message => Message::fromApiWithBody($d), $plan->deadline);
            }
            $delay = $plan->nextDelay($clock->monotonic(), $clock->wall(), $this->requester->lastRateLimit);
            if ($delay === null) {
                throw new WaitTimeoutException();
            }
            $clock->sleep($delay);
        }
    }

    /**
     * One page for waitForMessage: every sleep is bounded by the wait deadline. 429 RateLimited is not retried by
     * the transport loop (no fresh retry budget per poll); the plan sleeps Retry-After (at least 1 s) when it fits
     * before the deadline, otherwise the wait times out.
     */
    private function waitPage(WaitPlan $plan, ?string $from, ?string $subject, ?int $since, ?string $cursor): MessagePage
    {
        $clock = $this->requester->clock;
        while (true) {
            try {
                $spec = Ops::mailboxMessages($this->token, null, $from, $subject, $since, 100, $cursor);

                return $this->requester->call($spec, static fn(mixed $d): MessagePage => MessagePage::fromApi($d), $plan->deadline, false);
            } catch (RateLimitedException $e) {
                $retryAfter = $e->retryAfter;
            }
            $d = $plan->rateLimitDelay($clock->monotonic(), $retryAfter);
            if ($d === null) {
                throw new WaitTimeoutException();
            }
            $clock->sleep($d);
        }
    }

    private static function id(string|Message $m): string
    {
        return $m instanceof Message ? $m->id : $m;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'email' => $this->email,
            'domain' => $this->domain,
            'isUserDomain' => $this->isUserDomain,
            'customName' => $this->customName,
            'createdAt' => $this->createdAt,
            'messageCount' => $this->messageCount,
            'token' => $this->token?->reveal(),
            'web' => $this->web,
            'raw' => $this->rawArray(),
            default => throw new \LogicException('Undefined property ' . self::class . '::$' . $name),
        };
    }

    public function __isset(string $name): bool
    {
        return in_array($name, self::PROPERTIES, true) && $this->__get($name) !== null;
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \LogicException(self::class . ' properties are read-only');
    }

    /** @return array<string, mixed> */
    private function rawArray(): array
    {
        if ($this->raw === null) {
            return [];
        }
        $v = json_decode($this->raw->reveal(), true);
        $out = [];
        foreach (is_array($v) ? $v : [] as $k => $x) {
            $out[(string) $k] = $x;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'email' => $this->email,
            'domain' => $this->domain,
            'isUserDomain' => $this->isUserDomain,
            'customName' => $this->customName,
            'createdAt' => $this->createdAt,
            'messageCount' => $this->messageCount,
            'token' => $this->token === null ? null : '***',
            'web' => $this->web,
        ];
    }

    /** API field names; the tokens are masked. */
    public function jsonSerialize(): mixed
    {
        return [
            'email' => $this->email,
            'domain' => $this->domain,
            'is_user_domain' => $this->isUserDomain,
            'custom_name' => $this->customName,
            'created_at' => $this->createdAt,
            'message_count' => $this->messageCount,
            'token' => $this->token === null ? null : '***',
            'web' => $this->web,
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'Tmailor\Mailbox(email=%s, domain=%s, createdAt=%s, messageCount=%s, token=%s)',
            $this->email ?? 'null',
            $this->domain ?? 'null',
            $this->createdAt ?? 'null',
            $this->messageCount ?? 'null',
            $this->token === null ? 'null' : '***',
        );
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A Mailbox holds its token and cannot be serialized; store $box->token yourself');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Mailbox cannot be unserialized; use $client->mailbox($token)');
    }
}
