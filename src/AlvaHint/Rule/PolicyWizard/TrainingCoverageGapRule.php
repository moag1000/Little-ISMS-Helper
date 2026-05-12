<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\UserRepository;

/**
 * Policy-Wizard W7-D — Tier-2 training-coverage gap.
 *
 * Fires on a Tenant when at least one published Policy-Wizard
 * Document (status `approved` or `published`, generated from a
 * PolicyTemplate) has < {@see self::THRESHOLD_PERCENT}% acknowledgement
 * coverage among the active-user audience. The CTA links to the
 * PolicyAcknowledgement inbox so the responsible Admin / CISO can
 * chase outstanding acknowledgements.
 *
 * Audience definition matches the W3-D
 * {@see \App\Service\ComplianceWizard\Check\PolicyWizard\PolicyAcknowledgementCoverageCheck}:
 * `User.isActive=true` rows in the tenant. Per-policy role-scoping
 * is out of scope for the W7-D MVP — the Alva hint is a
 * coarse-grained reminder, the PolicyAcknowledgementCoverageCheck
 * remains the audit-grade source of truth.
 *
 * Tier-2 (audit-gap) rather than Tier-1: the lack of acknowledgements
 * is recoverable; the audit risk is observable but not legally
 * deadlined the way DSGVO/BSIG hints are. Dismissible so individual
 * Admins can snooze while ack-campaign runs.
 *
 * Spec: `07-phase4-sprint-reconciliation.md` lines 309-311 (W7-D
 * Alva-Hints catalogue, training-coverage gap).
 */
final class TrainingCoverageGapRule extends AbstractAlvaHintRule
{
    /** Bump when the rule's threshold or condition changes. */
    public const VERSION = 1;

    /** Coverage strictly below this percentage triggers the hint. */
    public const THRESHOLD_PERCENT = 80.0;

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly PolicyAcknowledgementRepository $acknowledgementRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function key(): string
    {
        return 'policy_wizard.training_coverage_gap';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    /**
     * @return array<int, string>
     */
    public function requiredModules(): array
    {
        return ['policy_wizard'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Tenant) {
            return false;
        }
        return $this->firstUndercoveredPolicy($entity) !== null;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Tenant);

        $sample = $this->firstUndercoveredPolicy($entity);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'alva_hint.training_coverage_gap.title',
            bodyTranslationKey: 'alva_hint.training_coverage_gap.body',
            bodyTranslationParams: [
                '%document_title%' => $sample['title'] ?? '',
                '%coverage_percent%' => isset($sample['coverage'])
                    ? (string) $sample['coverage']
                    : '0',
                '%threshold_percent%' => (string) self::THRESHOLD_PERCENT,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'alva_hint.training_coverage_gap.cta_label',
            actionRoute: 'app_policy_ack_inbox',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_ADMIN', 'ROLE_GROUP_CISO'],
            mood: 'thinking',
            version: self::VERSION,
        );
    }

    /**
     * Return {title, coverage} of the first published Policy-Wizard
     * document whose acknowledgement coverage is strictly below the
     * threshold, or null when every policy meets the bar.
     *
     * @return array{title: string, coverage: float}|null
     */
    private function firstUndercoveredPolicy(Tenant $tenant): ?array
    {
        /** @var list<Document> $policies */
        $policies = $this->documentRepository->createQueryBuilder('d')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->getQuery()
            ->getResult();

        if ($policies === []) {
            return null;
        }

        $audience = count($this->userRepository->findActiveUsers());
        if ($audience === 0) {
            // Brand-new sandbox tenant — no users to acknowledge yet.
            return null;
        }

        foreach ($policies as $policy) {
            $acks = count($this->acknowledgementRepository->findByDocument($policy));
            $coverage = ($acks / $audience) * 100.0;
            if ($coverage < self::THRESHOLD_PERCENT) {
                return [
                    'title' => $policy->getOriginalFilename() ?? $policy->getFilename() ?? '',
                    'coverage' => round($coverage, 1),
                ];
            }
        }
        return null;
    }
}
