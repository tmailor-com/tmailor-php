<?php

declare(strict_types=1);

namespace Tmailor\Model;

use Tmailor\Internal\Schema as S;

/**
 * `GET /v1/domains`. Iterating it yields the domains.
 *
 * @implements \IteratorAggregate<int, Domain>
 */
final class DomainList implements \IteratorAggregate, \Countable
{
    /** @param list<Domain> $items */
    public function __construct(
        public readonly array $items,
        public readonly bool $sharedDomainsAvailable,
    ) {
    }

    /** @internal */
    public static function fromApi(mixed $data): self
    {
        $d = S::obj($data, '$');
        $items = [];
        foreach (S::reqList($d, 'items', '$') as $i => $x) {
            $items[] = Domain::fromApi($x, '$.items[' . $i . ']');
        }

        return new self($items, S::reqBool($d, 'shared_domains_available', '$'));
    }

    /** @return \ArrayIterator<int, Domain> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
