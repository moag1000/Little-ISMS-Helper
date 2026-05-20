<?php

declare(strict_types=1);

namespace App\Exception\Io;

use App\Exception\AppException;

/**
 * Thrown when a filesystem, network, serialisation, or encryption I/O
 * operation fails.
 *
 * Examples: ZIP archive cannot be created, JSON encoding returns false,
 * openssl_encrypt returns false, HTTP discovery fetch fails.
 *
 * @see App\Exception\AppException
 */
final class IoException extends AppException
{
    /**
     * @param string $message    Human-readable reason.
     * @param string|null $path  Relevant file path or URL (if applicable).
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message,
        private readonly ?string $path = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getPath(): ?string
    {
        return $this->path;
    }
}
