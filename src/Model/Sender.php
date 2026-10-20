<?php

declare(strict_types=1);

namespace Tmailor\Model;

/** Sender of a message. */
final class Sender
{
    public function __construct(
        public readonly string $name,
        public readonly string $address,
    ) {
    }
}
