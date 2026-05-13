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
 * Tier-2 warning: last VVT-BfDI export is older than 365 days (or never happened).
 *
 * Supervisory authorities (BfDI and 16 state DPAs) may request the
 * Verfahrensverzeichnis (Art. 30 DSGVO) at any time. Best practice is an
 * annual refresh. When the last BfDI-export event is more than 365 days old
 * this rule fires as a warning-tier hint, prompting the DPO to export via
 * the Sprint-2 F25 BfDI-Muster XLSX download.
 *
 * Unlike the tip (VvtExportTipRule), this rule fires on dashboard pages and
 * is Tier 2 (audit-gap). Snooze still works — permanent dismissal is not
 * blocked — but re-surfacing after 30 days of dismissal is handled by the
 * service version-key rotation (`@v1` bumped when threshold changes).
 *
 * Trigger  : dashboard_ciso / dashboard_compliance_manager / inbox,
 *            PA count > 0, last export > 365 days ago OR never
 * Module   : privacy
 * Role     : ROLE_DPO
 */
final class VvtExportOverdueRule extends AbstractGlobalAlvaHintRule
{
    private const int OVERDUE_DAYS = 365;

    public function __construct(
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function key(): string
    {
        return 'global.vvt_export_overdue';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['privacy'];
    }

    public function appliesToPages(): array
    {
        return [
            'dashboard_ciso',
            'dashboard_compliance_manager',
            'inbox',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $count = count($this->processingActivityRepository->findByTenant($tenant));

        if ($count === 0) {
            return null;
        }

        $lastExportDate = $this->findLastExportDate($tenant);
        $cutoff = new DateTimeImmutable(sprintf('-%d days', self::OVERDUE_DAYS));

        if ($lastExportDate !== null && $lastExportDate >= $cutoff) {
            return null;
        }

        $months = $lastExportDate !== null
            ? (int) round((new DateTimeImmutable())->diff($lastExportDate)->days / 30)
            : 0;

        $bodyParams = $lastExportDate !== null
            ? ['%months%' => (string) $months]
            : ['%months%' => '12+'];

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.vvt_export_overdue.title',
            bodyTranslationKey: 'global.vvt_export_overdue.body',
            bodyTranslationParams: $bodyParams,
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.vvt_export_overdue.action',
            actionRoute: 'app_vvt_export_xlsx',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_DPO'],
            mood: 'warning',
            version: 1,
        );
    }

    /**
     * Returns the most recent VVT-BfDI export date for this tenant, or null
     * if no export has ever been logged.
     */
    private function findLastExportDate(Tenant $tenant): ?DateTimeImmutable
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('MAX(a.createdAt)')
            ->from(AuditLog::class, 'a')
            ->innerJoin(User::class, 'u', 'ON', 'u.email = a.userName')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('a.entityType = :entityType')
            ->andWhere('a.action = :action')
            ->setParameter('tenant', $tenant)
            ->setParameter('entityType', 'VVT-BfDI')
            ->setParameter('action', 'export')
            ->getQuery()
            ->getSingleScalarResult();

        if ($result === null) {
            return null;
        }

        return new DateTimeImmutable($result);
    }
}
