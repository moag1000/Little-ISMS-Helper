<?php

namespace App\Repository;

use DateTime;
use App\Entity\PhysicalAccessLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PhysicalAccessLog>
 */
class PhysicalAccessLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhysicalAccessLog::class);
    }

    /**
     * Find unauthorized access attempts
     */
    public function findUnauthorizedAccess(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->andWhere('p.authorized = :authorized')
            ->setParameter('authorized', false)
            ->orderBy('p.accessTime', 'DESC');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Find denied access attempts
     */
    public function findDeniedAccess(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->andWhere('p.accessType = :type')
            ->setParameter('type', 'denied')
            ->orderBy('p.accessTime', 'DESC');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Find forced entry incidents
     */
    public function findForcedEntry(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->andWhere('p.accessType = :type')
            ->setParameter('type', 'forced_entry')
            ->orderBy('p.accessTime', 'DESC');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Find after-hours access
     */
    public function findAfterHoursAccess(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->andWhere('p.afterHours = :afterHours')
            ->setParameter('afterHours', true)
            ->orderBy('p.accessTime', 'DESC');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Find access logs with alerts
     */
    public function findWithAlerts(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->andWhere('p.alertLevel IS NOT NULL')
            ->orderBy('p.accessTime', 'DESC');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Find access by person
     */
    public function findByPerson(string $personName, ?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->andWhere('p.personName LIKE :personName')
            ->setParameter('personName', '%' . $personName . '%')
            ->orderBy('p.accessTime', 'DESC');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Find access by location
     */
    public function findByLocation(string $location, ?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->andWhere('p.location = :location')
            ->setParameter('location', $location)
            ->orderBy('p.accessTime', 'DESC');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get access statistics
     */
    public function getStatistics(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        $allLogs = $queryBuilder->getQuery()->getResult();

        return [
            'total' => count($allLogs),
            'unauthorized' => count($this->findUnauthorizedAccess($startDate, $endDate)),
            'denied' => count($this->findDeniedAccess($startDate, $endDate)),
            'forced_entry' => count($this->findForcedEntry($startDate, $endDate)),
            'after_hours' => count($this->findAfterHoursAccess($startDate, $endDate)),
            'with_alerts' => count($this->findWithAlerts($startDate, $endDate)),
            'by_type' => $this->getCountByType($startDate, $endDate),
            'by_location' => $this->getCountByLocation($startDate, $endDate),
            'by_auth_method' => $this->getCountByAuthMethod($startDate, $endDate),
        ];
    }

    /**
     * Get count by access type
     */
    private function getCountByType(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->select('p.accessType as type, COUNT(p.id) as count')
            ->groupBy('p.accessType');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        $results = $queryBuilder->getQuery()->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['type']] = (int) $result['count'];
        }

        return $counts;
    }

    /**
     * Get count by location
     */
    private function getCountByLocation(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->select('p.location, COUNT(p.id) as count')
            ->groupBy('p.location');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        $results = $queryBuilder->getQuery()->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['location']] = (int) $result['count'];
        }

        return $counts;
    }

    /**
     * Get count by authentication method
     */
    private function getCountByAuthMethod(?DateTime $startDate = null, ?DateTime $endDate = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->select('p.authenticationMethod as method, COUNT(p.id) as count')
            ->groupBy('p.authenticationMethod');

        if ($startDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime >= :startDate')
               ->setParameter('startDate', $startDate);
        }

        if ($endDate instanceof DateTime) {
            $queryBuilder->andWhere('p.accessTime <= :endDate')
               ->setParameter('endDate', $endDate);
        }

        $results = $queryBuilder->getQuery()->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['method']] = (int) $result['count'];
        }

        return $counts;
    }

    /**
     * Find recent security incidents (unauthorized, denied, forced_entry, alerts)
     */
    public function findRecentSecurityIncidents(int $days = 7): array
    {
        $startDate = new DateTime()->modify("-$days days");

        $queryBuilder = $this->createQueryBuilder('p')
            ->andWhere('p.accessTime >= :startDate')
            ->setParameter('startDate', $startDate)
            ->andWhere(
                'p.authorized = :unauthorized OR ' .
                'p.accessType IN (:incidentTypes) OR ' .
                'p.alertLevel IS NOT NULL'
            )
            ->setParameter('unauthorized', false)
            ->setParameter('incidentTypes', ['denied', 'forced_entry'])
            ->orderBy('p.accessTime', 'DESC');

        return $queryBuilder->getQuery()->getResult();
    }
}
