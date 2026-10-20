<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;

/** One page of messages (newest first). Items have no body. */
final class MessagePage
{
    /** @param list<Message> $items */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
        public readonly int $total,
    ) {
    }

    /** @internal */
    public static function fromApi(mixed $data): self
    {
        $d = S::obj($data, '$');
        $items = [];
        foreach (S::reqList($d, 'items', '$') as $i => $x) {
            $items[] = Message::fromApi($x, '$.items[' . $i . ']');
        }

        return new self($items, S::cursor($d, '$'), S::reqInt($d, 'total', '$'));
    }
}
