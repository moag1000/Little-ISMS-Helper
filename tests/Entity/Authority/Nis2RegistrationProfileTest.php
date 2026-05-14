<?php

declare(strict_types=1);

namespace App\Tests\Entity\Authority;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Nis2RegistrationProfile entity accessors and helper methods.
 */
final class Nis2RegistrationProfileTest extends TestCase
{
    #[Test]
    public function defaultsAreCorrectOnCreation(): void
    {
        $profile = new Nis2RegistrationProfile();

        self::assertSame('', $profile->getOrganizationLegalName());
        self::assertSame('', $profile->getOrganizationLegalForm());
        self::assertSame([], $profile->getNaceCodes());
        self::assertSame(Nis2RegistrationProfile::CATEGORY_IMPORTANT, $profile->getNis2EntityCategory());
        self::assertSame(0, $profile->getAffectedHeadcount());
        self::assertNull($profile->getVatId());
        self::assertNull($profile->getLastReportedAt());
        self::assertNull($profile->getPortalConfirmationNumber());
        self::assertNull($profile->getId());
        self::assertInstanceOf(DateTimeImmutable::class, $profile->getCreatedAt());
        self::assertNull($profile->getUpdatedAt());
    }

    #[Test]
    public function nextDueAtIsSetToOneYearOnCreation(): void
    {
        $before = new DateTimeImmutable('+364 days');
        $profile = new Nis2RegistrationProfile();
        $after = new DateTimeImmutable('+366 days');

        self::assertGreaterThan($before, $profile->getNextDueAt());
        self::assertLessThan($after, $profile->getNextDueAt());
    }

    #[Test]
    public function isOverdueReturnsTrueWhenNextDueAtIsInPast(): void
    {
        $profile = new Nis2RegistrationProfile();
        $profile->setNextDueAt(new DateTimeImmutable('-1 day'));

        self::assertTrue($profile->isOverdue());
    }

    #[Test]
    public function isOverdueReturnsFalseWhenNextDueAtIsInFuture(): void
    {
        $profile = new Nis2RegistrationProfile();
        $profile->setNextDueAt(new DateTimeImmutable('+10 days'));

        self::assertFalse($profile->isOverdue());
    }

    #[Test]
    public function isDueSoonReturnsTrueWhenWithinThreshold(): void
    {
        $profile = new Nis2RegistrationProfile();
        $profile->setNextDueAt(new DateTimeImmutable('+15 days'));

        self::assertTrue($profile->isDueSoon(30));
    }

    #[Test]
    public function isDueSoonReturnsFalseWhenOverdue(): void
    {
        $profile = new Nis2RegistrationProfile();
        $profile->setNextDueAt(new DateTimeImmutable('-1 day'));

        self::assertFalse($profile->isDueSoon(30));
    }

    #[Test]
    public function isDueSoonReturnsFalseWhenFarInFuture(): void
    {
        $profile = new Nis2RegistrationProfile();
        $profile->setNextDueAt(new DateTimeImmutable('+90 days'));

        self::assertFalse($profile->isDueSoon(30));
    }

    #[Test]
    public function settersAndGettersWorkForAllFields(): void
    {
        $profile = new Nis2RegistrationProfile();
        $tenant = new Tenant();
        $user = new User();
        $now = new DateTimeImmutable();

        $profile->setTenant($tenant);
        $profile->setOrganizationLegalName('ACME GmbH');
        $profile->setOrganizationLegalForm('GmbH');
        $profile->setCommercialRegisterCity('Berlin');
        $profile->setCommercialRegisterNumber('HRB 99999');
        $profile->setVatId('DE123456789');
        $profile->setNaceCodes(['J62.01', 'J62.02']);
        $profile->setNis2Sector(Nis2RegistrationProfile::SECTOR_DIGITAL_INFRASTRUCTURE);
        $profile->setNis2EntityCategory(Nis2RegistrationProfile::CATEGORY_ESSENTIAL);
        $profile->setAffectedHeadcount(500);
        $profile->setAffectedAnnualTurnoverEur('12345678.00');
        $profile->setIctDependencyDescription('Kritische Abhängigkeit von Cloud-ERP.');
        $profile->setIncidentReportingContact($user);
        $profile->setSecurityResponsibleContact($user);
        $profile->setBackupSecurityContact($user);
        $profile->setLastReportedAt($now);
        $profile->setNextDueAt($now->modify('+1 year'));
        $profile->setPortalConfirmationNumber('BSI-2026-0001-TEST');

        self::assertSame($tenant, $profile->getTenant());
        self::assertSame('ACME GmbH', $profile->getOrganizationLegalName());
        self::assertSame('GmbH', $profile->getOrganizationLegalForm());
        self::assertSame('Berlin', $profile->getCommercialRegisterCity());
        self::assertSame('HRB 99999', $profile->getCommercialRegisterNumber());
        self::assertSame('DE123456789', $profile->getVatId());
        self::assertSame(['J62.01', 'J62.02'], $profile->getNaceCodes());
        self::assertSame(Nis2RegistrationProfile::SECTOR_DIGITAL_INFRASTRUCTURE, $profile->getNis2Sector());
        self::assertSame(Nis2RegistrationProfile::CATEGORY_ESSENTIAL, $profile->getNis2EntityCategory());
        self::assertSame(500, $profile->getAffectedHeadcount());
        self::assertSame('12345678.00', $profile->getAffectedAnnualTurnoverEur());
        self::assertSame('Kritische Abhängigkeit von Cloud-ERP.', $profile->getIctDependencyDescription());
        self::assertSame($user, $profile->getIncidentReportingContact());
        self::assertSame($user, $profile->getSecurityResponsibleContact());
        self::assertSame($user, $profile->getBackupSecurityContact());
        self::assertSame($now, $profile->getLastReportedAt());
        self::assertSame('BSI-2026-0001-TEST', $profile->getPortalConfirmationNumber());
    }

    #[Test]
    public function validCategoriesConstantContainsBothValues(): void
    {
        self::assertContains(Nis2RegistrationProfile::CATEGORY_ESSENTIAL, Nis2RegistrationProfile::VALID_CATEGORIES);
        self::assertContains(Nis2RegistrationProfile::CATEGORY_IMPORTANT, Nis2RegistrationProfile::VALID_CATEGORIES);
    }

    #[Test]
    public function validSectorsConstantIsNotEmpty(): void
    {
        self::assertNotEmpty(Nis2RegistrationProfile::VALID_SECTORS);
        self::assertContains(Nis2RegistrationProfile::SECTOR_ENERGY, Nis2RegistrationProfile::VALID_SECTORS);
        self::assertContains(Nis2RegistrationProfile::SECTOR_DIGITAL_INFRASTRUCTURE, Nis2RegistrationProfile::VALID_SECTORS);
    }
}
