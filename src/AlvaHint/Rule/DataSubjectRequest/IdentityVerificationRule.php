<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\DataSubjectRequest;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\DataSubjectRequest;
use App\Entity\User;
use App\Enum\DataSubjectRequestStatus;

/**
 * Tier-1 hint: GDPR Art. 12(6) identity verification before disclosure.
 *
 * Verification method is captured but the requester has not yet been
 * verified. Releasing data on an unverified DSR is itself a personal-
 * data breach (Art. 5(1)(f)) and exposes the controller to Stage-2
 * fines. Hint flags it before the responder responds.
 */
final class IdentityVerificationRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'data_subject_request.identity_verification';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['compliance'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof DataSubjectRequest) {
            return false;
        }
        if ($entity->isIdentityVerified()) {
            return false;
        }
        $method = trim((string) $entity->getIdentityVerificationMethod());
        if ($method === '') {
            return false;
        }

        return in_array($entity->getStatus(), [DataSubjectRequestStatus::Received->value, DataSubjectRequestStatus::InProgress->value], true);
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof DataSubjectRequest);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'data_subject_request.identity.title',
            bodyTranslationKey: 'data_subject_request.identity.body',
            bodyTranslationParams: [
                '%method%' => (string) $entity->getIdentityVerificationMethod(),
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 1,
            dismissible: false,
            entityType: 'DataSubjectRequest',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'data_subject_request.identity.action',
            actionRoute: 'app_data_subject_request_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            mood: 'thinking',
        );
    }
}
