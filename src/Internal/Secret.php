<?php

declare(strict_types=1);

namespace Tmailor\Internal;

/**
 * A secret string (API key, address token) that never shows up in var_dump(), print_r(), var_export(),
 * json_encode(), (array) casts or stack traces.
 *
 * The value is not stored in a property of the object: it lives in a private static array keyed by the
 * object id, so every dumper that walks properties sees nothing. serialize() is refused.
 *
 * @internal
 */
final class Secret implements \JsonSerializable
{
    // Not a WeakMap: PHP 8.1 segfaults when an object keyed in a static WeakMap is freed while an exception unwinds
    // the stack (CI run 39384425331, PHP 8.1.34, crash at `throw` in Requester). A static array keyed by
    // spl_object_id() is equally invisible to var_dump()/print_r()/var_export()/(array); __destruct() drops the entry
    // before the id can be reused, and cloning is refused.
    /** @var array<int, string> */
    private static array $values = [];

    private function __construct()
    {
    }

    public static function of(#[\SensitiveParameter] string $value): self
    {
        $s = new self();
        self::$values[spl_object_id($s)] = $value;

        return $s;
    }

    public static function ofNullable(#[\SensitiveParameter] ?string $value): ?self
    {
        return $value === null ? null : self::of($value);
    }

    public function reveal(): string
    {
        return self::$values[spl_object_id($this)] ?? '';
    }

    public function equals(#[\SensitiveParameter] string $value): bool
    {
        return hash_equals($this->reveal(), $value);
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

    public function __toString(): string
    {
        return '***';
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A Tmailor secret cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Tmailor secret cannot be unserialized');
    }

    /** A clone would have no value in the map; secrets are immutable, share the instance instead. */
    private function __clone()
    {
    }
}
