<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * Generic enum exposure for Twig templates.
 *
 * Lets filter dropdowns and other UI components read their choices from a
 * single source of truth (the PHP enum) instead of redeclaring values in
 * the template. Prevents drift between FormType choices and filter UIs.
 */
class EnumExtension
{
    /**
     * Return cases of a backed enum as `[value => name]` pairs ready for
     * iteration in Twig.
     *
     * Usage in Twig:
     *   {% for value, name in enum_cases('App\\Enum\\IncidentStatus') %}
     *       <option value="{{ value }}">{{ ('incident.status.' ~ value)|trans({}, 'incident') }}</option>
     *   {% endfor %}
     *
     * @return array<string|int, string>
     */
    #[AsTwigFunction('enum_cases')]
    public function cases(string $enumClass): array
    {
        if (!enum_exists($enumClass)) {
            return [];
        }

        $out = [];
        foreach ($enumClass::cases() as $case) {
            $value = property_exists($case, 'value') ? $case->value : $case->name;
            $out[$value] = $case->name;
        }

        return $out;
    }
}
