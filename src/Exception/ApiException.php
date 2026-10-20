<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** An error response from the API (any status outside 2xx; Review 20b PHP-04), or a 2xx response the SDK cannot trust. */
class ApiException extends TmailorException
{
}
