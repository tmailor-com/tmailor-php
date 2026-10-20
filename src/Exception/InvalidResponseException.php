<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/**
 * The API answered 2xx with a body the SDK cannot trust: not JSON, a field missing or of the wrong JSON type, or a
 * pagination cursor that was already used. `errorCode` is `InvalidResponse`; `status` is the HTTP status when
 * known. The response body is never kept in the error (it can carry address tokens).
 */
class InvalidResponseException extends ApiException
{
    public function __construct(string $message, ?int $status = null, ?string $requestId = null)
    {
        parent::__construct($message, 'InvalidResponse', $status, '', $requestId);
    }
}
