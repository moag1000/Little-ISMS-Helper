<?php

declare(strict_types=1);

namespace App\Exception\InvalidArgument;

use App\Exception\AppException;

/**
 * Thrown when an argument or parameter value is invalid.
 *
 * Examples: unsupported enum value in a match expression,
 * invalid cron expression, unknown report type.
 *
 * @see App\Exception\AppException
 */
final class InvalidArgumentException extends AppException
{
    /**
     * @param string $message         Human-readable reason.
     * @param string|null $parameterName  Name of the offending parameter.
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message,
        private readonly ?string $parameterName = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getParameterName(): ?string
    {
        return $this->parameterName;
    }
}
