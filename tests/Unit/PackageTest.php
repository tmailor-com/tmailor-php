<?php

declare(strict_types=1);

namespace Tmailor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tmailor\Client;

/** Release metadata stays consistent: version constant, CHANGELOG, composer.json. */
final class PackageTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function testVersionIsSemverAndInChangelog(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Client::VERSION);
        $changelog = (string) file_get_contents(self::root() . '/CHANGELOG.md');
        self::assertMatchesRegularExpression('/^## ' . preg_quote(Client::VERSION, '/') . ' /m', $changelog);
        self::assertStringContainsString('spec ' . Client::API_SPEC_VERSION, $changelog);
    }

    public function testComposerJson(): void
    {
        $c = json_decode((string) file_get_contents(self::root() . '/composer.json'), true);
        self::assertIsArray($c);
        self::assertSame('tmailor/tmailor', $c['name']);
        self::assertSame('library', $c['type']);
        self::assertArrayNotHasKey('version', $c, 'Packagist takes the version from the git tag');
        self::assertSame(['php' => '>=8.1', 'ext-curl' => '*', 'ext-json' => '*'], $c['require']);
    }
}
