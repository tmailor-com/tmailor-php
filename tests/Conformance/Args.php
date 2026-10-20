<?php

declare(strict_types=1);

namespace Tmailor\Tests\Conformance;

use PHPUnit\Framework\Assert;

/** Typed access to the neutral `call.args` of a scenario; fails on an argument the runner did not map. */
final class Args
{
    /** @var array<string, true> */
    private array $used = [];

    /** @param array<array-key, mixed> $args */
    public function __construct(private readonly array $args)
    {
    }

    public function str(string $k): ?string
    {
        $v = $this->get($k);
        Assert::assertTrue($v === null || is_string($v), "arg $k must be a string");

        return $v;
    }

    public function int(string $k): ?int
    {
        $v = $this->get($k);
        Assert::assertTrue($v === null || is_int($v), "arg $k must be an integer");

        return $v;
    }

    public function bool(string $k): ?bool
    {
        $v = $this->get($k);
        Assert::assertTrue($v === null || is_bool($v), "arg $k must be a boolean");

        return $v;
    }

    public function num(string $k): int|float|null
    {
        $v = $this->get($k);
        Assert::assertTrue($v === null || is_int($v) || is_float($v), "arg $k must be a number");

        return $v;
    }

    public function assertAllUsed(string $op): void
    {
        foreach (array_keys($this->args) as $k) {
            Assert::assertArrayHasKey((string) $k, $this->used, "$op: argument $k is not mapped by the runner");
        }
    }

    private function get(string $k): mixed
    {
        $this->used[$k] = true;

        return $this->args[$k] ?? null;
    }
}
