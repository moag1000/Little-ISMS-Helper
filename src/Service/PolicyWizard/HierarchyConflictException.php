<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use RuntimeException;

/**
 * Thrown by {@see WizardOrchestrator::complete} when the
 * `HierarchyOverrideValidator` reports unresolved Konzern-Tochter
 * conflicts. Generation must be blocked until each conflict is either
 * resolved (child tightens its setting) or escalated (Konzern-CISO
 * relaxes the parent).
 */
final class HierarchyConflictException extends RuntimeException
{
    /**
     * @param list<array<string, mixed>> $conflicts See HierarchyOverrideValidator::validate
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct(sprintf(
            'Hierarchy override conflicts blocking wizard generation: %d.',
            count($conflicts),
        ));
    }
}
