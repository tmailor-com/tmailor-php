<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/**
 * A base URL that the SDK refuses to send credentials to (not https, user info, query string, another host without
 * `allowUntrustedBaseUrl`, ...). This is an argument error, not a TmailorException.
 */
class BaseUrlException extends \InvalidArgumentException
{
}
