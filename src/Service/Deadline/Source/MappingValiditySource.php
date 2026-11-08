<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\ComplianceMapping;
use App\Entity\Tenant;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Mapping gültig bis: getValidUntil() of a mapping the organisation owns
 * (one side is a requirement it uploaded). Global catalogue mappings belong
 * to nobody's inbox; they are maintained with the catalogue.
 */
final class MappingValiditySource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return ComplianceMapping::class;
    }

    public function slug(): string
    {
        return 'mapping_validity';
    }

    public function label(): string
    {
        return 'Mapping läuft ab';
    }

    public function requiredModule(): string
    {
        return 'compliance';
    }

    public function findOpen(Tenant $tenant): iterable
    {
        return $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(ComplianceMapping::class, 'm')
            ->leftJoin('m.sourceRequirement', 's')
            ->leftJoin('m.targetRequirement', 't')
            ->where('m.validUntil IS NOT NULL')
            ->andWhere('s.uploadTenant = :tenant OR t.uploadTenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ComplianceMapping);

        return $item->getValidUntil();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ComplianceMapping);

        return sprintf(
            '%s → %s',
            (string) $item->getSourceRequirement()?->getRequirementId(),
            (string) $item->getTargetRequirement()?->getRequirementId(),
        );
    }

    public function isCompleted(object $item): bool
    {
        return false;
    }

    public function responsibleOf(object $item): Responsibility
    {
        return Responsibility::roles('ROLE_COMPLIANCE_MANAGER', 'ROLE_ISB');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ComplianceMapping);

        return new RecordLink('app_compliance_mapping_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'mappings_expiring', leadDays: 30, normReference: 'ISO 27001 6.1.3 (Anforderungsabgleich)');
    }
}
