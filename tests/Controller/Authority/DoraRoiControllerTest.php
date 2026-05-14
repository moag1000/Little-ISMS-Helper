<?php

declare(strict_types=1);

namespace App\Tests\Controller\Authority;

use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\Tenant;
use App\Repository\Authority\DoraRegisterOfInformationRepository;
use App\Service\Authority\DoraRoiXbrlExporter;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Smoke tests for DoraRoiController — verifies service wiring in DI container.
 *
 * Full HTTP stack tests require auth session + CSRF; these tests verify
 * the services are wired correctly without hitting the DB.
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraRoiControllerTest extends KernelTestCase
{
    #[Test]
    public function doraRoiXbrlExporterIsWiredInContainer(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertTrue(
            $container->has(DoraRoiXbrlExporter::class),
            'DoraRoiXbrlExporter must be registered as a service'
        );
    }

    #[Test]
    public function doraRegisterOfInformationRepositoryIsWiredInContainer(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertTrue(
            $container->has(DoraRegisterOfInformationRepository::class),
            'DoraRegisterOfInformationRepository must be registered as a service'
        );
    }

    #[Test]
    public function doraRecordIsSubmittedAfterMarkSubmitted(): void
    {
        // Entity-level smoke test: does not require DB connection
        $record = new DoraRegisterOfInformation();
        self::assertFalse($record->isSubmitted());

        $record->setSubmittedAt(new \DateTimeImmutable());
        self::assertTrue($record->isSubmitted());
    }

    #[Test]
    public function doraRecordScopesAreValid(): void
    {
        $record = new DoraRegisterOfInformation();
        $record->setReportingScope(DoraRegisterOfInformation::SCOPE_YEARLY_FULL);
        self::assertSame(DoraRegisterOfInformation::SCOPE_YEARLY_FULL, $record->getReportingScope());

        $record->setReportingScope(DoraRegisterOfInformation::SCOPE_SIGNIFICANT_CHANGES);
        self::assertSame(DoraRegisterOfInformation::SCOPE_SIGNIFICANT_CHANGES, $record->getReportingScope());
    }
}
