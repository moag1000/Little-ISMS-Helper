<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Enum\RegisterType;

/** Scope interfaces from critical suppliers (4.3 c). */
final class RegisterScopeInterfaceProposalKind extends AbstractRegisterProposalKind
{
    protected function type(): RegisterType
    {
        return RegisterType::SCOPE_INTERFACE;
    }
}
