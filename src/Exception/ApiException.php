<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** An error response (HTTP status >= 400) from the API, or a 2xx response the SDK cannot trust. */
class ApiException extends TmailorException
{
}
