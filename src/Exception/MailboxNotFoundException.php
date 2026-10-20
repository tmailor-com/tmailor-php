<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 404 MailboxNotFound: the token is wrong, was rotated on https://platform.tmailor.com, belongs to another account, or the address was deleted. */
class MailboxNotFoundException extends NotFoundException
{
}
