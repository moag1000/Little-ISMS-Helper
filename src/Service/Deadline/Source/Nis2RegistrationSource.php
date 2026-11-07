<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** NIS-2-Registrierung: getNextDueAt() of Nis2RegistrationProfile. */
final class Nis2RegistrationSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Nis2RegistrationProfile::class;
    }

    public function slug(): string
    {
        return 'nis2_registration';
    }

    public function label(): string
    {
        return 'NIS-2-Registrierung';
    }

    public function requiredModule(): ?string
    {
        return 'nis2_dora';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Nis2RegistrationProfile);

        return $item->getNextDueAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Nis2RegistrationProfile);

        return 'NIS-2-Registrierung';
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Nis2RegistrationProfile);

        return false;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof Nis2RegistrationProfile);

        return Responsibility::holders($item->getSecurityResponsibleContact(), $item->getIncidentReportingContact())
            ->withDeputies($item->getBackupSecurityContact())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof Nis2RegistrationProfile);

        return new RecordLink('nis2_registration_index', []);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'nis2_registration_due', leadDays: 30, normReference: 'NIS-2 Art. 3 Abs. 4 / BSIG § 33');
    }
}
