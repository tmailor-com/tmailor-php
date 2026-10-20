<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** HTTP 409 (for example an Idempotency-Key reused with a different body, or still in progress). */
class ConflictException extends ApiException
{
}
