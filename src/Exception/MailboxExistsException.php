<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 409 MailboxExists: the address is already in use. Also raised for a name on your own domain that was created on the tmailor.com website or mobile app: such an address cannot be used through the API, pick another name. */
class MailboxExistsException extends ConflictException
{
}
