<?php

declare(strict_types=1);

namespace App\Enum;

enum TreatmentStrategy: string
{
    case Accept = 'accept';
    case Mitigate = 'mitigate';
    case Transfer = 'transfer';
    case Avoid = 'avoid';
}
