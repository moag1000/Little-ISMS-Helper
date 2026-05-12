<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ProcessingActivityRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tier-3 tip: tenant has 5+ processing activities and no VVT export in the last 30 days.
 *
 * Once a Verfahrensverzeichnis has at least 5 entries, the DPO can fulfil an
 * authority request (BfDI / LfDI) with a single click using the BfDI-Muster
 * XLSX export introduced in Sprint-2 (F25). This hint surfaces only when no
 * export has been logged in the last 30 days, keeping it relevant without
 * being repetitive.
 *
 * Trigger  : app_processing_activity_index, PA count >= 5, no export in last 30 days
 * Module   : privacy
 * Role     : ROLE_DPO
 */
final class VvtExportTipRule extends AbstractGlobalAlvaHintRule
{
    private const int THRESHOLD = 5;
    private const int EXPORT_WINDOW_DAYS = 30;

    public function __construct(
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function key(): string
    {
        return 'global.vvt_export_tip';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['privacy'];
    }

    public function appliesToPages(): array
    {
        return ['app_processing_activity_index'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $count = count($this->processingActivityRepository->findByTenant($tenant));

        if ($count < self::THRESHOLD) {
            return null;
        }

        if ($this->hasRecentExport($tenant)) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.vvt_export_tip.title',
            bodyTranslationKey: 'global.vvt_export_tip.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.vvt_export_tip.action',
            actionRoute: 'app_vvt_export_xlsx',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_DPO'],
            mood: 'thinking',
            version: 1,
        );
    }

    /**
     * Returns true when a VVT-BfDI export was logged within the last EXPORT_WINDOW_DAYS.
     */
    private function hasRecentExport(Tenant $tenant): bool
    {
        $since = new DateTimeImmutable(sprintf('-%d days', self::EXPORT_WINDOW_DAYS));

        $result = $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(AuditLog::class, 'a')
            ->innerJoin(User::class, 'u', 'WITH', 'u.email = a.userName')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('a.entityType = :entityType')
            ->andWhere('a.action = :action')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('entityType', 'VVT-BfDI')
            ->setParameter('action', 'export')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result > 0;
    }
}
