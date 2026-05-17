<?php

declare(strict_types=1);

namespace App\Exception\Regulatory;

use App\Exception\AppException;

/**
 * Thrown when an operation requires a compliance framework that is not
 * activated for the current tenant (e.g. trying to run a DORA report
 * without nis2_dora module enabled).
 */
final class FrameworkNotActivatedException extends AppException
{
    public function __construct(
        private readonly string $frameworkCode,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf('Compliance framework "%s" is not activated for this tenant.', $frameworkCode),
            0,
            $previous,
        );
    }

    public function getFrameworkCode(): string
    {
        return $this->frameworkCode;
    }
}
