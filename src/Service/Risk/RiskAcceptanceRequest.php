<?php

declare(strict_types=1);

namespace App\Service\Risk;

use App\Entity\Risk;

/**
 * A requested risk acceptance waiting for its approver (ISO 27005 §8.4.4) —
 * the duty of {@see RiskAcceptanceRequestSource}. Its own type, so the risk
 * itself stays with the risk owner's sources (review, treatment).
 */
final readonly class RiskAcceptanceRequest
{
    public function __construct(
        public Risk $risk,
    ) {
    }
}
