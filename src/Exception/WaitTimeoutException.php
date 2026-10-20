<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** `Mailbox::waitForMessage()` did not see a matching new message before its timeout (`errorCode` `WaitTimeout`). */
class WaitTimeoutException extends TmailorException
{
    public function __construct(string $message = 'No matching message arrived before the timeout')
    {
        parent::__construct($message, 'WaitTimeout');
    }
}
