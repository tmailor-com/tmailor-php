<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 429 DailyCapReached: the daily request cap is reached (never retried automatically). */
class DailyCapReachedException extends RateLimitException
{
}
