<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * Per-framework coverage result: satisfied vs the list of violations (gaps).
 * Drives the wizard coverage ampel.
 */
final readonly class FrameworkCoverage
{
    /**
     * @param list<ConstraintViolation> $violations
     */
    public function __construct(
        public string $framework,
        public int $totalConstrained,
        public array $violations,
    ) {
    }

    public function satisfiedCount(): int
    {
        return $this->totalConstrained - count($this->violations);
    }

    /** @return list<ConstraintViolation> regulatory (blocking) violations only */
    public function blockingViolations(): array
    {
        return array_values(array_filter($this->violations, static fn (ConstraintViolation $v): bool => $v->isBlocking()));
    }
}
