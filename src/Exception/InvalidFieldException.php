<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 400 InvalidField: a parameter has a wrong value (for example a date that is not YYYY-MM-DD). */
class InvalidFieldException extends BadRequestException
{
}
