<?php

declare(strict_types=1);

namespace Tmailor\Internal;

/**
 * A secret string (API key, address token) that never shows up in var_dump(), print_r(), var_export(),
 * json_encode(), (array) casts or stack traces.
 *
 * The value is not stored in a property of the object: it lives in a private static WeakMap keyed by the
 * object, so every dumper that walks properties sees nothing. serialize() is refused.
 *
 * @internal
 */
final class Secret implements \JsonSerializable
{
    /** @var \WeakMap<Secret, string>|null */
    private static ?\WeakMap $values = null;

    private function __construct()
    {
    }

    public static function of(#[\SensitiveParameter] string $value): self
    {
        $s = new self();
        self::map()[$s] = $value;

        return $s;
    }

    public static function ofNullable(#[\SensitiveParameter] ?string $value): ?self
    {
        return $value === null ? null : self::of($value);
    }

    public function reveal(): string
    {
        return self::map()[$this] ?? '';
    }

    public function equals(#[\SensitiveParameter] string $value): bool
    {
        return hash_equals($this->reveal(), $value);
    }

    /** @return \WeakMap<Secret, string> */
    private static function map(): \WeakMap
    {
        if (self::$values === null) {
            /** @var \WeakMap<Secret, string> $m */
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
