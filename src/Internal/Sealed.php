<?php

declare(strict_types=1);

namespace Tmailor\Internal;

/**
 * Review 20b PHP-02: a value of any type (headers, a body, curl options) kept outside the properties of the object,
 * like {@see Secret}: var_dump(), print_r(), var_export(), json_encode() and (array) casts see nothing, and
 * serialize() is refused.
 *
 * @internal
 *
 * @template T
 */
final class Sealed implements \JsonSerializable
{
    // Not a WeakMap: PHP 8.1 segfaults when an object keyed in a static WeakMap is freed while an exception unwinds
    // the stack (CI run 39384425331, PHP 8.1.34, crash at `throw` in Requester). A static array keyed by
    // spl_object_id() is equally invisible to var_dump()/print_r()/var_export()/(array); __destruct() drops the entry
    // before the id can be reused, and cloning is refused.
    /** @var array<int, mixed> */
    private static array $values = [];

    private function __construct()
    {
    }

    /**
     * @template V
     *
     * @param V $value
     *
     * @return self<V>
     */
    public static function of(#[\SensitiveParameter] mixed $value): self
    {
        /** @var self<V> $s */
        $s = new self();
        self::$values[spl_object_id($s)] = $value;

        return $s;
    }

    /** @return T */
    public function value(): mixed
    {
        /** @var T */
        return self::$values[spl_object_id($this)] ?? null;
    }


    /** @return array<string, string> */
    public function __destruct()
    {
        unset(self::$values[spl_object_id($this)]);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    public function jsonSerialize(): string
    {
        return '***';
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A sealed Tmailor value cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A sealed Tmailor value cannot be unserialized');
    }

    private function __clone()
    {
    }
}
