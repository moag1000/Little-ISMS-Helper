<?php

declare(strict_types=1);

namespace App\Tests\Controller\Authority;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use App\Service\Authority\Nis2BsiRegistrationService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Smoke tests for Nis2RegistrationController.
 *
 * Tests service wiring and basic business logic without full HTTP stack,
 * because the controller requires tenant context and module gating.
 */
#[AllowMockObjectsWithoutExpectations]
final class Nis2RegistrationControllerTest extends KernelTestCase
{
    #[Test]
    public function registrationServiceIsWiredInContainer(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertTrue(
            $container->has(Nis2BsiRegistrationService::class),
            'Nis2BsiRegistrationService must be registered as a service'
        );
    }

    #[Test]
    public function registrationProfileRepositoryIsWiredInContainer(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertTrue(
            $container->has(Nis2RegistrationProfileRepository::class),
            'Nis2RegistrationProfileRepository must be registered as a service'
        );
    }

    #[Test]
    public function serviceValidateReturnsErrorsForEmptyProfile(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var Nis2BsiRegistrationService $service */
        $service = $container->get(Nis2BsiRegistrationService::class);
        $profile = new Nis2RegistrationProfile();

        $errors = $service->validate($profile);

        self::assertNotEmpty($errors, 'validate() must return errors for an empty profile');
        self::assertArrayHasKey('organizationLegalName', $errors);
    }

    #[Test]
    public function serviceExportToJsonProducesValidJson(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var Nis2BsiRegistrationService $service */
        $service = $container->get(Nis2BsiRegistrationService::class);

        $user = new User();
        $user->setEmail('ciso@example.com');

        $profile = new Nis2RegistrationProfile();
        $profile->setOrganizationLegalName('Test Org');
        $profile->setOrganizationLegalForm('AG');
        $profile->setCommercialRegisterCity('Hamburg');
        $profile->setCommercialRegisterNumber('HRB 11111');
        $profile->setNaceCodes(['K64.19']);
        $profile->setNis2Sector(Nis2RegistrationProfile::SECTOR_BANKING);
        $profile->setNis2EntityCategory(Nis2RegistrationProfile::CATEGORY_IMPORTANT);
        $profile->setAffectedHeadcount(120);
        $profile->setIctDependencyDescription('Core banking system dependency.');
        $profile->setIncidentReportingContact($user);
        $profile->setSecurityResponsibleContact($user);

        $json = $service->exportToJson($profile);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('1.0', $decoded['schemaVersion']);
        self::assertSame('Test Org', $decoded['organization']['legalName']);
    }
}
