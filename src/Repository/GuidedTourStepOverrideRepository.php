<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuidedTourStepOverride;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuidedTourStepOverride>
 */
class GuidedTourStepOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuidedTourStepOverride::class);
    }

    /**
     * Gibt den effektiven Override zurück — Tenant-spezifisch bevorzugt,
     * Fallback auf globalen (tenant_id IS NULL) Override.
     */
    public function findEffective(
        ?Tenant $tenant,
        string $tourId,
        string $stepId,
        string $locale,
    ): ?GuidedTourStepOverride {
        if ($tenant !== null) {
            $tenantSpecific = $this->findOneBy([
                'tenant' => $tenant,
                'tourId' => $tourId,
                'stepId' => $stepId,
                'locale' => $locale,
            ]);
            if ($tenantSpecific instanceof GuidedTourStepOverride
                && !$tenantSpecific->isEmpty()
            ) {
                return $tenantSpecific;
            }
        }

        $global = $this->createQueryBuilder('o')
            ->where('o.tenant IS NULL')
            ->andWhere('o.tourId = :tour')
            ->andWhere('o.stepId = :step')
            ->andWhere('o.locale = :locale')
            ->setParameter('tour', $tourId)
            ->setParameter('step', $stepId)
            ->setParameter('locale', $locale)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return ($global instanceof GuidedTourStepOverride && !$global->isEmpty())
            ? $global
            : null;
    }

    /**
     * Alle Overrides für eine Tour eines Tenants (+ globale), indiziert
     * nach "stepId|locale" für schnellen Template-Zugriff.
     *
     * @return array<string, GuidedTourStepOverride>
     */
    public function indexForTourAndTenant(?Tenant $tenant, string $tourId): array
    {
        $qb = $this->createQueryBuilder('o')
            ->where('o.tourId = :tour')
            ->setParameter('tour', $tourId);

        if ($tenant !== null) {
            $qb->andWhere('(o.tenant = :tenant OR o.tenant IS NULL)')
                ->setParameter('tenant', $tenant);
        } else {
            $qb->andWhere('o.tenant IS NULL');
        }

        $out = [];
        /** @var GuidedTourStepOverride $row */
        foreach ($qb->getQuery()->getResult() as $row) {
            // Tenant-specific overrides (non-null tenant) overwrite global in the map —
            // orderBy ensures global-NULL comes first so tenant-specific wins.
            $key = $row->getStepId() . '|' . $row->getLocale();
            if (!isset($out[$key]) || $row->getTenant() !== null) {
                $out[$key] = $row;
            }
        }
        return $out;
    }
}
