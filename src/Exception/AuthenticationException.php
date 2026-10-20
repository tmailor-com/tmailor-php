<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** HTTP 401: missing, invalid or revoked API key. */
class AuthenticationException extends ApiException
{
}
