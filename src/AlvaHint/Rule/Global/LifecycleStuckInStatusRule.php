<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;

/**
 * Tier-3 global AlvaHint rule: surfaces a hint when at least one Document has
 * been in a non-terminal lifecycle status for more than 14 days.
 *
 * The action deep-links to EXACTLY the documents it counts: a single stuck
 * document opens that document; several open the document index pre-filtered to
 * `focus=lifecycle_stuck` (same criteria, via DocumentRepository). The hint and
 * the filter share DocumentRepository::findStuckInLifecycle() so they can never
 * disagree.
 *
 * Tier 3 (workflow hygiene), ROLE_MANAGER, no module gate.
 */
final class LifecycleStuckInStatusRule extends AbstractGlobalAlvaHintRule
{
    private const int STUCK_THRESHOLD_DAYS = 14;

    public function __construct(
        private readonly DocumentRepository $documentRepository,
    ) {}

    public function key(): string
    {
        return 'alva_hint.lifecycle.stuck_in_status';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $stuck = $this->documentRepository->findStuckInLifecycle($tenant, self::STUCK_THRESHOLD_DAYS);
        $count = count($stuck);

        if ($count === 0) {
            return null;
        }

        // Deep-link to exactly what the hint is about: one → that document,
        // several → the document index pre-filtered to the same set.
        if ($count === 1) {
            $route = 'app_document_show';
            $params = ['id' => $stuck[0]->getId() ?? 0];
        } else {
            $route = 'app_document_index';
            $params = ['focus' => 'lifecycle_stuck'];
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'alva_hint.lifecycle.stuck_in_status.title',
            bodyTranslationKey: 'alva_hint.lifecycle.stuck_in_status.body',
            bodyTranslationParams: [
                '%count%' => (string) $count,
                '%days%' => (string) self::STUCK_THRESHOLD_DAYS,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'alva_hint.lifecycle.stuck_in_status.action',
            actionRoute: $route,
            actionRouteParams: $params,
            actionMethod: 'GET',
            requiredRoles: ['ROLE_USER'],
            mood: 'thinking',
            version: 1,
        );
    }
}
