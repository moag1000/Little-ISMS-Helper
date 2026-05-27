<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditFinding;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;

/**
 * Single transitive coverage edge: a finding linked to one requirement closes
 * another requirement in a different framework via {@see ComplianceMapping}.
 *
 * The finding is assigned by the service after the edge is built — kept
 * mutable to avoid a second pass over the mappings graph.
 */
final class TransitiveCoverage
{
    public ?AuditFinding $finding = null;

    public function __construct(
        public readonly ComplianceFramework $targetFramework,
        public readonly ComplianceRequirement $targetRequirement,
        public readonly ComplianceMapping $mapping,
        public readonly string $direction,
        public readonly int $percentage,
    ) {
    }
}
