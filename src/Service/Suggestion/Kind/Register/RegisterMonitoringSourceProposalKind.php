<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Enum\RegisterType;

/** Monitored sources (A.8.16) from the cloud services in use (A.5.23). */
final class RegisterMonitoringSourceProposalKind extends AbstractRegisterProposalKind
{
    protected function type(): RegisterType
    {
        return RegisterType::MONITORING_SOURCE;
    }
}
