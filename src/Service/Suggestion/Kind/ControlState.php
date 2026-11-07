<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Control;

/** Shared reading of a control's state for the derivation kinds. */
final class ControlState
{
    public const array EFFECTIVE = ['effective', 'highly_effective'];

    public static function isImplemented(Control $control): bool
    {
        return in_array($control->getImplementationStatus(), ['implemented', 'verified'], true);
    }

    public static function isEffective(Control $control): bool
    {
        return self::isImplemented($control) && in_array($control->getEffectiveness(), self::EFFECTIVE, true);
    }

    public static function label(Control $control): string
    {
        return trim(($control->getControlId() ?? '') . ' ' . ($control->getName() ?? ''));
    }
}
