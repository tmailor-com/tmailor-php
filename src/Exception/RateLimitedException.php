<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 429 RateLimited: too many requests in the current minute. */
class RateLimitedException extends RateLimitException
{
}
