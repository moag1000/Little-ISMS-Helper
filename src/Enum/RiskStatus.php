<?php

declare(strict_types=1);

namespace App\Enum;

enum RiskStatus: string
{
    case Identified = 'identified';
    case Assessed = 'assessed';
    case Treated = 'treated';
    case Monitored = 'monitored';
    case Closed = 'closed';
    case Accepted = 'accepted';
}
