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

    public function testNoFirstClassCallableSyntaxInSrc(): void
    {
        // PHP 8.1 segfaults when a `X::method(...)` callable is freed during exception unwinding (CI, PHP 8.1.34):
        // src/ passes `static fn` closures instead. Comments are skipped; `...` alone (variadics, spread) is fine.
        $banned = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src'));
        foreach ($files as $f) {
            if (!$f instanceof \SplFileInfo || $f->getExtension() !== 'php') {
                continue;
            }
            $code = [];
            foreach (token_get_all((string) file_get_contents($f->getPathname())) as $t) {
                if (\is_array($t) && \in_array($t[0], [\T_COMMENT, \T_DOC_COMMENT, \T_WHITESPACE], true)) {
                    continue;
                }
                $code[] = \is_array($t) ? $t[1] : $t;
            }
            if (str_contains(implode('', $code), '(...)')) {
                $banned[] = $f->getFilename();
            }
        }
        self::assertSame([], $banned, 'first-class callable syntax in src/');
    }
}
