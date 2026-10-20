<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 400 CustomNameNotAllowed: a custom name is only allowed on your own domain. */
class CustomNameNotAllowedException extends BadRequestException
{
}
