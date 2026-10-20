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
    /** @var \WeakMap<Sealed<mixed>, mixed>|null */
    private static ?\WeakMap $values = null;

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
        self::map()[$s] = $value;

        return $s;
    }

    /** @return T */
    public function value(): mixed
    {
        /** @var T */
        return self::map()[$this] ?? null;
    }

    /** @return \WeakMap<Sealed<mixed>, mixed> */
    private static function map(): \WeakMap
    {
        if (self::$values === null) {
            /** @var \WeakMap<Sealed<mixed>, mixed> $m */
            $m = new \WeakMap();
            self::$values = $m;
        }

        return self::$values;
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
