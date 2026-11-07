<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Supplier;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Lieferantenbewertung: getNextAssessmentDate() of Supplier. */
final class SupplierAssessmentSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Supplier::class;
    }

    public function slug(): string
    {
        return 'supplier_assessment';
    }

    public function label(): string
    {
        return 'Lieferantenbewertung';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Supplier);

        return $item->getNextAssessmentDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Supplier);

        return (string) ($item->getName() ?? 'Supplier #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Supplier);

        return in_array($item->getStatus(), ['inactive', 'terminated'], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof Supplier);

        return Responsibility::holders($item->getContactPersonUser(), $item->getContactPersonRef())
            ->withDeputies(...$item->getContactDeputyPersons())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): ?RecordLink
    {
        assert($item instanceof Supplier);

        return new RecordLink('app_supplier_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'supplier_reassessment_due', leadDays: 30, normReference: 'ISO 27001 A.5.22 / NIS-2 Art. 21 (2) d');
    }
}
