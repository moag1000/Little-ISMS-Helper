<?php

declare(strict_types=1);

namespace App\Exception\Import;

use App\Exception\AppException;

/**
 * Thrown when a bulk import fails at the file/job level (parsing,
 * structural validation, transactional rollback). Per-row failures use
 * {@see ImportRowInvalidException} instead.
 */
final class ImportFailedException extends AppException
{
    public function __construct(
        string $message,
        private readonly ?string $importType = null,
        private readonly ?string $sourceFile = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function forType(string $importType, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Import of type "%s" failed: %s', $importType, $reason),
            $importType,
            null,
            $previous,
        );
    }

    public function getImportType(): ?string
    {
        return $this->importType;
    }

    public function getSourceFile(): ?string
    {
        return $this->sourceFile;
    }
}
