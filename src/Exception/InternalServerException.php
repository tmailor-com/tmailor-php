<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** HTTP 500 or another 5xx without a more specific class. */
class InternalServerException extends ApiException
{
}
