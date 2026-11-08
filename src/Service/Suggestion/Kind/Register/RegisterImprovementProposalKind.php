<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Enum\RegisterType;

/** Improvements (10.1) from the management review, audit opportunities and lessons learned. */
final class RegisterImprovementProposalKind extends AbstractRegisterProposalKind
{
    protected function type(): RegisterType
    {
        return RegisterType::IMPROVEMENT;
    }
}
