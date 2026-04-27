<?php

declare(strict_types=1);

namespace App\Enum;

enum IncidentStatus: string
{
    case Reported = 'reported';
    case InInvestigation = 'in_investigation';
    case InResolution = 'in_resolution';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
