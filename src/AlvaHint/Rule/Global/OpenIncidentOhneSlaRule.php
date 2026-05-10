<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tier-1 hint: Open GDPR-related incidents older than 24h without
 * an authority notification recorded.
 *
 * DSGVO Art. 33 mandates supervisory authority notification within 72h.
 * An open high-severity incident touching personal data > 24h old is
 * a direct early-warning signal before the deadline is missed.
 */
final class OpenIncidentOhneSlaRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function key(): string
    {
        return 'global.open_incident_ohne_sla';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['privacy'];
    }

    public function appliesToPages(): array
    {
        return [
            'incident_index',
            'data_breach_index',
            'dashboard_ciso',
            'dashboard_compliance_manager',
            'dashboard_auditor',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $threshold = new DateTimeImmutable('-24 hours');

        // Count open high-severity privacy incidents older than 24h
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(i.id) FROM App\Entity\Incident i
             WHERE i.tenant = :tenant
             AND i.status IN (:status)
             AND i.severity IN (:severities)
             AND i.category = :category
             AND i.createdAt <= :threshold',
        )
            ->setParameter('tenant', $tenant)
            ->setParameter('status', ['reported', 'in_investigation', 'in_resolution'])
            ->setParameter('severities', ['high', 'critical'])
            ->setParameter('category', 'privacy')
            ->setParameter('threshold', $threshold)
            ->getSingleScalarResult();

        if ($count <= 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.open_incident_ohne_sla.title',
            bodyTranslationKey: 'global.open_incident_ohne_sla.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.open_incident_ohne_sla.action',
            actionRoute: 'app_incident_index',
            actionRouteParams: [],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'alert',
        );
    }
}
