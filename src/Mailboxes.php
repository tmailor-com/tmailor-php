<?php

declare(strict_types=1);

namespace Tmailor;

use Tmailor\Internal\Core;
use Tmailor\Internal\MailboxFields;
use Tmailor\Internal\Ops;
use Tmailor\Internal\Requester;
use Tmailor\Internal\Schema as S;

/**
 * `$client->mailboxes`: create and list addresses. Iterating it yields every address of the account.
 *
 * @implements \IteratorAggregate<int, Mailbox>
 */
final class Mailboxes implements \IteratorAggregate
{
    /** @internal */
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * Create an address (uses 1 unit of mailbox quota; it never expires).
     *
     * Without `domain` a random shared domain and a random name are used. `domain` must be one of your usable
     * domains (`$client->domains()`); `name` is only allowed with your own domain. An Idempotency-Key is generated
     * (or pass yours), and retries reuse it, so a retry never creates a second address.
     */
    public function create(?string $domain = null, ?string $name = null, ?string $idempotencyKey = null): Mailbox
    {
        $f = $this->requester->call(Ops::mailboxesCreate($domain, $name, $idempotencyKey), MailboxFields::fromApi(...));

        return Mailbox::fromFields($this->requester, $f);
    }

    /** One page of addresses (newest first) with `nextCursor`. */
    public function listPage(?string $cursor = null, ?int $pageSize = null): MailboxPage
    {
        [$fields, $next] = $this->requester->call(Ops::mailboxesList($cursor, $pageSize), self::parsePage(...));
        $items = [];
        foreach ($fields as $f) {
            $items[] = Mailbox::fromFields($this->requester, $f);
        }

        return new MailboxPage($items, $next);
    }

    /**
     * Every address of the account (newest first) with its current token, following `next_cursor`.
     *
     * `limit` caps the total number of mailboxes yielded (null = all). A cursor the API already returned throws
     * InvalidResponseException instead of looping forever.
     *
     * @return \Generator<int, Mailbox, mixed, void>
     */
    public function list(?int $limit = null, ?int $pageSize = null): \Generator
    {
        $cursor = null;
        $seen = [];
        $n = 0;
        $size = $pageSize ?? ($limit !== null && $limit !== 0 ? min($limit, Ops::MAX_PAGE) : 50);
        while (true) {
            $page = $this->listPage($cursor, $size);
            foreach ($page->items as $mb) {
                if ($limit !== null && $n >= $limit) {
                    return;
                }
                yield $mb;
                ++$n;
            }
            if ($page->nextCursor === null || ($limit !== null && $n >= $limit)) {
                return;
            }
            Core::checkCursor($seen, $page->nextCursor);
            $cursor = $page->nextCursor;
        }
    }

    /** @return \Generator<int, Mailbox, mixed, void> */
    public function getIterator(): \Generator
    {
        return $this->list();
    }

    /** @return array{0: list<MailboxFields>, 1: ?string} */
    private static function parsePage(mixed $data): array
    {
        $d = S::obj($data, '$');
        $items = [];
        foreach (S::reqList($d, 'items', '$') as $i => $x) {
            $items[] = MailboxFields::fromApi($x, '$.items[' . $i . ']');
        }

        return [$items, S::cursor($d, '$')];
    }
}
