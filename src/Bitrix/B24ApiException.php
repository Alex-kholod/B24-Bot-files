<?php

declare(strict_types=1);

namespace B24DocsBot\Bitrix;

use RuntimeException;
use Throwable;

final class B24ApiException extends RuntimeException
{
    private const TRANSIENT_CODES = [
        'QUERY_LIMIT_EXCEEDED',
        'OPERATION_TIME_LIMIT',
        'OVERLOAD_LIMIT',
        'INTERNAL_SERVER_ERROR',
        'ERROR_UNEXPECTED_ANSWER',
        'NETWORK_ERROR',
    ];

    public function __construct(
        string $message,
        private readonly string $errorCode = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function isTransient(): bool
    {
        return in_array($this->errorCode, self::TRANSIENT_CODES, true);
    }
}
