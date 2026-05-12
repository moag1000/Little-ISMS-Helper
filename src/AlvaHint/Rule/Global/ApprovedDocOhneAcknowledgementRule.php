<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tier-2 hint: Approved documents without any policy acknowledgement record.
 *
 * ISO 27001 A.6.3 / Cl. 7.3 awareness requires demonstrable employee
 * acknowledgement of published mandatory policies. Zero-acknowledgement
 * is an audit gap on every published policy.
 */
final class ApprovedDocOhneAcknowledgementRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function key(): string
    {
        return 'global.approved_doc_ohne_ack';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return [];
    }

    public function appliesToPages(): array
    {
        return [
            'document_index',
            'dashboard_ciso',
            'dashboard_compliance_manager',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Count approved documents without any acknowledgement for this tenant
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(d.id) FROM App\Entity\Document d
             WHERE d.tenant = :tenant
             AND d.status = :status
             AND d.id NOT IN (
                 SELECT IDENTITY(pa.document) FROM App\Entity\PolicyAcknowledgement pa
                 WHERE pa.document IS NOT NULL
             )',
        )
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'approved')
            ->getSingleScalarResult();

        if ($count <= 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.approved_doc_ohne_ack.title',
            bodyTranslationKey: 'global.approved_doc_ohne_ack.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.approved_doc_ohne_ack.action',
            actionRoute: 'app_document_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
