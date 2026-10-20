<?php

declare(strict_types=1);

namespace Tmailor\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmailor\Client;
use Tmailor\Exception\BaseUrlException;
use Tmailor\Internal\BaseUrl;

final class BaseUrlTest extends TestCase
{
    /** @return iterable<string, array{string, bool, ?string}> */
    public static function urls(): iterable
    {
        $cases = [
            ['https://open-api.tmailor.com', false, 'https://open-api.tmailor.com'],
            ['HTTPS://Open-API.tmailor.com:443/', false, 'https://open-api.tmailor.com:443'],
            ['https://open-api.tmailor.com/v0/', false, 'https://open-api.tmailor.com/v0'],
            ['https://staging.example.com', true, 'https://staging.example.com'],
            ['http://127.0.0.1:8080', true, 'http://127.0.0.1:8080'],
            ['http://127.9.9.9', true, 'http://127.9.9.9'],
            ['http://[::1]:8080/', true, 'http://[::1]:8080'],
            ['http://localhost:9000', true, 'http://localhost:9000'],
            ['https://staging.example.com', false, null],
            ['https://open-api.tmailor.com.evil.example', false, null],
            ['https://open-api.tmailor.com:8443', false, null],
            ['http://open-api.tmailor.com', false, null],
            ['http://open-api.tmailor.com', true, null],
            ['http://example.com', true, null],
            ['http://127.0.0.1.nip.io', true, null],
            ['http://128.0.0.1', true, null],
            ['http://[::2]', true, null],
            ['http://127.0.0.1:8080', false, null],
            ['https://user:pass@open-api.tmailor.com', true, null],
            ['https://open-api.tmailor.com@evil.example', true, null],
            ['https://open-api.tmailor.com/?x=1', true, null],
            ['https://open-api.tmailor.com/#frag', true, null],
            ['https://open-api.tmailor.com/a b', true, null],
            ["https://open-api.tmailor.com\r\nX: y", true, null],
            ["https://open-api.tmailor.com/\u{00A0}", true, null],
            ["https://open-api.tmailor.com/\xC2", true, null],
            ['ftp://open-api.tmailor.com', true, null],
            ['open-api.tmailor.com', true, null],
            ['https://', true, null],
            ['https://h:99999', true, null],
            ['https://h:abc', true, null],
            ['https://[zz]', true, null],
            ['', true, null],
        ];
        foreach ($cases as $i => [$url, $allow, $expect]) {
            yield $i . ' ' . addcslashes($url, "\0..\37\177..\377") . ($allow ? ' (allow)' : '') => [$url, $allow, $expect];
        }
    }

    #[DataProvider('urls')]
    public function testCheck(string $url, bool $allow, ?string $expect): void
    {
        if ($expect === null) {
            $this->expectException(BaseUrlException::class);
            BaseUrl::check($url, $allow);
        } else {
            self::assertSame($expect, BaseUrl::check($url, $allow));
        }
    }

    public function testBaseUrlExceptionIsAnArgumentError(): void
    {
        self::assertInstanceOf(\InvalidArgumentException::class, new BaseUrlException('x'));
    }

    public function testClientChecksBaseUrlAndEnvironment(): void
    {
        $prev = getenv('TMAILOR_BASE_URL');
        try {
            putenv('TMAILOR_BASE_URL=https://example.test/');
            try {
                new Client('k');
                self::fail('untrusted host from the environment accepted');
            } catch (BaseUrlException) {
                self::addToAssertionCount(1);
            }
            self::assertSame('https://example.test', (new Client('k', allowUntrustedBaseUrl: true))->baseUrl);
            putenv('TMAILOR_BASE_URL');
            self::assertSame('https://open-api.tmailor.com', (new Client('k'))->baseUrl);
            $this->expectException(BaseUrlException::class);
            new Client('k', baseUrl: 'http://evil.example', allowUntrustedBaseUrl: true);
        } finally {
            putenv($prev === false ? 'TMAILOR_BASE_URL' : 'TMAILOR_BASE_URL=' . $prev);
        }
    }
}
