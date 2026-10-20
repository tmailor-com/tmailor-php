<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** The HTTP request timed out (`errorCode` `Timeout`). */
class ApiTimeoutException extends ApiConnectionException
{
    public function __construct(string $message = 'Request timed out')
    {
        parent::__construct($message, 'Timeout');
    }
}
