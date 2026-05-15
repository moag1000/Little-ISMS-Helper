<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Library\VdaIsaImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VdaIsaImporter.
 *
 * Tests YAML parsing, framework + requirement upsert, Kapitel parents,
 * idempotency, and error handling using a sample TISAX YAML.
 */
#[AllowMockObjectsWithoutExpectations]
final class VdaIsaImporterTest extends TestCase
{
    private string $projectDir;
    private string $sampleYamlPath;

    /** @var MockObject&EntityManagerInterface */
    private MockObject $entityManager;

    /** @var MockObject&ComplianceFrameworkRepository */
    private MockObject $frameworkRepo;

    /** @var MockObject&ComplianceRequirementRepository */
    private MockObject $requirementRepo;

    private VdaIsaImporter $importer;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vda_importer_test_' . uniqid();
        mkdir($this->projectDir . '/fixtures/library/frameworks', 0777, true);

        $this->sampleYamlPath = $this->projectDir . '/fixtures/library/frameworks/tisax-test.yaml';
        file_put_contents($this->sampleYamlPath, $this->getSampleYaml());

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepo = $this->createMock(ComplianceRequirementRepository::class);

        $this->importer = new VdaIsaImporter(
            $this->entityManager,
            $this->frameworkRepo,
            $this->requirementRepo,
            $this->projectDir,
        );
    }

    protected function tearDown(): void
    {
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
    public function importYamlCreatesChapterParentAndControl(): void
    {
        $this->frameworkRepo->method('findOneBy')->willReturn(null);
        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $stats = $this->importer->importYaml($this->sampleYamlPath);

        // 1 Kapitel parent + 2 Kontrollen
        self::assertSame(3, $stats['requirements_created']);
    }

    #[Test]
    public function importYamlIsIdempotentOnSecondRun(): void
    {
        $existingFramework = new ComplianceFramework();
        $existingFramework->setCode('TISAX-TEST');
        $existingFramework->setName('TISAX Test');
        $existingFramework->setVersion('6.0');
        $existingFramework->setApplicableIndustry('automotive');
        $existingFramework->setRegulatoryBody('VDA');
        $existingFramework->setMandatory(false);

        $existingReq = new ComplianceRequirement();
        $existingReq->setRequirementId('ISA 1.1.1');
        $existingReq->setTitle('Existing Control');
        $existingReq->setDescription('');
        $existingReq->setPriority('high');
        $existingReq->setRequirementType('detailed');

        $this->frameworkRepo->method('findOneBy')->willReturn($existingFramework);
        $this->requirementRepo->method('findOneBy')->willReturn($existingReq);

        $stats = $this->importer->importYaml($this->sampleYamlPath);

        self::assertSame(0, $stats['frameworks_created']);
        self::assertSame(1, $stats['frameworks_updated']);
        self::assertSame(0, $stats['requirements_created']);
    }

    #[Test]
    public function importYamlReturnsErrorForMissingFile(): void
    {
        $stats = $this->importer->importYaml('/does/not/exist.yaml');

        self::assertNotEmpty($stats['errors']);
        self::assertStringContainsString('not found', $stats['errors'][0]);
    }

    #[Test]
    public function importYamlSetsAutomotiveIndustry(): void
    {
        $capturedFramework = null;

        $this->frameworkRepo->method('findOneBy')->willReturn(null);
        $this->requirementRepo->method('findOneBy')->willReturn(null);
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$capturedFramework): void {
                if ($entity instanceof ComplianceFramework) {
                    $capturedFramework = $entity;
                }
            }
        );

        $this->importer->importYaml($this->sampleYamlPath);

        self::assertInstanceOf(ComplianceFramework::class, $capturedFramework);
        self::assertSame('automotive', $capturedFramework->getApplicableIndustry());
    }

    private function getSampleYaml(): string
    {
        return <<<'YAML'
metadata:
  code: 'TISAX-TEST'
  name: 'TISAX Test v6.0'
  version: '6.0'
  body: 'VDA'

kapitel:
  - id: '1'
    title: 'Informationssicherheits-Management'

kontrollen:
  - id: 'ISA 1.1.1'
    kapitel: '1'
    title: 'Informationssicherheits-Leitlinie'
    description: 'Test Kontrolle'
    minReifegradFuerBasis: 3
    prueffragen:
      - 'Hat das Top-Management die Leitlinie unterzeichnet?'
  - id: 'ISA 1.2.1'
    kapitel: '1'
    title: 'Rollen und Verantwortlichkeiten'
    description: 'Zweite Kontrolle'
    minReifegradFuerBasis: 3
YAML;
    }
}
