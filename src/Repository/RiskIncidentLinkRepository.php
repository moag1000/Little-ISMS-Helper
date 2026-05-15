<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\RiskIncidentLink;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RiskIncidentLink>
 */
class RiskIncidentLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskIncidentLink::class);
    }

    /**
     * All links for a given risk (for risk-show view).
     *
     * @return RiskIncidentLink[]
     */
    public function findByRisk(Risk $risk): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.risk = :risk')
            ->setParameter('risk', $risk)
            ->orderBy('l.linkedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All links for a given incident (for incident-show view).
     *
     * @return RiskIncidentLink[]
     */
    public function findByIncident(Incident $incident): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.incident = :incident')
            ->setParameter('incident', $incident)
            ->orderBy('l.linkedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find an existing link between a specific risk and incident pair.
     */
    public function findOneByRiskAndIncident(Risk $risk, Incident $incident): ?RiskIncidentLink
    {
        return $this->createQueryBuilder('l')
            ->where('l.risk = :risk')
            ->andWhere('l.incident = :incident')
            ->setParameter('risk', $risk)
            ->setParameter('incident', $incident)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Aggregate count of links per link type for a tenant (analytics).
     *
     * @return array<string, int>  e.g. ['materialized' => 3, 'related' => 12]
     */
    public function countByLinkType(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.linkType AS linkType, COUNT(l.id) AS cnt')
            ->where('l.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('l.linkType')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['linkType']] = (int) $row['cnt'];
        }
        return $result;
    }

    /**
     * All links to incidents that are still OPEN (not closed) for a tenant.
     * Used by the Alva-Hint rule.
     *
     * @return RiskIncidentLink[]
     */
    public function findLinksToOpenIncidents(Tenant $tenant): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.incident', 'i')
            ->where('l.tenant = :tenant')
            ->andWhere('i.status != :closed')
            ->setParameter('tenant', $tenant)
            ->setParameter('closed', 'closed')
            ->getQuery()
            ->getResult();
    }
}
