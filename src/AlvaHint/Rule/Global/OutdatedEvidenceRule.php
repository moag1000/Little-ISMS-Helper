<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ControlRepository;

/**
 * Tier-2 alert: tenant has ≥3 Controls with evidenceOutdated=true.
 *
 * When documents used as evidence on controls are updated (new version
 * uploaded), the affected controls are flagged. If 3 or more controls are
 * awaiting evidence re-verification, this hint surfaces the reviewer queue
 * so the ISB or Compliance Manager can act before ISO 27001 Cl.7.5.3 audit
 * evidence gaps accumulate.
 *
 * Trigger  : any page (global)
 * Tier     : 2 (alert — higher priority than tip)
 * Module   : none (Document is core)
 * Role     : ROLE_MANAGER
 * Route    : app_evidence_reverification_index (GET)
 */
final class OutdatedEvidenceRule extends AbstractGlobalAlvaHintRule
{
    private const int THRESHOLD = 3;

    public function __construct(
        private readonly ControlRepository $controlRepository,
    ) {}

    public function key(): string
    {
        return 'global.outdated_evidence';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return []; // Document / Evidence versioning is core (no module gate)
    }

    public function appliesToPages(): array
    {
        return []; // Fires on all pages
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $outdatedControls = $this->controlRepository->findEvidenceOutdated($tenant);
        $count = count($outdatedControls);

        if ($count < self::THRESHOLD) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.outdated_evidence.title',
            bodyTranslationKey: 'global.outdated_evidence.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.outdated_evidence.action',
            actionRoute: 'app_evidence_reverification_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'alert',
            version: 1,
        );
    }
}
