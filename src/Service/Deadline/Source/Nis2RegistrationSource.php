<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Tenant;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * NIS-2-Registrierung (BSIG § 33): getNextDueAt() of the organisation's
 * registration profile. An organisation with the NIS-2 module and no profile
 * yet is due at once — the profile is built on the spot (not stored) from
 * the organisation's own registration date, if it has one. Done for good when
 * the organisation classifies itself as not regulated.
 */
final class Nis2RegistrationSource extends EntityDeadlineSource
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly Nis2RegistrationProfileRepository $profiles,
    ) {
        parent::__construct($entityManager);
    }

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

    public function requiredModule(): string
    {
        return 'nis2_dora';
    }

    /** @return list<Nis2RegistrationProfile> */
    public function findOpen(Tenant $tenant): iterable
    {
        $profile = $this->profiles->findForTenant($tenant) ?? Nis2RegistrationProfile::forTenant($tenant);

        return $this->isCompleted($profile) ? [] : [$profile];
    }

    public function dueDateOf(object $item): DateTimeInterface
    {
        assert($item instanceof Nis2RegistrationProfile);

        return $item->getNextDueAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Nis2RegistrationProfile);
        $tenant = $item->getTenant();

        return (string) ($tenant->getLegalName() ?: $tenant->getName());
    }

    /**
     * Not regulated → nothing to register. Otherwise the duty comes back every
     * year; between two confirmations the lead time keeps it out of the inbox.
     */
    public function isCompleted(object $item): bool
    {
        assert($item instanceof Nis2RegistrationProfile);

        return $item->getTenant()->getNis2Classification() === Tenant::NIS2_NOT_REGULATED;
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
