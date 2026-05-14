<?php

declare(strict_types=1);

namespace App\Tests\Service\Authority;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use App\Service\Authority\Nis2BsiRegistrationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Nis2BsiRegistrationService.
 *
 * Covers: validate() returns errors for missing fields,
 * validate() passes for complete profile, exportToJson() produces
 * correct BSI-spec schema, markReported() advances nextDueAt by 1 year.
 */
#[AllowMockObjectsWithoutExpectations]
final class Nis2BsiRegistrationServiceTest extends TestCase
{
    private Nis2RegistrationProfileRepository $profileRepo;
    private EntityManagerInterface $em;
    private Nis2BsiRegistrationService $service;

    protected function setUp(): void
    {
        $this->profileRepo = $this->createMock(Nis2RegistrationProfileRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->service = new Nis2BsiRegistrationService($this->profileRepo, $this->em);
    }

    #[Test]
    public function validateReturnsErrorsForEmptyProfile(): void
    {
        $profile = new Nis2RegistrationProfile();
        $profile->setNis2EntityCategory('invalid_category');

        $errors = $this->service->validate($profile);

        self::assertArrayHasKey('organizationLegalName', $errors);
        self::assertArrayHasKey('organizationLegalForm', $errors);
        self::assertArrayHasKey('commercialRegisterCity', $errors);
        self::assertArrayHasKey('commercialRegisterNumber', $errors);
        self::assertArrayHasKey('naceCodes', $errors);
        self::assertArrayHasKey('nis2Sector', $errors);
        self::assertArrayHasKey('nis2EntityCategory', $errors);
        self::assertArrayHasKey('affectedHeadcount', $errors);
        self::assertArrayHasKey('ictDependencyDescription', $errors);
    }

    #[Test]
    public function validatePassesForCompleteProfile(): void
    {
        $profile = $this->buildCompleteProfile();

        $errors = $this->service->validate($profile);

        self::assertSame([], $errors, 'Expected no validation errors for a complete profile');
    }

    #[Test]
    public function exportToJsonProducesValidBsiSpecSchema(): void
    {
        $profile = $this->buildCompleteProfile();

        $json = $this->service->exportToJson($profile);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('1.0', $data['schemaVersion']);
        self::assertSame('nis2_yearly_reregistration', $data['registrationType']);
        self::assertSame('ACME GmbH', $data['organization']['legalName']);
        self::assertSame('GmbH', $data['organization']['legalForm']);
        self::assertSame('Berlin', $data['organization']['commercialRegister']['city']);
        self::assertSame('HRB 99999', $data['organization']['commercialRegister']['number']);
        self::assertSame(['J62.01'], $data['organization']['naceCodes']);
        self::assertSame(250, $data['organization']['affectedHeadcount']);
        self::assertSame(Nis2RegistrationProfile::SECTOR_DIGITAL_INFRASTRUCTURE, $data['nis2Classification']['sector']);
        self::assertSame(Nis2RegistrationProfile::CATEGORY_ESSENTIAL, $data['nis2Classification']['entityCategory']);
        self::assertSame('test@example.com', $data['contacts']['incidentReporting']['email']);
        self::assertNull($data['contacts']['backupSecurity']);
    }

    #[Test]
    public function exportToJsonIsValidJson(): void
    {
        $profile = $this->buildCompleteProfile();
        $json = $this->service->exportToJson($profile);

        self::assertJson($json);
    }

    #[Test]
    public function markReportedSetsLastReportedAtAndAdvancesNextDueAtByOneYear(): void
    {
        $profile = $this->buildCompleteProfile();
        $originalNextDueAt = $profile->getNextDueAt();

        $this->em->expects(self::once())->method('flush');

        $this->service->markReported($profile, 'BSI-2026-TEST-9999');

        self::assertNotNull($profile->getLastReportedAt());
        self::assertSame('BSI-2026-TEST-9999', $profile->getPortalConfirmationNumber());

        // nextDueAt should be ~1 year after the new lastReportedAt
        $expectedNextDue = $profile->getLastReportedAt()->modify('+1 year');
        $diff = abs($profile->getNextDueAt()->getTimestamp() - $expectedNextDue->getTimestamp());
        self::assertLessThan(5, $diff, 'nextDueAt should be within 5 seconds of +1 year from now');
    }

    #[Test]
    public function markReportedStoresConfirmationNumber(): void
    {
        $profile = $this->buildCompleteProfile();
        $this->em->method('flush');

        $this->service->markReported($profile, 'CONFIRM-XYZ');

        self::assertSame('CONFIRM-XYZ', $profile->getPortalConfirmationNumber());
    }

    #[Test]
    public function getOrCreateProfileReturnsExistingProfile(): void
    {
        $tenant = new Tenant();
        $existing = $this->buildCompleteProfile();

        $this->profileRepo->method('findForTenant')->willReturn($existing);

        $result = $this->service->getOrCreateProfile($tenant);

        self::assertSame($existing, $result);
    }

    #[Test]
    public function getOrCreateProfileCreatesNewProfileWithTenantDefaults(): void
    {
        $tenant = new Tenant();
        $tenant->setLegalName('Test Corp GmbH');
        $tenant->setLegalForm('GmbH');
        $tenant->setNis2Sector(Nis2RegistrationProfile::SECTOR_HEALTH);
        $tenant->setNaceCode('Q86.10');

        $this->profileRepo->method('findForTenant')->willReturn(null);
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $profile = $this->service->getOrCreateProfile($tenant);

        self::assertSame('Test Corp GmbH', $profile->getOrganizationLegalName());
        self::assertSame('GmbH', $profile->getOrganizationLegalForm());
        self::assertSame(Nis2RegistrationProfile::SECTOR_HEALTH, $profile->getNis2Sector());
        self::assertContains('Q86.10', $profile->getNaceCodes());
        self::assertSame($tenant, $profile->getTenant());
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function buildCompleteProfile(): Nis2RegistrationProfile
    {
        $user = new User();
        $user->setEmail('test@example.com');

        $profile = new Nis2RegistrationProfile();
        $profile->setOrganizationLegalName('ACME GmbH');
        $profile->setOrganizationLegalForm('GmbH');
        $profile->setCommercialRegisterCity('Berlin');
        $profile->setCommercialRegisterNumber('HRB 99999');
        $profile->setNaceCodes(['J62.01']);
        $profile->setNis2Sector(Nis2RegistrationProfile::SECTOR_DIGITAL_INFRASTRUCTURE);
        $profile->setNis2EntityCategory(Nis2RegistrationProfile::CATEGORY_ESSENTIAL);
        $profile->setAffectedHeadcount(250);
        $profile->setIctDependencyDescription('Kritische Cloud-ERP-Abhängigkeit.');
        $profile->setIncidentReportingContact($user);
        $profile->setSecurityResponsibleContact($user);

        return $profile;
    }
}
