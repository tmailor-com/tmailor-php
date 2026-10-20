<?php

declare(strict_types=1);

namespace Tmailor\Internal;

use Tmailor\Exception as E;

/**
 * Maps an API error code (first) or an HTTP status to an exception class. Same table as the other Tmailor SDKs.
 *
 * @internal
 */
final class ErrorMap
{
    /** @var array<string, class-string<E\ApiException>> */
    public const CODES = [
        'InvalidJson' => E\InvalidJsonException::class,
        'InvalidField' => E\InvalidFieldException::class,
        'InvalidDomain' => E\InvalidDomainException::class,
        'CustomNameNotAllowed' => E\CustomNameNotAllowedException::class,
        'InvalidName' => E\InvalidNameException::class,
        'MissingKey' => E\MissingKeyException::class,
        'InvalidKey' => E\InvalidKeyException::class,
        'KeyRevoked' => E\KeyRevokedException::class,
        'QuotaExceeded' => E\QuotaExceededException::class,
        'AccountLocked' => E\AccountLockedException::class,
        'IpNotAllowed' => E\IpNotAllowedException::class,
        'NotFound' => E\NotFoundException::class,
        'MailboxNotFound' => E\MailboxNotFoundException::class,
        'MessageNotFound' => E\MessageNotFoundException::class,
        'MethodNotAllowed' => E\MethodNotAllowedException::class,
        'MailboxExists' => E\MailboxExistsException::class,
        'Conflict' => E\ConflictException::class,
        'RateLimited' => E\RateLimitedException::class,
        'DailyCapReached' => E\DailyCapReachedException::class,
        'InternalError' => E\InternalServerException::class,
        'NoDomainAvailable' => E\NoDomainAvailableException::class,
        'IdempotencyUnavailable' => E\IdempotencyUnavailableException::class,
        'CacheUnavailable' => E\CacheUnavailableException::class,
    ];

    /** @var array<int, class-string<E\ApiException>> */
    public const STATUSES = [
        400 => E\BadRequestException::class,
        401 => E\AuthenticationException::class,
        402 => E\QuotaExceededException::class,
        403 => E\PermissionDeniedException::class,
        404 => E\NotFoundException::class,
        405 => E\MethodNotAllowedException::class,
        409 => E\ConflictException::class,
        413 => E\BadRequestException::class,
        429 => E\RateLimitException::class,
        500 => E\InternalServerException::class,
        503 => E\ServiceUnavailableException::class,
    ];

    /** @return class-string<E\ApiException> */
    public static function classFor(?string $code, int $status): string
    {
        if ($code !== null && $code !== '' && isset(self::CODES[$code])) {
            return self::CODES[$code];
        }
        if (isset(self::STATUSES[$status])) {
            return self::STATUSES[$status];
        }
        if ($status >= 500) {
            return E\InternalServerException::class;
        }

        return E\ApiException::class;
    }
}
