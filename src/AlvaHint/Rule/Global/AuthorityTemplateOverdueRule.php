<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataBreachRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tier-2 warning: DataBreach without authority-export > 24h after detectedAt (DSGVO 72h deadline).
 *
 * GDPR Art. 33 requires notification to the supervisory authority within 72 hours
 * of becoming aware of a personal data breach. When a high/critical DataBreach
 * has been open for more than 24 hours without a documented authority-export event
 * this rule fires as a warning-tier hint to remind the DPO to use the
 * EU-Behörden-Export feature (F26).
 *
 * Trigger  : dashboard_ciso / dashboard_compliance_manager / inbox,
 *            DataBreach count > 0 for tenant, breach detected > 24h ago without export
 * Module   : privacy + eu_authority_reporting
 * Role     : ROLE_DPO (fallback ROLE_MANAGER)
 * Dismiss  : authority_template_overdue@v1
 */
final class AuthorityTemplateOverdueRule extends AbstractGlobalAlvaHintRule
{
    private const int HOURS_THRESHOLD = 24;

    public function __construct(
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function key(): string
    {
        return 'global.authority_template_overdue';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['privacy', 'eu_authority_reporting'];
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
        $breaches = $this->dataBreachRepository->findByTenant($tenant);

        if (empty($breaches)) {
            return null;
        }

        $cutoff = new DateTimeImmutable(sprintf('-%d hours', self::HOURS_THRESHOLD));
        $overdueCount = 0;

        foreach ($breaches as $breach) {
            if (!in_array($breach->getSeverity(), ['high', 'critical'], true)) {
                continue;
            }
            if ($breach->getSupervisoryAuthorityNotifiedAt() !== null) {
                continue;
            }
            $detectedAt = $breach->getEffectiveDetectedAt() ?? $breach->getDetectedAt();
            if ($detectedAt === null) {
                continue;
            }
            if ($detectedAt <= $cutoff) {
                // Check if an authority export has already been logged
                if (!$this->hasExportEvent($tenant, $breach->getId())) {
                    $overdueCount++;
                }
            }
        }

        if ($overdueCount === 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.authority_template_overdue.title',
            bodyTranslationKey: 'global.authority_template_overdue.body',
            bodyTranslationParams: ['%count%' => (string) $overdueCount],
            translationDomain: 'eu_authorities',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.authority_template_overdue.action',
            actionRoute: 'app_authority_notification_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_DPO', 'ROLE_MANAGER'],
            mood: 'warning',
            version: 1,
        );
    }

    /**
     * Check if an authority notification export has been logged for this tenant
     * within the last 72 hours (any authority key).
     */
    private function hasExportEvent(Tenant $tenant, ?int $breachId): bool
    {
        $cutoff = new DateTimeImmutable('-72 hours');

        $result = $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(AuditLog::class, 'a')
            ->innerJoin(User::class, 'u', 'WITH', 'u.email = a.userName')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('a.action = :action')
            ->andWhere('a.entityId = :breachId OR a.entityId IS NULL')
            ->andWhere('a.createdAt >= :cutoff')
            ->setParameter('tenant', $tenant)
            ->setParameter('action', 'export')
            ->setParameter('breachId', $breachId)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result > 0;
    }
}
