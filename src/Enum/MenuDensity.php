<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * UI density setting for the mega-menu sidebar.
 * Maps to the three density levels exposed by the density-toggle Stimulus controller.
 */
enum MenuDensity: string
{
    case BASIC    = 'basic';
    case STANDARD = 'standard';
    case EXPERT   = 'expert';
}
