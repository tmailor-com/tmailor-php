<?php

declare(strict_types=1);

namespace Tmailor\Tests\Support;

use Tmailor\Http\Request;
use Tmailor\Http\Response;
use Tmailor\Http\TransportInterface;

/** Test transport: hands every request to a callback and records it. */
final class CallbackTransport implements TransportInterface
{
    /** @var list<Request> */
    public array $requests = [];
    /** @var list<float> */
    public array $timeouts = [];
    /** @var \Closure(Request, float): Response */
    private \Closure $handler;

    /** @param callable(Request, float): Response $handler */
    public function __construct(callable $handler)
    {
        $this->handler = \Closure::fromCallable($handler);
    }

    /**
     * A transport that returns the given responses in order (or throws the given exceptions).
     *
     * @param list<Response|\Throwable> $responses
     */
    public static function sequence(array $responses): self
    {
        return new self(static function () use (&$responses): Response {
            $next = array_shift($responses);
            if ($next === null) {
                throw new \LogicException('unexpected extra request');
            }
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string>   $headers
     */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function send(Request $request, float $timeout): Response
    {
        $this->requests[] = $request;
        $this->timeouts[] = $timeout;

        return ($this->handler)($request, $timeout);
    }
}
