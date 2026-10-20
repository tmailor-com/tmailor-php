<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 403 IpNotAllowed: the request IP is not in the allowlist of the account (set it on https://platform.tmailor.com). */
class IpNotAllowedException extends PermissionDeniedException
{
}
