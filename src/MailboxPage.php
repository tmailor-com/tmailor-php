<?php

declare(strict_types=1);

namespace Tmailor;

/** One page of `GET /v1/mailboxes`. */
final class MailboxPage
{
    /** @param list<Mailbox> $items */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
    ) {
    }
}
