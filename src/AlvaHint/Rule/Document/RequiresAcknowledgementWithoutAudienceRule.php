<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Document;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Document;
use App\Entity\User;

/**
 * Sprint-2 P-7 Wave-2 trigger-4: Document.requiresAcknowledgement = true
 * → Acknowledgement-Audience-Picker.
 *
 * ISO 27001 A.6.3 — policies and supporting documentation must be
 * communicated and acknowledged by the relevant audience. The
 * Document entity flag `requiresAcknowledgement` triggers
 * {@see App\EventListener\AutoReactionAcknowledgementCampaignListener}
 * which fans out PolicyAcknowledgement rows. By default the fan-out
 * targets the WHOLE active tenant population — but many policies
 * only apply to a subset (e.g. cleaning-services SOP for facility
 * staff, secure-coding-guideline for developers).
 *
 * Wave-2 introduces Document::$acknowledgementAudience (Many2Many
 * → User). When empty, legacy "broadcast to all" behaviour applies.
 * When populated, audit-ready targeted campaigns become possible.
 *
 * This rule fires when:
 *   - requiresAcknowledgement = true AND
 *   - acknowledgementAudience collection is empty
 *
 * Not module-gated (Awareness is ISO 27001 base; every tenant has it).
 * Role-gated ROLE_MANAGER (document owner / approver decision).
 */
final class RequiresAcknowledgementWithoutAudienceRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'document.requires_acknowledgement_without_audience';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        // No module gate — Awareness is ISO 27001 base requirement.
        return [];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Document) {
            return false;
        }
        if (!$entity->getRequiresAcknowledgement()) {
            return false;
        }

        return $entity->getAcknowledgementAudience()->isEmpty();
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Document);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'document.acknowledgement_audience.title',
            bodyTranslationKey: 'document.acknowledgement_audience.body',
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Document',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'document.acknowledgement_audience.action',
            actionRoute: 'app_document_acknowledgement_audience_picker',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: 1,
        );
    }
}
