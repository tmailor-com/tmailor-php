<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 400 InvalidDomain: `domain` is not one of your usable domains. */
class InvalidDomainException extends BadRequestException
{
}
