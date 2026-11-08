<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Supplier;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Vertragsende: getContractEndDate() of a supplier still in business. Renew,
 * exit or replace before the date (ISO 27001 A.5.20, DORA Art. 28 (7)).
 */
final class SupplierContractEndSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Supplier::class;
    }

    public function slug(): string
    {
        return 'supplier_contract_end';
    }

    public function label(): string
    {
        return 'Lieferantenvertrag endet';
    }

    public function requiredModule(): string
    {
        return 'suppliers';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Supplier);

        return $item->getContractEndDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Supplier);

        return (string) $item->getName();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Supplier);

        return in_array($item->getStatus(), ['inactive', 'terminated'], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        return Responsibility::roles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof Supplier);

        return new RecordLink('app_supplier_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'supplier_contracts_ending', leadDays: 90, normReference: 'ISO 27001 A.5.20 / DORA Art. 28 (7)');
    }
}
