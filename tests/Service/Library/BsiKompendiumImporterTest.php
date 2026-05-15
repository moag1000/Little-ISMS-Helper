<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Library\BsiKompendiumImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BsiKompendiumImporter.
 *
 * Tests YAML parsing, framework + requirement upsert, idempotency,
 * error handling, and stats accuracy using a sample YAML file.
 */
#[AllowMockObjectsWithoutExpectations]
final class BsiKompendiumImporterTest extends TestCase
{
    private string $projectDir;
    private string $sampleYamlPath;

    /** @var MockObject&EntityManagerInterface */
    private MockObject $entityManager;

    /** @var MockObject&ComplianceFrameworkRepository */
    private MockObject $frameworkRepo;

    /** @var MockObject&ComplianceRequirementRepository */
    private MockObject $requirementRepo;

    private BsiKompendiumImporter $importer;

    protected function setUp(): void
    {
        // Write a minimal sample YAML for testing
        $this->projectDir = sys_get_temp_dir() . '/bsi_importer_test_' . uniqid();
        mkdir($this->projectDir . '/fixtures/library/frameworks', 0777, true);

        $this->sampleYamlPath = $this->projectDir . '/fixtures/library/frameworks/bsi-test.yaml';
        file_put_contents($this->sampleYamlPath, $this->getSampleYaml());

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepo = $this->createMock(ComplianceRequirementRepository::class);

        $this->importer = new BsiKompendiumImporter(
            $this->entityManager,
            $this->frameworkRepo,
            $this->requirementRepo,
            $this->projectDir,
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        if (file_exists($this->sampleYamlPath)) {
            unlink($this->sampleYamlPath);
        }
        if (is_dir($this->projectDir . '/fixtures/library/frameworks')) {
            rmdir($this->projectDir . '/fixtures/library/frameworks');
            rmdir($this->projectDir . '/fixtures/library');
            rmdir($this->projectDir . '/fixtures');
        }
        if (is_dir($this->projectDir)) {
            rmdir($this->projectDir);
        }
    }

    #[Test]
    public function importYamlCreatesNewFrameworkOnFirstRun(): void
    {
        $this->frameworkRepo->method('findOneBy')->willReturn(null);
        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $stats = $this->importer->importYaml($this->sampleYamlPath);

        self::assertSame(1, $stats['frameworks_created']);
        self::assertSame(0, $stats['frameworks_updated']);
        self::assertEmpty($stats['errors']);
    }

    #[Test]
    public function importYamlUpdatesExistingFrameworkOnSecondRun(): void
    {
        $existingFramework = new ComplianceFramework();
        $existingFramework->setCode('BSI-TEST');
        $existingFramework->setName('Old Name');
        $existingFramework->setVersion('1.0');
        $existingFramework->setApplicableIndustry('all');
        $existingFramework->setRegulatoryBody('BSI');
        $existingFramework->setMandatory(false);

        $this->frameworkRepo->method('findOneBy')->willReturn($existingFramework);
        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $stats = $this->importer->importYaml($this->sampleYamlPath);

        self::assertSame(0, $stats['frameworks_created']);
        self::assertSame(1, $stats['frameworks_updated']);
    }

    #[Test]
    public function importYamlCreatesRequirementsForBausteine(): void
    {
        $this->frameworkRepo->method('findOneBy')->willReturn(null);
        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $stats = $this->importer->importYaml($this->sampleYamlPath);

        // Sample YAML has 1 Baustein (parent) + 2 Anforderungen (children)
        self::assertSame(3, $stats['requirements_created']);
        self::assertSame(0, $stats['requirements_updated']);
    }

    #[Test]
    public function importYamlIsIdempotentOnSecondRun(): void
    {
        $existingFramework = new ComplianceFramework();
        $existingFramework->setCode('BSI-TEST');
        $existingFramework->setName('Existing');
        $existingFramework->setVersion('2024.1');
        $existingFramework->setApplicableIndustry('all');
        $existingFramework->setRegulatoryBody('BSI');
        $existingFramework->setMandatory(false);

        $existingReq = new ComplianceRequirement();
        $existingReq->setRequirementId('ISMS.1');
        $existingReq->setTitle('Existing Req');
        $existingReq->setDescription('');
        $existingReq->setPriority('medium');
        $existingReq->setRequirementType('core');

        $this->frameworkRepo->method('findOneBy')->willReturn($existingFramework);
        $this->requirementRepo->method('findOneBy')->willReturn($existingReq);

        $stats = $this->importer->importYaml($this->sampleYamlPath);

        self::assertSame(0, $stats['frameworks_created']);
        self::assertSame(1, $stats['frameworks_updated']);
        // All requirements updated (idempotent)
        self::assertSame(0, $stats['requirements_created']);
        self::assertSame(3, $stats['requirements_updated']);
    }

    #[Test]
    public function importYamlReturnsErrorForMissingFile(): void
    {
        $stats = $this->importer->importYaml('/nonexistent/path.yaml');

        self::assertNotEmpty($stats['errors']);
        self::assertStringContainsString('not found', $stats['errors'][0]);
    }

    #[Test]
    public function importYamlReturnsErrorForMissingMetadata(): void
    {
        $invalidPath = $this->projectDir . '/fixtures/library/frameworks/invalid.yaml';
        file_put_contents($invalidPath, "bausteine:\n  - id: ISMS.1\n");

        $stats = $this->importer->importYaml($invalidPath);

        self::assertNotEmpty($stats['errors']);
        unlink($invalidPath);
    }

    #[Test]
    public function importDefaultUsesCorrectPath(): void
    {
        // Create the default fixture path in temp dir
        $defaultPath = $this->projectDir . '/fixtures/library/frameworks/bsi-it-grundschutz-2024.yaml';
        file_put_contents($defaultPath, $this->getSampleYaml());

        $this->frameworkRepo->method('findOneBy')->willReturn(null);
        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $stats = $this->importer->importDefault();

        self::assertSame(1, $stats['frameworks_created']);
        unlink($defaultPath);
    }

    private function getSampleYaml(): string
    {
        return <<<'YAML'
metadata:
  code: 'BSI-TEST'
  name: 'BSI Test Framework'
  version: '2024.1'
  body: 'BSI'

bausteine:
  - id: ISMS.1
    name: 'Sicherheitsmanagement'
    schicht: ISMS
    description: 'Test Baustein'
    anforderungen:
      - id: ISMS.1.A1
        level: basis
        title: 'Erste Anforderung'
        text: 'Beschreibung der ersten Anforderung.'
      - id: ISMS.1.A2
        level: standard
        title: 'Zweite Anforderung'
        text: 'Beschreibung der zweiten Anforderung.'
YAML;
    }
}
