<?php

declare(strict_types=1);

namespace Tmailor\Model;

/** The API key used for the request: its id and its public prefix (never the secret part). */
final class KeyInfo
{
    public function __construct(
        public readonly int $keyId,
        public readonly string $prefix,
    ) {
    }
}
