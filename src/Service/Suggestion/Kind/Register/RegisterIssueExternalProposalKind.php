<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Enum\RegisterType;

/** External issues from the context free text (ISO 27001 4.1). */
final class RegisterIssueExternalProposalKind extends AbstractRegisterProposalKind
{
    protected function type(): RegisterType
    {
        return RegisterType::ISSUE_EXTERNAL;
    }
}
