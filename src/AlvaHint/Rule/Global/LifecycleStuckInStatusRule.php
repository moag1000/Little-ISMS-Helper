<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tier-3 global AlvaHint rule: surfaces a hint when at least one Document
 * entity has been in a non-terminal lifecycle status for more than 14 days.
 *
 * "Non-terminal" covers transient statuses where human or automated action is
 * expected: draft, in_review, in_investigation, in_progress, in_triage,
 * under_assessment. Terminal statuses (approved, published, archived,
 * rejected, cancelled) are excluded — no action needed once an entity has
 * reached a settled state.
 *
 * The rule uses a COUNT query against Document (the widest-deployed entity
 * with a status + updatedAt column) as an efficient proxy. Additional entity
 * types can be wired in future iterations without changing the AlvaHint
 * framework.
 *
 * Trigger  : any page (appliesToPages returns [])
 * Module   : no module gate (lifecycle status is always relevant)
 * Tier     : 3 (efficiency / workflow hygiene)
 * Roles    : ROLE_MANAGER (managers are responsible for clearing stuck items)
 */
final class LifecycleStuckInStatusRule extends AbstractGlobalAlvaHintRule
{
    private const int STUCK_THRESHOLD_DAYS = 14;

    /** @var string[] */
    private const array NON_TERMINAL_STATUSES = [
        'draft',
        'in_review',
        'in_investigation',
        'in_progress',
        'in_triage',
        'under_assessment',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function key(): string
    {
        return 'global.lifecycle.stuck_in_status';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $count = $this->countStuckDocuments($tenant);

        if ($count === 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.lifecycle.stuck_in_status.title',
            bodyTranslationKey: 'global.lifecycle.stuck_in_status.body',
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
            actionLabelTranslationKey: 'global.lifecycle.stuck_in_status.action',
            actionRoute: 'app_document_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: 1,
        );
    }

    private function countStuckDocuments(Tenant $tenant): int
    {
        $threshold = new \DateTimeImmutable(sprintf('-%d days', self::STUCK_THRESHOLD_DAYS));

        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Document::class, 'd')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:non_terminal)')
            ->andWhere('d.updatedAt < :threshold')
            ->setParameter('tenant', $tenant)
            ->setParameter('non_terminal', self::NON_TERMINAL_STATUSES)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
