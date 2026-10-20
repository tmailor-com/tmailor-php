<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** The request did not get an HTTP response (DNS, TLS, connection refused or reset, ...). `status` is null. */
class ApiConnectionException extends TmailorException
{
    public function __construct(string $message = 'Connection error', string $errorCode = 'ConnectionError')
    {
        parent::__construct($message, $errorCode);
    }
}
