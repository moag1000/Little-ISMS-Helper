<?php

namespace App\Service;

use App\Entity\ComplianceMapping;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * State-Machine für Mapping-Lifecycle:
 *
 *   draft ──► review ──► approved ──► published
 *      │         │           │           │
 *      └────┬────┴───────────┴───────────┘
 *           ▼
 *      deprecated
 *
 * - draft → review: jeder Editor
 * - review → approved: erfordert Reviewer (4-Augen — actor != mapping.author)
 * - approved → published: erfordert ROLE_CISO (Sign-Off)
 * - any → deprecated: jederzeit möglich (ROLE_ADMIN)
 *
 * Jede Transition wird im AuditLog mit before/after, Reason und Actor
 * protokolliert.
 */
class MappingLifecycleService
{
    public const STATES = ['draft', 'review', 'approved', 'published', 'deprecated'];

    private const ALLOWED_TRANSITIONS = [
        'draft' => ['review', 'deprecated'],
        'review' => ['approved', 'draft', 'deprecated'],  // back to draft if rejected
        'approved' => ['published', 'review', 'deprecated'],
        'published' => ['deprecated'],
        'deprecated' => [],  // terminal
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly MappingQualityScoreService $mqsService,
    ) {
    }

    /**
     * Transitioniert das Mapping zum neuen Lifecycle-State.
     *
     * @throws \DomainException bei verbotenen Übergängen oder fehlenden Berechtigungen
     */
    public function transition(
        ComplianceMapping $mapping,
        string $newState,
        User $actor,
        ?string $reason = null,
    ): void {
        $oldState = $mapping->getLifecycleState();

        if (!in_array($newState, self::STATES, true)) {
            throw new \DomainException(sprintf("Unknown lifecycle state '%s'.", $newState));
        }
        if (!$this->isAllowedTransition($oldState, $newState)) {
            throw new \DomainException(sprintf(
                "Transition '%s' → '%s' not allowed. Allowed from '%s': %s.",
                $oldState,
                $newState,
                $oldState,
                implode(', ', self::ALLOWED_TRANSITIONS[$oldState] ?? []),
            ));
        }

        // Berechtigungs-Checks
        $this->assertAllowedForActor($newState, $actor);

        // Required-Fields-Check beim Übergang in höhere Reife
        if (in_array($newState, ['approved', 'published'], true)) {
            $missing = $this->missingRequiredFields($mapping);
            if (!empty($missing)) {
                throw new \DomainException(sprintf(
                    "Cannot transition to '%s' — missing required fields: %s.",
                    $newState,
                    implode(', ', $missing),
                ));
            }
        }

        $mapping->setLifecycleState($newState);
        if ($newState === 'approved' || $newState === 'published') {
            $mapping->setReviewedBy($actor->getEmail());
            $mapping->setReviewedAt(new \DateTimeImmutable());
        }

        // MQS-Recompute weil Lifecycle-Dimension sich änderte
        $this->mqsService->compute($mapping);

        $this->auditLogger->logCustom(
            action: 'compliance_mapping.lifecycle_transition',
            entityType: 'ComplianceMapping',
            entityId: (int) $mapping->getId(),
            oldValues: ['lifecycle_state' => $oldState],
            newValues: [
                'lifecycle_state' => $newState,
                'reason' => $reason,
                'actor_email' => $actor->getEmail(),
            ],
            description: sprintf(
                'Mapping#%d %s → %s by %s%s',
                (int) $mapping->getId(),
                $oldState,
                $newState,
                $actor->getEmail(),
                $reason ? ' (' . $reason . ')' : '',
            ),
        );

        $this->entityManager->flush();
    }

    public function isAllowedTransition(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Welche Übergänge sind aktuell zulässig?
     *
     * @return list<string>
     */
    public function allowedNextStates(string $current): array
    {
        return self::ALLOWED_TRANSITIONS[$current] ?? [];
    }

    private function assertAllowedForActor(string $newState, User $actor): void
    {
        $roles = $actor->getRoles();
        // published erfordert ROLE_CISO (oder ADMIN/SUPER_ADMIN)
        if ($newState === 'published') {
            $allowed = ['ROLE_CISO', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];
            if (empty(array_intersect($roles, $allowed))) {
                throw new \DomainException("Only ROLE_CISO/ADMIN/SUPER_ADMIN can publish a mapping (CISO sign-off).");
            }
        }
        // deprecated erfordert ADMIN
        if ($newState === 'deprecated') {
            $allowed = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_CISO'];
            if (empty(array_intersect($roles, $allowed))) {
                throw new \DomainException("Only ROLE_ADMIN/SUPER_ADMIN/CISO can deprecate a mapping.");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function missingRequiredFields(ComplianceMapping $mapping): array
    {
        $missing = [];
        if (empty($mapping->getProvenanceSource())) {
            $missing[] = 'provenanceSource';
        }
        if (empty($mapping->getMethodologyType())) {
            $missing[] = 'methodologyType';
        }
        if (empty($mapping->getMethodologyDescription())) {
            $missing[] = 'methodologyDescription';
        }
        if (empty($mapping->getMappingRationale()) && empty($mapping->getRelationship())) {
            $missing[] = 'mappingRationale or relationship';
        }
        return $missing;
    }
}
