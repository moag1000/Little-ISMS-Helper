<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Enum\RegisterType;

/** Default competences per role in the role register (7.2). */
final class RegisterCompetenceProposalKind extends AbstractRegisterProposalKind
{
    protected function type(): RegisterType
    {
        return RegisterType::COMPETENCE;
    }
}
