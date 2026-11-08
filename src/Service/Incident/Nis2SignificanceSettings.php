<?php

declare(strict_types=1);

namespace App\Service\Incident;

use App\Entity\Tenant;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/** Saves the organisation's {@see Nis2SignificanceCriteria} with an audit entry. */
final readonly class Nis2SignificanceSettings
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
    ) {
    }

    public function update(Tenant $tenant, int $financialLossEur, int $affectedPersons): void
    {
        $old = self::values($tenant);
        Nis2SignificanceCriteria::set($tenant, $financialLossEur, $affectedPersons);
        $this->entityManager->flush();
        $this->auditLogger->logUpdate('Tenant', $tenant->getId(), $old, self::values($tenant), 'NIS-2 significance thresholds updated');
    }

    /** @return array{financial_loss_eur: int, affected_persons: int} */
    public static function values(?Tenant $tenant): array
    {
        return [
            'financial_loss_eur' => Nis2SignificanceCriteria::financialLossEur($tenant),
            'affected_persons' => Nis2SignificanceCriteria::affectedPersons($tenant),
        ];
    }
}
