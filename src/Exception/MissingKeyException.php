<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/**
 * HTTP 401 MissingKey, or raised by the SDK (`status` null) before anything is sent when an operation that needs
 * a key is called without one (`health()` works without a key).
 */
class MissingKeyException extends AuthenticationException
{
}
