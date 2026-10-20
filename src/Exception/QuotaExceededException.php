<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** HTTP 402: the mailbox quota of all your plans is used up for this period. */
class QuotaExceededException extends ApiException
{
}
