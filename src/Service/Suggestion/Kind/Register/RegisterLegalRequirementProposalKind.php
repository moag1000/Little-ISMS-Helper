<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Enum\RegisterType;

/** Legal and contractual requirements from frameworks, interested parties and context (4.2 c, A.5.31). */
final class RegisterLegalRequirementProposalKind extends AbstractRegisterProposalKind
{
    protected function type(): RegisterType
    {
        return RegisterType::LEGAL_REQUIREMENT;
    }
}
