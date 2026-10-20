<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 503 IdempotencyUnavailable: the Idempotency-Key could not be checked, so nothing was created. Retry later with the same key. */
class IdempotencyUnavailableException extends ServiceUnavailableException
{
}
