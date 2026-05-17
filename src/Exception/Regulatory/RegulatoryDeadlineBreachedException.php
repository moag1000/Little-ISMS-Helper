<?php

declare(strict_types=1);

namespace App\Exception\Regulatory;

use App\Exception\AppException;

/**
 * Thrown when a regulatory notification deadline has been (or would be)
 * breached. Examples:
 *  - GDPR Art. 33: 72 hours from breach detection
 *  - DORA Art. 19: initial incident classification within 24 hours
 *  - NIS-2 Art. 23: early warning within 60 minutes for significant incidents
 *
 * The kernel.exception listener should log this to the immutable audit
 * trail (ISO 27001 Cl. 7.5.3) — a missed regulatory deadline is itself a
 * compliance event.
 */
final class RegulatoryDeadlineBreachedException extends AppException
{
    public function __construct(
        private readonly string $framework,
        private readonly string $deadline,
        private readonly ?string $entityIdentifier = null,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf(
                '%s deadline "%s" breached%s.',
                $framework,
                $deadline,
                $entityIdentifier !== null ? ' for '.$entityIdentifier : '',
            ),
            0,
            $previous,
        );
    }

    public static function gdpr72h(string $entityIdentifier): self
    {
        return new self('GDPR Art. 33', '72h', $entityIdentifier);
    }

    public static function dora24h(string $entityIdentifier): self
    {
        return new self('DORA Art. 19', '24h initial classification', $entityIdentifier);
    }

    public static function nis2EarlyWarning(string $entityIdentifier): self
    {
        return new self('NIS-2 Art. 23', '60min early warning', $entityIdentifier);
    }

    public function getFramework(): string
    {
        return $this->framework;
    }

    public function getDeadline(): string
    {
        return $this->deadline;
    }

    public function getEntityIdentifier(): ?string
    {
        return $this->entityIdentifier;
    }
}
