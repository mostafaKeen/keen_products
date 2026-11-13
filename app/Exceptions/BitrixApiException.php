<?php

namespace App\Exceptions;

use RuntimeException;

class BitrixApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $errorDescription = '',
        public readonly int $httpStatus = 400,
        public readonly ?int $operatingResetAt = null,
        public readonly array $payload = [],
    ) {
        $message = $errorDescription !== ''
            ? "{$errorCode}: {$errorDescription}"
            : $errorCode;

        parent::__construct($message, $httpStatus);
    }

    public function isExpiredToken(): bool
    {
        return $this->httpStatus === 401
            || strcasecmp($this->errorCode, 'expired_token') === 0
            || strcasecmp($this->errorCode, 'INVALID_CREDENTIALS') === 0;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 503
            || strcasecmp($this->errorCode, 'QUERY_LIMIT_EXCEEDED') === 0
            || strcasecmp($this->errorCode, 'OVERLOAD_LIMIT') === 0;
    }

    public function isOperatingLimit(): bool
    {
        return $this->httpStatus === 429
            || strcasecmp($this->errorCode, 'OPERATION_TIME_LIMIT') === 0;
    }

    public function isSpaRestricted(): bool
    {
        return strcasecmp($this->errorCode, 'CREATE_DYNAMIC_TYPE_RESTRICTED') === 0;
    }

    public function retryAfterSeconds(int $default = 10): int
    {
        if ($this->operatingResetAt !== null && $this->operatingResetAt > time()) {
            return max(5, min(600, $this->operatingResetAt - time() + 2));
        }

        if ($this->isRateLimited()) {
            return 2;
        }

        return $default;
    }
}
