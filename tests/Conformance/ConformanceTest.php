<?php

declare(strict_types=1);

namespace Tmailor\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmailor\Client;
use Tmailor\Exception as E;
use Tmailor\Mailbox;
use Tmailor\Model\Message;

/**
 * Runs every shared conformance scenario (conformance/scenarios/*.json) against the PHP SDK.
 *
 * Every request the SDK sends must match the next exchange; a scenario fails if a request is unexpected,
 * differs, or if an exchange is left unused. Format: conformance/README.md.
 */
final class ConformanceTest extends TestCase
{
    /** Number of scenario files shipped with this SDK version; fewer means fixtures were lost. */
    public const EXPECTED_SCENARIOS = 83; // Review 20b CONF: +me_missing_ip_allowlist_enforced, +redirect_3xx_is_error, +mailbox_delete_redirect_is_error
    public const DEFAULT_KEY = 'tmk_live_c0nf0rmance01_TESTSECRETdoNotUseAnywhere0000000000000';
    public const BASE_URL = 'https://open-api.test';

    /** Neutral class names of the scenarios -> PHP exception classes. */
    private const ERROR_CLASSES = [
        'MissingKey' => E\MissingKeyException::class,
        'InvalidKey' => E\InvalidKeyException::class,
        'KeyRevoked' => E\KeyRevokedException::class,
        'AccountLocked' => E\AccountLockedException::class,
        'IpNotAllowed' => E\IpNotAllowedException::class,
        'InvalidJson' => E\InvalidJsonException::class,
        'InvalidField' => E\InvalidFieldException::class,
        'InvalidDomain' => E\InvalidDomainException::class,
        'CustomNameNotAllowed' => E\CustomNameNotAllowedException::class,
        'InvalidName' => E\InvalidNameException::class,
        'QuotaExceeded' => E\QuotaExceededException::class,
        'MailboxNotFound' => E\MailboxNotFoundException::class,
        'MessageNotFound' => E\MessageNotFoundException::class,
        'NotFound' => E\NotFoundException::class,
        'MailboxExists' => E\MailboxExistsException::class,
        'Conflict' => E\ConflictException::class,
        'RateLimited' => E\RateLimitedException::class,
        'DailyCapReached' => E\DailyCapReachedException::class,
        'InternalServerError' => E\InternalServerException::class,
        'ServiceUnavailable' => E\ServiceUnavailableException::class,
        'NoDomainAvailable' => E\NoDomainAvailableException::class,
        'IdempotencyUnavailable' => E\IdempotencyUnavailableException::class,
        'CacheUnavailable' => E\CacheUnavailableException::class,
        'BadRequest' => E\BadRequestException::class,
        'InvalidResponse' => E\InvalidResponseException::class,
        'ConnectionError' => E\ApiConnectionException::class,
        'Timeout' => E\ApiTimeoutException::class,
        'WaitTimeout' => E\WaitTimeoutException::class,
        // Review 20b CONF: any other HTTP error (for example a 3xx, never followed) is the plain API error class.
        'APIError' => E\ApiException::class,
    ];

    /** Stable location: TMAILOR_CONFORMANCE_DIR, else <repo>/conformance/scenarios. Missing = failure, never a skip. */
    public static function scenarioDir(): string
    {
        $env = getenv('TMAILOR_CONFORMANCE_DIR');

        return is_string($env) && $env !== '' ? $env : \dirname(__DIR__, 2) . '/conformance/scenarios';
    }

    /** @return list<string> */
    private static function files(): array
    {
        $files = glob(self::scenarioDir() . '/*.json');

        return $files === false ? [] : $files;
    }

    public function testScenariosFound(): void
    {
        self::assertDirectoryExists(self::scenarioDir(), 'conformance scenarios not found');
        self::assertGreaterThanOrEqual(self::EXPECTED_SCENARIOS, count(self::files()));
    }

    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        foreach (self::files() as $f) {
            yield basename($f, '.json') => [$f];
        }
    }

    #[DataProvider('scenarios')]
    public function testScenario(string $file): void
    {
        $text = (string) file_get_contents($file);
        $scn = self::arr(json_decode($text, true, 512, \JSON_THROW_ON_ERROR));
        $obj = json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $obj);
        $sid = (string) self::str($scn['id'] ?? '');
        self::assertSame(basename($file, '.json'), $sid, 'id must equal the file name');

        $exchanges = [];
        $responseJson = [];
        foreach (self::arr($scn['exchanges'] ?? []) as $i => $ex) {
            $exchanges[] = self::arr($ex);
            $exObj = self::arr($obj->exchanges)[$i] ?? null;
            $responseJson[] = $exObj instanceof \stdClass && $exObj->response instanceof \stdClass && property_exists($exObj->response, 'json')
                ? $exObj->response->json
                : null;
        }
        $cfg = self::arr($scn['client'] ?? []);
        $clockCfg = self::arr($scn['clock'] ?? []);
        $clock = new \Tmailor\Tests\Support\FakeClock(self::num($clockCfg['wall_start'] ?? 1760342400));
        $replay = new ReplayTransport($sid, $exchanges, $responseJson, $clock);
        $apiKey = array_key_exists('api_key', $cfg) ? self::str($cfg['api_key']) : self::DEFAULT_KEY;
        $maxRetries = $cfg['max_retries'] ?? 2;
        self::assertIsInt($maxRetries);
        $client = new Client(
            $apiKey,
            baseUrl: self::BASE_URL,
            timeout: self::num($cfg['timeout'] ?? 30.0),
            maxRetries: $maxRetries,
            maxRetryWait: self::num($cfg['max_retry_wait'] ?? 60),
            transport: $replay,
            allowUntrustedBaseUrl: true, // fake host
            clock: $clock,
        );

        [$outcome, $mailbox, $err] = $this->call($client, $scn);
        $this->check($sid, self::arr($scn['expect'] ?? []), $outcome, $err, $mailbox, $client, $replay, $clock);
    }

    /**
     * @param array<array-key, mixed> $scn
     *
     * @return array{0: mixed, 1: ?Mailbox, 2: ?E\TmailorException}
     */
    private function call(Client $client, array $scn): array
    {
        $call = self::arr($scn['call'] ?? []);
        $op = self::str($call['op'] ?? '');
        // Neutral argument names (conformance/README.md) -> PHP named arguments.
        $a = new Args(self::arr($call['args'] ?? []));
        $mb = null;
        try {
            if (str_starts_with($op, 'mailbox.')) {
                $mb = $client->mailbox(self::str($call['token'] ?? ''));
                $res = self::mailboxOp($mb, $op, $a);
            } else {
                $res = match ($op) {
                    'health' => $client->health(),
                    'me' => $client->me(),
                    'domains' => $client->domains(),
                    'usage' => $client->usage(from: $a->str('from'), to: $a->str('to')),
                    'mailboxes.create' => $mb = $client->mailboxes->create(
                        domain: $a->str('domain'),
                        name: $a->str('name'),
                        idempotencyKey: $a->str('idempotency_key'),
                    ),
                    'mailboxes.list' => iterator_to_array(
                        $client->mailboxes->list(limit: $a->int('limit'), pageSize: $a->int('page_size')),
                        false,
                    ),
                    default => self::fail('unknown op ' . $op),
                };
            }
            $a->assertAllUsed($op);
            $then = $call['then'] ?? null;
            if ($then === 'mailbox.info') {
                $mb?->info();
            } elseif ($then !== null) {
                self::fail('unknown then ' . self::str($then));
            }

            return [$res, $mb, null];
        } catch (E\TmailorException $e) {
            return [null, $mb, $e];
        }
    }

    private static function mailboxOp(Mailbox $mb, string $op, Args $a): mixed
    {
        return match ($op) {
            'mailbox.info' => $mb->info(),
            'mailbox.clear' => $mb->clear(),
            'mailbox.delete' => $mb->delete(),
            'mailbox.messages' => iterator_to_array($mb->messages(
                unread: $a->bool('unread'),
                from: $a->str('from'),
                subject: $a->str('subject'),
                since: $a->num('since'),
                limit: $a->int('limit'),
                pageSize: $a->int('page_size'),
            ), false),
            'mailbox.message' => $mb->message($a->str('message_id') ?? ''),
            'mailbox.delete_message' => $mb->deleteMessage($a->str('message_id') ?? ''),
            'mailbox.wait_for_message' => $mb->waitForMessage(
                timeout: $a->num('timeout') ?? 120,
                from: $a->str('from'),
                subjectContains: $a->str('subject_contains'),
                pollInterval: $a->num('poll_interval'),
                since: $a->num('since'),
            ),
            default => self::fail('unknown op ' . $op),
        };
    }

    /** @param array<array-key, mixed> $exp */
    private function check(
        string $sid,
        array $exp,
        mixed $outcome,
        ?E\TmailorException $err,
        ?Mailbox $mailbox,
        Client $client,
        ReplayTransport $replay,
        \Tmailor\Tests\Support\FakeClock $clock,
    ): void {
        $replay->assertDone();
        if (array_key_exists('error', $exp)) {
            $e = self::arr($exp['error']);
            $class = self::ERROR_CLASSES[self::str($e['class'] ?? '')] ?? null;
            self::assertNotNull($class, "$sid: unknown error class");
            self::assertNotNull($err, "$sid: expected {$class}, got a result");
            self::assertInstanceOf($class, $err, "$sid: " . $err::class . " is not $class");
            self::assertInstanceOf(E\TmailorException::class, $err);
            self::assertSame($e['code'], $err->errorCode, "$sid: code");
            self::assertSame($e['status'], $err->status, "$sid: status");
            if (array_key_exists('request_id', $e)) {
                self::assertSame($e['request_id'], $err->requestId, "$sid: request_id");
            }
            if (array_key_exists('retry_after', $e)) {
                self::assertSame($e['retry_after'], $err->retryAfter, "$sid: retry_after");
            }
            if (array_key_exists('message', $e)) {
                self::assertSame($e['message'], $err->getMessage(), "$sid: message");
            }
            if (array_key_exists('secret_changed', $e)) {
                self::assertInstanceOf(E\CacheUnavailableException::class, $err);
                self::assertSame($e['secret_changed'], $err->secretChanged, "$sid: secret_changed");
            }
            if (array_key_exists('reason', $e)) {
                self::assertInstanceOf(E\InvalidNameException::class, $err);
                self::assertSame($e['reason'], $err->reason, "$sid: reason");
            }
            // No secret ever leaks into the error: message, summary, string form with the stack trace (arguments
            // included, see phpunit.xml.dist), body, headers, dumps.
            $text = implode("\n", [
                $err->getMessage(),
                $err->summary(),
                (string) $err,
                print_r($err->body, true),
                print_r($err->headers, true),
                print_r(self::sdkFrames($err), true),
            ]);
            self::assertNoSecret($sid, $text);
            self::assertNull($err->getPrevious(), "$sid: chained exception");
        } else {
            self::assertNull($err, "$sid: unexpected error " . ($err !== null ? $err::class . ' ' . $err->getMessage() : ''));
            self::assertSubset($sid, $exp['result'] ?? null, self::toPlain($outcome));
            foreach (['otp', 'codes', 'links'] as $k) {
                if (array_key_exists($k, $exp)) {
                    self::assertInstanceOf(Message::class, $outcome);
                    self::assertSame($exp[$k], $outcome->{$k}, "$sid: $k");
                }
            }
        }
        if (array_key_exists('sleeps', $exp)) {
            $want = self::arr($exp['sleeps']);
            self::assertCount(count($want), $clock->sleeps, "$sid: sleeps " . json_encode($clock->sleeps) . ' != ' . json_encode($want));
            foreach ($want as $i => $w) {
                self::assertEqualsWithDelta(self::num($w), $clock->sleeps[$i], 1e-6, "$sid: sleeps " . json_encode($clock->sleeps));
            }
        }
        if (array_key_exists('elapsed_max', $exp)) {
            self::assertLessThanOrEqual(self::num($exp['elapsed_max']) + 1e-6, $clock->now, "$sid: elapsed");
        }
        if (array_key_exists('sleep_count', $exp)) {
            self::assertCount(self::int($exp['sleep_count']), $clock->sleeps, "$sid: sleep_count");
        }
        if (array_key_exists('rate_limit', $exp)) {
            $rl = $client->lastRateLimit();
            self::assertNotNull($rl, "$sid: rate_limit");
            self::assertSame($exp['rate_limit'], ['limit' => $rl->limit, 'remaining' => $rl->remaining, 'reset' => $rl->reset]);
        }
        if (array_key_exists('mailbox', $exp)) {
            self::assertNotNull($mailbox, "$sid: mailbox");
            self::assertSame(self::arr($exp['mailbox'])['token'] ?? null, $mailbox->token, "$sid: mailbox token not updated");
            ob_start();
            var_dump($mailbox);
            $dump = (string) ob_get_clean();
            self::assertNoSecret($sid, print_r($mailbox, true) . $dump . json_encode($mailbox) . $mailbox);
        }
    }

    /**
     * Stack frames of SDK code (with their arguments). Frames of the tests are left out: their arguments hold the
     * scenario, which has the key and tokens in it.
     *
     * @return list<array<string, mixed>>
     */
    public static function sdkFrames(\Throwable $e): array
    {
        $out = [];
        foreach ($e->getTrace() as $f) {
            $class = $f['class'] ?? '';
            if (str_starts_with($class, 'Tmailor\\') && !str_starts_with($class, 'Tmailor\\Tests\\')) {
                $out[] = $f;
            }
        }

        return $out;
    }

    public static function assertNoSecret(string $sid, string $text): void
    {
        foreach ([self::DEFAULT_KEY, 'TESTSECRET', 'conformance-token', 'conformance-transfer'] as $s) {
            self::assertStringNotContainsString($s, $text, "$sid: secret in output");
        }
    }

    /** SDK result -> JSON-like value with the API field names (camelCase -> snake_case). */
    public static function toPlain(mixed $v): mixed
    {
        if ($v instanceof Mailbox) {
            $d = [
                'email' => $v->email,
                'domain' => $v->domain,
                'is_user_domain' => $v->isUserDomain,
                'custom_name' => $v->customName,
                'created_at' => $v->createdAt,
                'token' => $v->token,
                'web' => self::toPlain($v->web),
            ];
            if ($v->messageCount !== null) {
                $d['message_count'] = $v->messageCount;
            }

            return $d;
        }
        if ($v instanceof \Tmailor\Model\WebAccess) {
            return ['accesstoken' => $v->accesstoken, 'transfertoken' => $v->transfertoken, 'reuse_url' => $v->reuseUrl];
        }
        if (is_object($v)) {
            $out = [];
            foreach ((new \ReflectionObject($v))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
                if ($p->isStatic() || $p->getName() === 'raw') {
                    continue;
                }
                $name = strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z0-9])/', '_', $p->getName()));
                $out[$name] = self::toPlain($p->getValue($v));
            }

            return $out;
        }
        if (is_array($v)) {
            return array_map(self::toPlain(...), $v);
        }

        return $v;
    }

    /** Deep subset: objects -> listed keys must match; arrays -> same length, each element subset; scalars equal. */
    public static function assertSubset(string $sid, mixed $exp, mixed $got, string $path = '$'): void
    {
        if (is_array($exp) && !array_is_list($exp)) {
            self::assertIsArray($got, "$sid: $path expected object, got " . var_export($got, true));
            foreach ($exp as $k => $v) {
                self::assertArrayHasKey($k, $got, "$sid: $path.$k missing");
                self::assertSubset($sid, $v, $got[$k], "$path.$k");
            }
        } elseif (is_array($exp)) {
            self::assertIsArray($got, "$sid: $path expected array");
            self::assertTrue(array_is_list($got), "$sid: $path expected a list");
            self::assertCount(count($exp), $got, "$sid: $path expected " . count($exp) . ' items');
            foreach ($exp as $i => $v) {
                self::assertSubset($sid, $v, $got[$i], $path . '[' . $i . ']');
            }
        } elseif ((is_int($exp) || is_float($exp)) && (is_int($got) || is_float($got))) {
            self::assertEquals($exp, $got, "$sid: $path");
        } else {
            self::assertSame($exp, $got, "$sid: $path");
        }
    }

    /** @return array<array-key, mixed> */
    private static function arr(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    private static function str(mixed $v): string
    {
        self::assertIsString($v);

        return $v;
    }

    private static function int(mixed $v): int
    {
        self::assertIsInt($v);

        return $v;
    }

    private static function num(mixed $v): float
    {
        self::assertTrue(is_int($v) || is_float($v));

        return (float) $v;
    }
}
