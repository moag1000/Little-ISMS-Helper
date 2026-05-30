<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * Decides whether a parameter's policy section is included, from its
 * `template_slot.section_if` condition evaluated against the resolved value.
 * Supported: { not: X } and { exists: true }. No condition => always active.
 */
final class PolicySectionResolver
{
    public function isActive(ParameterDefinition $def, mixed $value): bool
    {
        $condition = $def->templateSlot['section_if'] ?? null;
        if ($condition === null) {
            return true;
        }

        if (array_key_exists('not', $condition)) {
            return $value !== $condition['not'];
        }

        if (array_key_exists('exists', $condition)) {
            return $condition['exists'] ? $value !== null : $value === null;
        }

        return true;
    }
}
