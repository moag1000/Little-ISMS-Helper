<?php

declare(strict_types=1);

namespace App\Exception\Import;

use App\Exception\AppException;

/**
 * Thrown when a single row in a bulk import is invalid.
 *
 * Carries the row number (1-based, matches user-visible CSV/XLSX row)
 * plus a list of error strings for UI display in the import preview /
 * delta view.
 */
final class ImportRowInvalidException extends AppException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly int $rowNumber,
        private readonly array $errors,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf(
                'Import row %d is invalid: %s',
                $rowNumber,
                \implode('; ', $errors) ?: 'unknown error',
            ),
            0,
            $previous,
        );
    }

    public function getRowNumber(): int
    {
        return $this->rowNumber;
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
