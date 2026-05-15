<?php

declare(strict_types=1);

namespace App\Repository\Fte;

use App\Entity\Fte\FteCalibrationConstant;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FteCalibrationConstant>
 */
class FteCalibrationConstantRepository extends ServiceEntityRepository
{
    /**
     * System-level defaults when no tenant override is configured.
     * Mirrors the migration seed data.
     *
     * @var array<string, float>
     */
    private const array SYSTEM_DEFAULTS = [
        FteCalibrationConstant::OP_MANUAL_USER_PROVISIONING => 20.0,
        FteCalibrationConstant::OP_MANUAL_ASSET_CREATION => 3.0,
        FteCalibrationConstant::OP_MANUAL_RISK_CREATION => 5.0,
        FteCalibrationConstant::OP_MANUAL_CONTROL_MAPPING => 4.0,
        FteCalibrationConstant::OP_SINGLE_FRAMEWORK_EVIDENCE_MAINTENANCE => 8.0,
        FteCalibrationConstant::OP_MANUAL_BUSINESS_PROCESS_CREATION => 3.0,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FteCalibrationConstant::class);
    }

    /**
     * Return minutes-per-operation for the given tenant, falling back to
     * system-default row (tenant = null), then to the hard-coded constant above.
     */
    public function getMinutesFor(Tenant $tenant, string $operationType): float
    {
        // 1) Tenant-specific override
        $row = $this->findOneBy(['tenant' => $tenant, 'operationType' => $operationType]);
        if ($row !== null) {
            return $row->getMinutesPerOperation();
        }

        // 2) System default row in DB (tenant IS NULL)
        $systemRow = $this->createQueryBuilder('c')
            ->where('c.operationType = :op')
            ->andWhere('c.tenant IS NULL')
            ->setParameter('op', $operationType)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($systemRow instanceof FteCalibrationConstant) {
            return $systemRow->getMinutesPerOperation();
        }

        // 3) Hard-coded fallback
        return self::SYSTEM_DEFAULTS[$operationType] ?? 5.0;
    }

    /**
     * All tenant-specific constants for a given tenant (for calibration form).
     *
     * @return list<FteCalibrationConstant>
     */
    public function findForTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['operationType' => 'ASC']);
    }

    /**
     * Upsert: find or create a constant for tenant+operationType.
     */
    public function findOrCreate(Tenant $tenant, string $operationType): FteCalibrationConstant
    {
        $existing = $this->findOneBy(['tenant' => $tenant, 'operationType' => $operationType]);
        if ($existing !== null) {
            return $existing;
        }

        $constant = new FteCalibrationConstant();
        $constant->setTenant($tenant);
        $constant->setOperationType($operationType);
        $constant->setMinutesPerOperation(self::SYSTEM_DEFAULTS[$operationType] ?? 5.0);

        return $constant;
    }
}
