<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Person;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Zugang endet: getAccessValidUntil() of an active Person. Access rights end
 * on that day and have to be withdrawn (ISO 27001 A.5.18, A.6.5).
 */
final class PersonAccessExpirySource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Person::class;
    }

    public function slug(): string
    {
        return 'person_access_expiry';
    }

    public function label(): string
    {
        return 'Zugang endet';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Person);

        return $item->getAccessValidUntil();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Person);

        return (string) $item->getFullName();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Person);

        return $item->isActive() === false;
    }

    /** Access is withdrawn by whoever administers accounts; the person is informed. */
    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof Person);

        return Responsibility::roles('ROLE_ADMIN')->alsoInform($item);
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof Person);

        return new RecordLink('app_person_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'access_expiring', leadDays: 14, normReference: 'ISO 27001 A.5.18, A.6.5');
    }
}
