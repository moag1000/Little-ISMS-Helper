<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Enum\RegisterType;

/** Opportunities from the last management review (6.1.1). */
final class RegisterOpportunityProposalKind extends AbstractRegisterProposalKind
{
    protected function type(): RegisterType
    {
        return RegisterType::OPPORTUNITY;
    }
}
