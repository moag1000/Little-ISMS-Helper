<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Enum\RegisterType;

/**
 * Opportunities (6.1.1). The management review's improvement opportunities
 * moved to the improvement register (10.1, {@see RegisterImprovementProposalKind});
 * this kind stays so proposals offered before resolve (accepting one that the
 * improvement register took meanwhile answers "already applied").
 */
final class RegisterOpportunityProposalKind extends AbstractRegisterProposalKind
{
    protected function type(): RegisterType
    {
        return RegisterType::OPPORTUNITY;
    }
}
