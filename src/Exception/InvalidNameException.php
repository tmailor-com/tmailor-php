<?php

declare(strict_types=1);

namespace Tmailor\Exception;

/** 400 InvalidName: the custom name breaks the naming rules. `reason` holds the detail when the API sends it. */
class InvalidNameException extends BadRequestException
{
    public readonly ?string $reason;

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        string $errorCode = 'InvalidName',
        ?int $status = null,
        string $description = '',
        ?string $requestId = null,
        ?int $retryAfter = null,
        array $body = [],
        array $headers = [],
    ) {
        parent::__construct($message, $errorCode, $status, $description, $requestId, $retryAfter, $body, $headers);
        $r = $body['reason'] ?? null;
        $this->reason = is_string($r) ? $r : null;
    }
}
