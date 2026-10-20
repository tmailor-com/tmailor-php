<?php

declare(strict_types=1);

namespace Tmailor\Internal;

use Tmailor\Exception\TmailorException;

/**
 * Request builders: one function per API operation, returning a RequestSpec. Same table as every Tmailor SDK
 * (paths, methods, bodies, safety flags).
 *
 * @internal
 */
final class Ops
{
    public const MAX_PAGE = 100;

    public static function date(\DateTimeInterface|string|null $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        return $v;
    }

    /** `since`: a DateTimeInterface or unix seconds (a float is truncated to whole seconds). */
    public static function unixTime(\DateTimeInterface|int|float|null $v): ?int
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->getTimestamp();
        }
        if (is_float($v) && !is_finite($v)) {
            throw new \InvalidArgumentException('since must be a finite unix time');
        }

        return (int) $v;
    }

    public static function pageSize(?int $v): int
    {
        $n = $v ?? 50;
        if ($n < 1 || $n > self::MAX_PAGE) {
            throw new \InvalidArgumentException('pageSize must be between 1 and ' . self::MAX_PAGE);
        }

        return $n;
    }

    private static function token(?Secret $token): string
    {
        if ($token === null || $token->reveal() === '') {
            throw new TmailorException(
                'This mailbox has no token (tokens can be temporarily unavailable). Get it again with $client->mailboxes->list()',
                'NoToken',
            );
        }

        return $token->reveal();
    }

    private static function messageId(string $id): string
    {
        if ($id === '') {
            throw new \InvalidArgumentException('messageId is required');
        }

        return $id;
    }

    /** Public endpoint, sent without Authorization (works without an API key). */
    public static function health(): RequestSpec
    {
        return new RequestSpec('GET', '/v1/health', safe: true, auth: false);
    }

    public static function me(): RequestSpec
    {
        return new RequestSpec('GET', '/v1/me', safe: true);
    }

    public static function domains(): RequestSpec
    {
        return new RequestSpec('GET', '/v1/domains', safe: true);
    }

    public static function usage(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null): RequestSpec
    {
        return new RequestSpec('GET', '/v1/usage', ['from' => self::date($from), 'to' => self::date($to)], safe: true);
    }

    public static function mailboxesCreate(?string $domain = null, ?string $name = null, ?string $idempotencyKey = null): RequestSpec
    {
        $body = [];
        if ($domain !== null) {
            $body['domain'] = $domain;
        }
        if ($name !== null) {
            $body['name'] = $name;
        }
        $key = $idempotencyKey ?? Core::newIdempotencyKey();
        if (preg_match('/^[\x21-\x7E]{1,128}$/', $key) !== 1) {
            throw new \InvalidArgumentException('idempotencyKey must be 1-128 visible ASCII characters');
        }

        // Safe to retry: the server returns the first result for the same key + same body.
        return new RequestSpec('POST', '/v1/mailboxes', jsonBody: $body, safe: true, headers: ['Idempotency-Key' => $key]);
    }

    public static function mailboxesList(?string $cursor = null, ?int $size = null): RequestSpec
    {
        return new RequestSpec('GET', '/v1/mailboxes', ['limit' => self::pageSize($size), 'cursor' => $cursor], safe: true);
    }

    // One address: POST + token in the JSON body (never in the URL or a header).

    public static function mailboxInfo(?Secret $token): RequestSpec
    {
        return new RequestSpec('POST', '/v1/mailbox/info', jsonBody: ['token' => self::token($token)], safe: true);
    }

    public static function mailboxMessages(
        ?Secret $token,
        ?bool $unread = null,
        ?string $from = null,
        ?string $subject = null,
        \DateTimeInterface|int|float|null $since = null,
        ?int $size = null,
        ?string $cursor = null,
    ): RequestSpec {
        $body = ['token' => self::token($token)];
        if ($unread !== null) {
            $body['unread'] = $unread;
        }
        if ($from !== null && $from !== '') {
            $body['from'] = $from;
        }
        if ($subject !== null && $subject !== '') {
            $body['subject'] = $subject;
        }
        $s = self::unixTime($since);
        if ($s !== null) {
            $body['since'] = $s;
        }
        $body['limit'] = self::pageSize($size);
        if ($cursor !== null && $cursor !== '') {
            $body['cursor'] = $cursor;
        }

        return new RequestSpec('POST', '/v1/mailbox/messages', jsonBody: $body, safe: true);
    }

    /** The server marks the message as read; that is idempotent, so the request stays safe to retry. */
    public static function mailboxMessage(?Secret $token, string $messageId): RequestSpec
    {
        $body = ['token' => self::token($token), 'message_id' => self::messageId($messageId)];

        return new RequestSpec('POST', '/v1/mailbox/message', jsonBody: $body, safe: true);
    }

    public static function mailboxMessageDelete(?Secret $token, string $messageId): RequestSpec
    {
        $body = ['token' => self::token($token), 'message_id' => self::messageId($messageId)];

        return new RequestSpec('POST', '/v1/mailbox/message/delete', jsonBody: $body);
    }

    public static function mailboxClear(?Secret $token): RequestSpec
    {
        return new RequestSpec('POST', '/v1/mailbox/messages/clear', jsonBody: ['token' => self::token($token)]);
    }

    public static function mailboxDelete(?Secret $token): RequestSpec
    {
        return new RequestSpec('POST', '/v1/mailbox/delete', jsonBody: ['token' => self::token($token)]);
    }
}
