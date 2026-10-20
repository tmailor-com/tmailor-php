<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** HTTP 429. `retryAfter` tells how many seconds to wait. */
class RateLimitException extends ApiException
{
}
