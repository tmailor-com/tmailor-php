<?php

declare(strict_types=1);

namespace Tmailor\Internal;

/** @internal */
final class Env
{
    /** getenv() first, then $_ENV (frameworks that load .env files may only fill one of them). */
    public static function get(string $name): ?string
    {
        $v = getenv($name);
        if (is_string($v)) {
            return $v;
        }
        $e = $_ENV[$name] ?? null;

        return is_string($e) ? $e : null;
    }
}
