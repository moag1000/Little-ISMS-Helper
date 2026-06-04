<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\ProcessingActivity;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use App\Enum\ProcessingActivityStatus;

/**
 * Tier-2 advisory hint (N-6): a marketing purpose that does not rest on consent
 * or legitimate interests.
 *
 * Direct marketing under the GDPR is lawful under consent (Art. 6(1)(a)) or
 * legitimate interests (Art. 6(1)(f), Recital 47), but practically never under
 * contract, legal obligation, vital interests or public task. A marketing
 * activity declared on one of those bases is most likely a mis-selected legal
 * basis — surfaced as a nudge rather than a hard block, because edge cases
 * exist and the DPO should decide.
 */
final class PurposeLegalBasisMismatchRule extends AbstractAlvaHintRule
{
    private const array MARKETING_COMPATIBLE_BASES = ['consent', 'legitimate_interests'];

    public function key(): string
    {
        return 'processing_activity.purpose_legal_basis_mismatch';
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
        if (!$entity instanceof ProcessingActivity) {
            return false;
        }
        if ($entity->getStatus() === ProcessingActivityStatus::Draft->value) {
            return false;
        }

        $purposes = $entity->getPurposes();
        if (!in_array('marketing', $purposes, true)) {
            return false;
        }

        return !in_array($entity->getLegalBasis(), self::MARKETING_COMPATIBLE_BASES, true);
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof ProcessingActivity);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'processing_activity.purpose_legal_basis_mismatch.title',
            bodyTranslationKey: 'processing_activity.purpose_legal_basis_mismatch.body',
            bodyTranslationParams: [
                '%basis%' => (string) $entity->getLegalBasis(),
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'ProcessingActivity',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'processing_activity.purpose_legal_basis_mismatch.action',
            actionRoute: 'app_processing_activity_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            mood: 'thinking',
        );
    }
}
