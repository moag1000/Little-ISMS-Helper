<?php

declare(strict_types=1);

namespace App\Enum;

enum RiskStatus: string
{
    case Identified = 'identified';
    case Assessed = 'assessed';
    case InTreatment = 'in_treatment';
    case Treated = 'treated';
    case Mitigated = 'mitigated';
    case Monitored = 'monitored';
    case Closed = 'closed';
    case Accepted = 'accepted';
    case Open = 'open';

    /**
     * Single source for "handled": closed, accepted, mitigated, treated or
     * monitored (post-treatment monitoring, ISO 27005). Every other case —
     * including InTreatment — is still open / unmitigated.
     */
    public function isHandled(): bool
    {
        return match ($this) {
            self::Closed, self::Accepted, self::Mitigated, self::Treated, self::Monitored => true,
            self::Identified, self::Assessed, self::InTreatment, self::Open => false,
        };
    }

    /**
     * Single source for "is re-assessed at planned intervals": every risk
     * except a closed one — handled risks too, accepted risks in particular
     * (ISO 27001 8.2, ISO 27005). Review reminders and the data-integrity
     * "review date passed" check both use it.
     */
    public function requiresPeriodicReview(): bool
    {
        return $this !== self::Closed;
    }
}
