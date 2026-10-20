<?php

declare(strict_types=1);

namespace Tmailor\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tmailor\Client;
use Tmailor\Exception as E;
use Tmailor\Http\CurlTransport;
use Tmailor\Http\Request;
use Tmailor\Http\TransportException;
use Tmailor\Tests\Support\FakeClock;
use Tmailor\Tests\Support\Fixtures as F;

/**
 * The default transport against local servers on 127.0.0.1 only: `php -S` with tests/Fixtures/router.php
 * (normal, slow, partial and redirect responses), a raw TCP server that closes without replying, and a closed port.
 */
#[RequiresPhpExtension('curl')]
final class CurlTransportTest extends TestCase
{
    /** @var list<resource> */
    private static array $procs = [];
    private static int $httpPort = 0;
    private static int $rawPort = 0;

    public static function setUpBeforeClass(): void
    {
        self::$httpPort = self::freePort();
        self::start(
            [\PHP_BINARY, '-S', '127.0.0.1:' . self::$httpPort, __DIR__ . '/../Fixtures/router.php'],
            ['PHP_CLI_SERVER_WORKERS' => '4'],
        );
        self::$rawPort = self::freePort();
        self::start([\PHP_BINARY, __DIR__ . '/../Fixtures/raw_server.php', (string) self::$rawPort], []);
        self::waitFor(self::$httpPort);
        self::waitFor(self::$rawPort);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$procs as $p) {
            proc_terminate($p);
            proc_close($p);
        }
        self::$procs = [];
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($s, $errstr ?? '');
        $name = (string) stream_socket_get_name($s, false);
        fclose($s);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
     */
    private static function start(array $cmd, array $env): void
    {
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $p = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, null, $env + getenv());
        self::assertIsResource($p);
        self::$procs[] = $p;
    }

    private static function waitFor(int $port): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($c !== false) {
                fclose($c);

                return;
            }
            usleep(50_000);
        }
        self::fail("local test server on port $port did not start");
    }

    private static function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$httpPort . $path;
    }

    public function testSuccessSendsHeadersAndBody(): void
    {
        $t = new CurlTransport();
        $r = $t->send(new Request('POST', self::url('/echo?a=1&b=x%20y'), ['Content-Type' => 'application/json', 'X-Test' => 'v'], '{"k":"v"}'), 5.0);
        self::assertSame(200, $r->status);
        self::assertSame('OK', $r->reasonPhrase);
        self::assertSame('rid-echo', $r->header('x-request-id'));
        self::assertSame('120', $r->header('X-RateLimit-Limit'));
        $echo = json_decode($r->body(), true);
        self::assertIsArray($echo);
        self::assertSame('POST', $echo['method']);
        self::assertSame(['a' => '1', 'b' => 'x y'], $echo['query']);
        self::assertSame('{"k":"v"}', $echo['body']);
        self::assertIsArray($echo['headers']);
        self::assertSame('v', $echo['headers']['x-test']);
        self::assertArrayNotHasKey('expect', $echo['headers']);

        // The handle is reused: a GET after a POST has no body and no leftover options.
        $r = $t->send(new Request('GET', self::url('/echo'), ['Accept' => 'application/json'], null), 5.0);
        $echo = json_decode($r->body(), true);
        self::assertIsArray($echo);
        self::assertSame('GET', $echo['method']);
        self::assertSame('', $echo['body']);
    }

    public function testErrorStatusIsAResponse(): void
    {
        $r = (new CurlTransport())->send(new Request('GET', self::url('/bad-gateway'), [], null), 5.0);
        self::assertSame(502, $r->status);
        self::assertSame('<html>bad gateway</html>', $r->body());
    }

    public function testRedirectIsNotFollowed(): void
    {
        $r = (new CurlTransport())->send(new Request('GET', self::url('/redirect'), [], null), 5.0);
        self::assertSame(302, $r->status);
    }

    public function testClosedPortIsConnect(): void
    {
        $port = self::freePort(); // nothing listens there any more
        try {
            (new CurlTransport())->send(new Request('POST', 'http://127.0.0.1:' . $port . '/v1/mailbox/delete', [], '{}'), 2.0);
            self::fail('no TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportException::CONNECT, $e->kind);
            self::assertTrue($e->connectPhase);
            self::assertStringContainsString('curl error 7', $e->getMessage());
        }
    }

    public function testSlowServerIsTimeout(): void
    {
        $t0 = microtime(true);
        try {
            (new CurlTransport())->send(new Request('GET', self::url('/slow?s=3'), [], null), 0.5);
            self::fail('no TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportException::TIMEOUT, $e->kind);
            self::assertFalse($e->connectPhase, 'the request was sent before the timeout');
            self::assertStringContainsString('curl error 28', $e->getMessage());
        }
        self::assertLessThan(2.5, microtime(true) - $t0, 'the timeout is a total limit');
    }

    public function testPartialBodyIsRead(): void
    {
        try {
            (new CurlTransport())->send(new Request('GET', self::url('/partial'), [], null), 5.0);
            self::fail('no TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportException::READ, $e->kind);
            self::assertFalse($e->connectPhase);
            self::assertStringContainsString('curl error 18', $e->getMessage());
        }
    }

    public function testEmptyReplyIsRead(): void
    {
        try {
            (new CurlTransport())->send(new Request('GET', 'http://127.0.0.1:' . self::$rawPort . '/x', [], null), 5.0);
            self::fail('no TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportException::READ, $e->kind);
            self::assertStringContainsString('curl error 52', $e->getMessage());
        }
    }

    public function testClientOverLoopbackHttp(): void
    {
        $c = new Client(F::KEY, baseUrl: 'http://127.0.0.1:' . self::$httpPort, allowUntrustedBaseUrl: true);
        self::assertSame('ok', $c->health()->status);
        // Error responses keep their typed class through the real transport.
        try {
            $c->mailbox(F::TOKEN)->delete();
            self::fail('no MailboxNotFoundException');
        } catch (E\MailboxNotFoundException $e) {
            self::assertSame('rid-404', $e->requestId);
        }
    }

    public function testClientRetriesConnectErrorsThenRaises(): void
    {
        $port = self::freePort();
        $clock = new FakeClock();
        $c = new Client(F::KEY, baseUrl: 'http://127.0.0.1:' . $port, allowUntrustedBaseUrl: true, clock: $clock);
        try {
            $c->mailbox(F::TOKEN)->delete(); // unsafe, but nothing was sent: retried
            self::fail('no ApiConnectionException');
        } catch (E\ApiConnectionException $e) {
            self::assertNotInstanceOf(E\ApiTimeoutException::class, $e);
            self::assertSame('ConnectionError', $e->errorCode);
            self::assertNull($e->status);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('SECRET', (string) $e);
        }
        self::assertCount(2, $clock->sleeps);
    }

    public function testClientTimeoutAndReadErrors(): void
    {
        $clock = new FakeClock();
        $c = new Client(F::KEY, baseUrl: 'http://127.0.0.1:' . self::$rawPort, allowUntrustedBaseUrl: true, maxRetries: 1, clock: $clock);
        try {
            $c->mailbox(F::TOKEN)->delete(); // unsafe and the request was sent: not retried
            self::fail('no ApiConnectionException');
        } catch (E\ApiConnectionException $e) {
            self::assertSame('ConnectionError', $e->errorCode);
        }
        self::assertSame([], $clock->sleeps);

        $c = new Client(F::KEY, baseUrl: 'http://127.0.0.1:' . self::$httpPort . '/slow', allowUntrustedBaseUrl: true, timeout: 0.3, maxRetries: 0);
        $this->expectException(E\ApiTimeoutException::class);
        $c->health();
    }

    public function testDumpsHideOptions(): void
    {
        $t = new CurlTransport([\CURLOPT_PROXYUSERPWD => 'user:SUPERSECRET']);
        ob_start();
        var_dump($t);
        $text = print_r($t, true) . var_export($t, true) . json_encode($t) . print_r((array) $t, true) . ob_get_clean();
        self::assertStringNotContainsString('SUPERSECRET', $text);
        self::assertStringContainsString('CURLOPT_PROXYUSERPWD', print_r($t, true));
    }

    /** @return iterable<string, array{int}> */
    public static function unsafeOptions(): iterable
    {
        $names = [
            'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_VERBOSE', 'CURLOPT_STDERR',
            'CURLOPT_FOLLOWLOCATION', 'CURLOPT_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS', 'CURLOPT_HEADERFUNCTION',
            'CURLOPT_WRITEFUNCTION', 'CURLOPT_READFUNCTION', 'CURLOPT_DEBUGFUNCTION', 'CURLOPT_URL',
            'CURLOPT_HTTPHEADER', 'CURLOPT_POSTFIELDS', 'CURLOPT_PROXY_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYSTATUS',
            'CURLOPT_CERTINFO', 'CURLOPT_FILE', 'CURLOPT_WRITEHEADER', 'CURLOPT_UNRESTRICTED_AUTH',
        ];
        foreach ($names as $n) {
            if (\defined($n)) {
                yield $n => [\constant($n)];
            }
        }
        yield 'unknown number' => [999999];
    }

    #[DataProvider('unsafeOptions')]
    public function testUnsafeOptionsAreRefused(int $opt): void
    {
        // Review 20b PHP-01: only the allowlisted options; nothing can turn off TLS checks or print a debug trace.
        $this->expectException(\InvalidArgumentException::class);
        new CurlTransport([$opt => true]);
    }

    public function testSafeOptionsAreAccepted(): void
    {
        $t = new CurlTransport([\CURLOPT_CAINFO => '/etc/ssl/certs/ca-bundle.crt', \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4]);
        $r = $t->send(new Request('GET', self::url('/v1/health'), [], null), 5.0);
        self::assertSame(200, $r->status);
        self::assertArrayHasKey(\CURLOPT_PROXY, CurlTransport::allowedOptions());
        self::assertArrayNotHasKey(\CURLOPT_VERBOSE, CurlTransport::allowedOptions());
    }

    public function testAllowlistNumbersMatchTheLocalConstants(): void
    {
        foreach (CurlTransport::SAFE_OPTIONS as $number => $name) {
            if (\defined($name)) {
                self::assertSame($number, \constant($name), $name);
            }
        }
        foreach (['CURLOPT_SSLKEYPASSWD', 'CURLOPT_KEYPASSWD'] as $alias) {
            if (\defined($alias)) {
                self::assertArrayHasKey(\constant($alias), CurlTransport::SAFE_OPTIONS, $alias);
            }
        }
    }

    public function testPlainHttpOnlyToLoopback(): void
    {
        // Review 20b PHP-01: https only, http only to a loopback host; no request is attempted otherwise.
        foreach (['http://example.com/v1/health', 'ftp://127.0.0.1/x', 'file:///etc/passwd', 'gopher://127.0.0.1/'] as $url) {
            try {
                (new CurlTransport())->send(new Request('GET', $url, [], null), 1.0);
                self::fail('sent to ' . $url);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCurlErrnoIsKept(): void
    {
        try {
            (new CurlTransport())->send(new Request('GET', 'http://127.0.0.1:' . self::freePort() . '/', [], null), 2.0);
            self::fail('no TransportException');
        } catch (TransportException $e) {
            self::assertSame(7, $e->curlErrno);
        }
    }
}
