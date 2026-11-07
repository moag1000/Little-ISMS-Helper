<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\InterestedParty;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Kommunikation interessierte Partei: getNextCommunication() of InterestedParty. */
final class InterestedPartyCommunicationSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return InterestedParty::class;
    }

    public function slug(): string
    {
        return 'interested_party_communication';
    }

    public function label(): string
    {
        return 'Kommunikation interessierte Partei';
    }

    public function requiredModule(): ?string
    {
        return 'interested_parties';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof InterestedParty);

        return $item->getNextCommunication();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof InterestedParty);

        return (string) ($item->getName() ?? 'Interested party #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof InterestedParty);

        return in_array($item->getStatus(), ['draft', 'archived'], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof InterestedParty);

        return Responsibility::holders($item->getContactPersonUser(), $item->getContactPersonRef())
            ->withDeputies(...$item->getContactDeputyPersons())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof InterestedParty);

        return new RecordLink('app_interested_party_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'interested_party_communication_due', leadDays: 14, normReference: 'ISO 27001 4.2 / 7.4');
    }
}
