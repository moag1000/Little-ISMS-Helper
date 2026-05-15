<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Risk;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Risk;
use App\Entity\User;
use App\Repository\DataProtectionImpactAssessmentRepository;

/**
 * Sprint-2 P-7 Wave-2 trigger-1: Risk.requiresDPIA = true → DPIA-Anlage.
 *
 * GDPR Art. 35(1) — when a type of processing is likely to result in a
 * high risk to data subjects' rights, the controller MUST carry out a
 * DPIA. The Risk entity carries `requiresDPIA` as a self-declared
 * predicate (e.g. set during risk identification). The rule turns
 * that flag into an actionable hint when no DPIA is yet linked to
 * the same asset as the Risk.
 *
 * Heuristic for "no DPIA linked": Risk and DPIA share an Asset via
 * Risk.asset ←→ DPIA.relatedAsset. If the Risk has no Asset we fall
 * back to "user manually dismisses once a DPIA has been created" —
 * the hint is dismissible, version=1.
 *
 * Module-gated: `privacy` (GDPR is the legal basis; tenant without
 * GDPR scope sees nothing).
 *
 * Action: `app_dpia_new` with `?from_risk=<risk_id>` so the
 * controller can use {@see App\Service\PreFiller\DpiaPreFiller}
 * to copy the Risk context onto the DPIA skeleton.
 */
final class RequiresDpiaWithoutDpiaRule extends AbstractAlvaHintRule
{
    public function __construct(
        private readonly DataProtectionImpactAssessmentRepository $dpiaRepository,
    ) {
    }

    public function key(): string
    {
        return 'risk.requires_dpia_without_dpia';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['privacy'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Risk) {
            return false;
        }
        if (!$entity->isRequiresDPIA()) {
            return false;
        }

        // If the Risk is linked to an Asset, check whether a DPIA already
        // references that asset. If yes, hint is moot — DPO is already
        // aware. If no, fire.
        $asset = $entity->getAsset();
        if ($asset !== null) {
            $existing = $this->dpiaRepository->findOneBy(['relatedAsset' => $asset]);
            if ($existing instanceof DataProtectionImpactAssessment) {
                return false;
            }
        }

        return true;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Risk);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'risk.requires_dpia.title',
            bodyTranslationKey: 'risk.requires_dpia.body',
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Risk',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'risk.requires_dpia.action',
            actionRoute: 'app_dpia_new',
            actionRouteParams: ['from_risk' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_DPO'],
            mood: 'thinking',
            version: 1,
        );
    }
}
