<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\WizardManualConfirmation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WizardManualConfirmation>
 */
class WizardManualConfirmationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WizardManualConfirmation::class);
    }

    public function findOneForCheck(Tenant $tenant, string $wizardKey, string $checkKey): ?WizardManualConfirmation
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'wizardKey' => $wizardKey,
            'checkKey' => $checkKey,
        ]);
    }

    /**
     * Map of confirmed check_key => true for a wizard, for one tenant.
     * Used to score all manual checks of a wizard in a single query.
     *
     * @return array<string, bool>
     */
    public function confirmedKeysFor(Tenant $tenant, string $wizardKey): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.checkKey')
            ->where('c.tenant = :tenant')
            ->andWhere('c.wizardKey = :wizard')
            ->andWhere('c.confirmed = true')
            ->setParameter('tenant', $tenant)
            ->setParameter('wizard', $wizardKey)
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['checkKey']] = true;
        }

        return $map;
    }
}
