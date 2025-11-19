<?php

namespace App\Tests\Service;

use App\Command\LoadTisaxRequirementsCommand;
use App\Command\LoadDoraRequirementsCommand;
use App\Command\LoadNis2RequirementsCommand;
use App\Command\LoadBsiItGrundschutzRequirementsCommand;
use App\Command\LoadGdprRequirementsCommand;
use App\Command\LoadIso27001RequirementsCommand;
use App\Command\LoadIso27701RequirementsCommand;
use App\Command\LoadIso27701v2025RequirementsCommand;
use App\Command\LoadC5RequirementsCommand;
use App\Command\LoadC52025RequirementsCommand;
use App\Command\LoadKritisRequirementsCommand;
use App\Command\LoadKritisHealthRequirementsCommand;
use App\Command\LoadDigavRequirementsCommand;
use App\Command\LoadTkgRequirementsCommand;
use App\Command\LoadGxpRequirementsCommand;
use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceFrameworkLoaderService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ComplianceFrameworkLoaderServiceTest extends TestCase
{
    private MockObject $frameworkRepository;
    private MockObject $tisaxCommand;
    private MockObject $doraCommand;
    private MockObject $nis2Command;
    private MockObject $bsiCommand;
    private MockObject $gdprCommand;
    private MockObject $iso27001Command;
    private MockObject $iso27701Command;
    private MockObject $iso27701v2025Command;
    private MockObject $c5Command;
    private MockObject $c52025Command;
    private MockObject $kritisCommand;
    private MockObject $kritisHealthCommand;
    private MockObject $digavCommand;
    private MockObject $tkgCommand;
    private MockObject $gxpCommand;
    private ComplianceFrameworkLoaderService $service;

    protected function setUp(): void
    {
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->tisaxCommand = $this->createMock(LoadTisaxRequirementsCommand::class);
        $this->doraCommand = $this->createMock(LoadDoraRequirementsCommand::class);
        $this->nis2Command = $this->createMock(LoadNis2RequirementsCommand::class);
        $this->bsiCommand = $this->createMock(LoadBsiItGrundschutzRequirementsCommand::class);
        $this->gdprCommand = $this->createMock(LoadGdprRequirementsCommand::class);
        $this->iso27001Command = $this->createMock(LoadIso27001RequirementsCommand::class);
        $this->iso27701Command = $this->createMock(LoadIso27701RequirementsCommand::class);
        $this->iso27701v2025Command = $this->createMock(LoadIso27701v2025RequirementsCommand::class);
        $this->c5Command = $this->createMock(LoadC5RequirementsCommand::class);
        $this->c52025Command = $this->createMock(LoadC52025RequirementsCommand::class);
        $this->kritisCommand = $this->createMock(LoadKritisRequirementsCommand::class);
        $this->kritisHealthCommand = $this->createMock(LoadKritisHealthRequirementsCommand::class);
        $this->digavCommand = $this->createMock(LoadDigavRequirementsCommand::class);
        $this->tkgCommand = $this->createMock(LoadTkgRequirementsCommand::class);
        $this->gxpCommand = $this->createMock(LoadGxpRequirementsCommand::class);

        $this->service = new ComplianceFrameworkLoaderService(
            $this->frameworkRepository,
            $this->tisaxCommand,
            $this->doraCommand,
            $this->nis2Command,
            $this->bsiCommand,
            $this->gdprCommand,
            $this->iso27001Command,
            $this->iso27701Command,
            $this->iso27701v2025Command,
            $this->c5Command,
            $this->c52025Command,
            $this->kritisCommand,
            $this->kritisHealthCommand,
            $this->digavCommand,
            $this->tkgCommand,
            $this->gxpCommand
        );
    }

    public function testGetAvailableFrameworksReturnsAllFrameworks(): void
    {
        $this->frameworkRepository->method('findAll')->willReturn([]);

        $frameworks = $this->service->getAvailableFrameworks();

        $this->assertIsArray($frameworks);
        $this->assertCount(15, $frameworks);

        // Verify TISAX framework structure
        $this->assertEquals('TISAX', $frameworks[0]['code']);
        $this->assertEquals('TISAX (Trusted Information Security Assessment Exchange)', $frameworks[0]['name']);
        $this->assertFalse($frameworks[0]['loaded']);
        $this->assertFalse($frameworks[0]['mandatory']);
        $this->assertEquals('automotive', $frameworks[0]['industry']);
    }

    public function testGetAvailableFrameworksMarksLoadedFrameworks(): void
    {
        $doraFramework = $this->createMock(ComplianceFramework::class);
        $doraFramework->method('getCode')->willReturn('DORA');

        $gdprFramework = $this->createMock(ComplianceFramework::class);
        $gdprFramework->method('getCode')->willReturn('GDPR');

        $this->frameworkRepository->method('findAll')->willReturn([
            $doraFramework,
            $gdprFramework
        ]);

        $frameworks = $this->service->getAvailableFrameworks();

        // Find DORA and GDPR in results
        $doraResult = array_values(array_filter($frameworks, fn($f) => $f['code'] === 'DORA'))[0];
        $gdprResult = array_values(array_filter($frameworks, fn($f) => $f['code'] === 'GDPR'))[0];
        $tisaxResult = array_values(array_filter($frameworks, fn($f) => $f['code'] === 'TISAX'))[0];

        $this->assertTrue($doraResult['loaded']);
        $this->assertTrue($gdprResult['loaded']);
        $this->assertFalse($tisaxResult['loaded']);
    }

    public function testGetAvailableFrameworksIncludesAllRequiredFields(): void
    {
        $this->frameworkRepository->method('findAll')->willReturn([]);

        $frameworks = $this->service->getAvailableFrameworks();

        foreach ($frameworks as $framework) {
            $this->assertArrayHasKey('code', $framework);
            $this->assertArrayHasKey('name', $framework);
            $this->assertArrayHasKey('description', $framework);
            $this->assertArrayHasKey('industry', $framework);
            $this->assertArrayHasKey('regulatory_body', $framework);
            $this->assertArrayHasKey('mandatory', $framework);
            $this->assertArrayHasKey('version', $framework);
            $this->assertArrayHasKey('loaded', $framework);
            $this->assertArrayHasKey('icon', $framework);
            $this->assertArrayHasKey('required_modules', $framework);
        }
    }

    public function testLoadFrameworkSuccessfully(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('getId')->willReturn(1);
        $framework->method('getRequirements')->willReturn(new ArrayCollection());

        $this->frameworkRepository->method('findOneBy')
            ->with(['code' => 'TISAX'])
            ->willReturn($framework);

        $this->tisaxCommand->expects($this->once())
            ->method('run')
            ->willReturn(0);

        $this->frameworkRepository->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturnOnConsecutiveCalls($framework, $framework);

        $result = $this->service->loadFramework('TISAX');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Successfully loaded TISAX framework', $result['message']);
        $this->assertEquals(1, $result['framework_id']);
    }

    public function testLoadFrameworkWithInvalidCode(): void
    {
        $result = $this->service->loadFramework('INVALID_CODE');

        $this->assertFalse($result['success']);
        $this->assertEquals('Framework not found', $result['message']);
    }

    public function testLoadFrameworkWhenAlreadyLoadedWithRequirements(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $requirements = new ArrayCollection([
            $this->createMock(\App\Entity\ComplianceRequirement::class),
            $this->createMock(\App\Entity\ComplianceRequirement::class),
        ]);
        $framework->method('getRequirements')->willReturn($requirements);

        $this->frameworkRepository->method('findOneBy')
            ->with(['code' => 'DORA'])
            ->willReturn($framework);

        $result = $this->service->loadFramework('DORA');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already loaded with 2 requirements', $result['message']);
    }

    public function testLoadFrameworkCommandExecutionFailure(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('getRequirements')->willReturn(new ArrayCollection());

        $this->frameworkRepository->method('findOneBy')
            ->with(['code' => 'NIS2'])
            ->willReturn($framework);

        $this->nis2Command->expects($this->once())
            ->method('run')
            ->willReturn(1); // Non-zero exit code

        $result = $this->service->loadFramework('NIS2');

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to load framework', $result['message']);
    }

    public function testLoadFrameworkHandlesUniqueConstraintViolation(): void
    {
        $this->frameworkRepository->method('findOneBy')
            ->with(['code' => 'GDPR'])
            ->willReturn(null);

        // Create a mock DriverException
        $driverException = $this->createMock(\Doctrine\DBAL\Driver\Exception::class);
        $driverException->method('getSQLState')->willReturn('23000');

        $this->gdprCommand->expects($this->once())
            ->method('run')
            ->willThrowException(new \Doctrine\DBAL\Exception\UniqueConstraintViolationException(
                $driverException,
                null
            ));

        $result = $this->service->loadFramework('GDPR');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already exist', $result['message']);
    }

    public function testLoadFrameworkHandlesOrmException(): void
    {
        $this->frameworkRepository->method('findOneBy')
            ->with(['code' => 'ISO27001'])
            ->willReturn(null);

        // Use a concrete ORM exception
        $exception = new \Doctrine\ORM\Exception\EntityManagerClosed();

        $this->iso27001Command->expects($this->once())
            ->method('run')
            ->willThrowException($exception);

        $result = $this->service->loadFramework('ISO27001');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Error loading framework', $result['message']);
    }

    public function testLoadFrameworkHandlesGeneralException(): void
    {
        $this->frameworkRepository->method('findOneBy')
            ->with(['code' => 'BSI_GRUNDSCHUTZ'])
            ->willReturn(null);

        $this->bsiCommand->expects($this->once())
            ->method('run')
            ->willThrowException(new \Exception('Unexpected error'));

        $result = $this->service->loadFramework('BSI_GRUNDSCHUTZ');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unexpected error', $result['message']);
    }

    public function testLoadAllFrameworkTypes(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('getId')->willReturn(1);
        $framework->method('getRequirements')->willReturn(new ArrayCollection());

        $this->frameworkRepository->method('findOneBy')->willReturn(null, $framework);
        $this->tisaxCommand->method('run')->willReturn(0);

        $result = $this->service->loadFramework('TISAX');

        $this->assertTrue($result['success']);
    }

    public function testGetFrameworkStatisticsWithNoFrameworks(): void
    {
        $this->frameworkRepository->method('findAll')->willReturn([]);

        $stats = $this->service->getFrameworkStatistics();

        $this->assertEquals(15, $stats['total_available']);
        $this->assertEquals(0, $stats['total_loaded']);
        $this->assertEquals(15, $stats['total_not_loaded']);
        $this->assertEquals(0.0, $stats['compliance_percentage']);
    }

    public function testGetFrameworkStatisticsWithSomeFrameworksLoaded(): void
    {
        $doraFramework = $this->createMock(ComplianceFramework::class);
        $doraFramework->method('getCode')->willReturn('DORA');

        $gdprFramework = $this->createMock(ComplianceFramework::class);
        $gdprFramework->method('getCode')->willReturn('GDPR');

        $nis2Framework = $this->createMock(ComplianceFramework::class);
        $nis2Framework->method('getCode')->willReturn('NIS2');

        $this->frameworkRepository->method('findAll')->willReturn([
            $doraFramework,
            $gdprFramework,
            $nis2Framework
        ]);

        $stats = $this->service->getFrameworkStatistics();

        $this->assertEquals(15, $stats['total_available']);
        $this->assertEquals(3, $stats['total_loaded']);
        $this->assertEquals(12, $stats['total_not_loaded']);
        $this->assertEquals(20.0, $stats['compliance_percentage']);
    }

    public function testGetFrameworkStatisticsCountsMandatoryFrameworks(): void
    {
        // DORA, NIS2, GDPR are mandatory frameworks
        $doraFramework = $this->createMock(ComplianceFramework::class);
        $doraFramework->method('getCode')->willReturn('DORA');

        $this->frameworkRepository->method('findAll')->willReturn([
            $doraFramework
        ]);

        $stats = $this->service->getFrameworkStatistics();

        // Count mandatory frameworks: DORA, NIS2, GDPR, KRITIS, KRITIS-HEALTH, DIGAV, TKG-2024, GXP = 8
        $this->assertEquals(8, $stats['mandatory_frameworks']);
        $this->assertEquals(1, $stats['mandatory_loaded']);
        $this->assertEquals(7, $stats['mandatory_not_loaded']);
    }

    public function testGetFrameworkStatisticsWithAllFrameworksLoaded(): void
    {
        $frameworks = [];
        $codes = [
            'TISAX', 'DORA', 'NIS2', 'BSI_GRUNDSCHUTZ', 'GDPR',
            'ISO27001', 'ISO27701', 'ISO27701_2025', 'BSI-C5', 'BSI-C5-2025',
            'KRITIS', 'KRITIS-HEALTH', 'DIGAV', 'TKG-2024', 'GXP'
        ];

        foreach ($codes as $code) {
            $framework = $this->createMock(ComplianceFramework::class);
            $framework->method('getCode')->willReturn($code);
            $frameworks[] = $framework;
        }

        $this->frameworkRepository->method('findAll')->willReturn($frameworks);

        $stats = $this->service->getFrameworkStatistics();

        $this->assertEquals(15, $stats['total_available']);
        $this->assertEquals(15, $stats['total_loaded']);
        $this->assertEquals(0, $stats['total_not_loaded']);
        $this->assertEquals(100.0, $stats['compliance_percentage']);
        $this->assertEquals(8, $stats['mandatory_frameworks']);
        $this->assertEquals(8, $stats['mandatory_loaded']);
        $this->assertEquals(0, $stats['mandatory_not_loaded']);
    }

    public function testLoadFrameworkWithoutExistingFrameworkEntry(): void
    {
        $newFramework = $this->createMock(ComplianceFramework::class);
        $newFramework->method('getId')->willReturn(42);

        $this->frameworkRepository->expects($this->exactly(2))
            ->method('findOneBy')
            ->with(['code' => 'ISO27001'])
            ->willReturnOnConsecutiveCalls(null, $newFramework);

        $this->iso27001Command->expects($this->once())
            ->method('run')
            ->willReturn(0);

        $result = $this->service->loadFramework('ISO27001');

        $this->assertTrue($result['success']);
        $this->assertEquals(42, $result['framework_id']);
    }

    public function testLoadFrameworkWithExistingFrameworkButNoRequirements(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('getId')->willReturn(5);
        $framework->method('getRequirements')->willReturn(new ArrayCollection());

        $this->frameworkRepository->expects($this->exactly(2))
            ->method('findOneBy')
            ->with(['code' => 'ISO27701_2025'])
            ->willReturn($framework);

        $this->iso27701v2025Command->expects($this->once())
            ->method('run')
            ->willReturn(0);

        $result = $this->service->loadFramework('ISO27701_2025');

        $this->assertTrue($result['success']);
        $this->assertEquals(5, $result['framework_id']);
    }
}
