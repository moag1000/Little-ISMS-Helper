<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * S12 / ISO 27001 6.1.3 f, 8.3: what the approver accepted — the residual
 * rating — does not change while the acceptance stands. The form shows it
 * read-only; this constraint refuses the same change on every other write
 * path that validates (REST, assistant via MCP). A new rating goes through
 * "Neu bewerten oder verlängern" (RiskAcceptanceWorkflowService::requestReevaluation()).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AcceptedRiskUnchanged extends Constraint
{
    /** Properties frozen while the risk is formally accepted. */
    public const array FROZEN = ['residualProbability', 'residualImpact'];

    public string $message = 'risk.validation.accepted_residual_frozen';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
