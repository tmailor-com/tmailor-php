<?php

declare(strict_types=1);

namespace Tmailor\Internal;

/**
 * Strict readers for 2xx response bodies decoded with json_decode(..., false): a JSON object is a stdClass and a
 * JSON array is a PHP list, so the two are never confused (`{}` is not `[]`).
 *
 * Every reader checks that a field is present and has exactly the JSON type of the OpenAPI schema. `null` is
 * accepted only where the schema allows it. Nothing is coerced (`"12"` is not an integer, `"false"` is not a
 * boolean, `1.0` is not an integer). Messages name the field, never the value.
 *
 * @internal
 */
final class Schema
{
    public static function obj(mixed $v, string $path): \stdClass
    {
        if (!$v instanceof \stdClass) {
            throw new SchemaException($path . ' must be an object');
        }

        return $v;
    }

    private static function get(\stdClass $d, string $key, string $path): mixed
    {
        if (!property_exists($d, $key)) {
            throw new SchemaException($path . '.' . $key . ' is missing');
        }

        return $d->{$key};
    }

    public static function has(\stdClass $d, string $key): bool
    {
        return property_exists($d, $key);
    }

    public static function reqObj(\stdClass $d, string $key, string $path): \stdClass
    {
        return self::obj(self::get($d, $key, $path), $path . '.' . $key);
    }

    public static function reqObjOrNull(\stdClass $d, string $key, string $path): ?\stdClass
    {
        $v = self::get($d, $key, $path);

        return $v === null ? null : self::obj($v, $path . '.' . $key);
    }

    public static function reqStr(\stdClass $d, string $key, string $path): string
    {
        $v = self::get($d, $key, $path);
        if (!is_string($v)) {
            throw new SchemaException($path . '.' . $key . ' must be a string');
        }

        return $v;
    }

    public static function reqStrOrNull(\stdClass $d, string $key, string $path): ?string
    {
        $v = self::get($d, $key, $path);
        if ($v !== null && !is_string($v)) {
            throw new SchemaException($path . '.' . $key . ' must be a string or null');
        }

        return $v;
    }

    public static function reqInt(\stdClass $d, string $key, string $path): int
    {
        $v = self::get($d, $key, $path);
        if (!is_int($v)) {
            throw new SchemaException($path . '.' . $key . ' must be an integer');
        }

        return $v;
    }

    /** A field that may be absent (null); when present it must be an integer (not null). */
    public static function optInt(\stdClass $d, string $key, string $path): ?int
    {
        if (!property_exists($d, $key)) {
            return null;
        }

        return self::reqInt($d, $key, $path);
    }

    public static function reqBool(\stdClass $d, string $key, string $path): bool
    {
        $v = self::get($d, $key, $path);
        if (!is_bool($v)) {
            throw new SchemaException($path . '.' . $key . ' must be a boolean');
        }

        return $v;
    }

    /** @return list<mixed> */
    public static function reqList(\stdClass $d, string $key, string $path): array
    {
        $v = self::get($d, $key, $path);
        if (!is_array($v) || !array_is_list($v)) {
            throw new SchemaException($path . '.' . $key . ' must be an array');
        }

        return $v;
    }

    /** @return list<string> */
    public static function reqStrList(\stdClass $d, string $key, string $path): array
    {
        $out = [];
        foreach (self::reqList($d, $key, $path) as $i => $x) {
            if (!is_string($x)) {
                throw new SchemaException($path . '.' . $key . '[' . $i . '] must be a string');
            }
            $out[] = $x;
        }

        return $out;
    }

    /** `next_cursor`: string or null (an empty string is treated as the end). */
    public static function cursor(\stdClass $d, string $path): ?string
    {
        $v = self::reqStrOrNull($d, 'next_cursor', $path);

        return $v === null || $v === '' ? null : $v;
    }

    /**
     * stdClass tree -> associative arrays (for `raw`). An empty JSON object becomes [].
     *
     * @return array<string, mixed>
     */
    public static function toArray(\stdClass $o): array
    {
        $out = [];
        foreach (get_object_vars($o) as $k => $v) {
            $out[(string) $k] = self::plain($v);
        }

        return $out;
    }

    private static function plain(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            return self::toArray($v);
        }
        if (is_array($v)) {
            return array_map(static fn(mixed $d): mixed => self::plain($d), $v);
        }

        return $v;
    }
}
