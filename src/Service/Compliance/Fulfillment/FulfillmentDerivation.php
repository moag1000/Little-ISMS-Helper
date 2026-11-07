<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

/**
 * Result of evaluating a requirement's rule over the organisation's data.
 *
 * percentage is null when the rule decides applicability only (every mapped
 * Annex-A control is excluded in the SoA). basis lists the contributors the
 * requirement page shows as "why": one row per control, mapping or maturity
 * level, each with the value it contributed.
 */
final readonly class FulfillmentDerivation
{
    /**
     * @param list<array<string, mixed>> $basis
     */
    public function __construct(
        public ?int $percentage,
        public string $kind,
        public array $basis,
        public ?bool $applicable = null,
        public ?string $applicabilityJustification = null,
    ) {
    }
}
