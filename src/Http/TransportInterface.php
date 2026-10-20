<?php

declare(strict_types=1);

namespace Tmailor\Http;

/**
 * Sends one HTTP request. The default is {@see CurlTransport}; pass your own to the Client (proxy settings, tests).
 *
 * A transport must not follow redirects and must not retry: the client applies the retry rules. When no HTTP
 * response is received it throws {@see TransportException} with the right kind (connect, read or timeout); any
 * HTTP status, including 4xx and 5xx, is returned as a Response.
 */
interface TransportInterface
{
    /**
     * @param float $timeout seconds for the whole request (connect, send and receive)
     *
     * @throws TransportException
     */
    public function send(Request $request, float $timeout): Response;
}
