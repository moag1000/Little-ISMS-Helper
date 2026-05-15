<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Library\LibraryRoundtripService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for LibraryRoundtripService.
 *
 * Tests YAML export structure, CSV export headers/rows, and semantic
 * round-trip correctness: buildExportData → parse → same structure.
 */
#[AllowMockObjectsWithoutExpectations]
final class LibraryRoundtripServiceTest extends TestCase
{
    /** @var MockObject&ComplianceRequirementRepository */
    private MockObject $requirementRepo;

    private LibraryRoundtripService $service;

    protected function setUp(): void
    {
        $this->requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $this->service = new LibraryRoundtripService($this->requirementRepo);
    }

    #[Test]
    public function exportYamlProducesValidYamlWithMetadata(): void
    {
        $framework = $this->buildSampleFramework();
        [$parentReq, $childReq] = $this->buildSampleRequirements($framework);

        $this->requirementRepo->method('findBy')->willReturn([$parentReq, $childReq]);

        $yaml = $this->service->exportYaml($framework);

        self::assertIsString($yaml);
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parse($yaml);

        self::assertArrayHasKey('metadata', $parsed);
        self::assertArrayHasKey('bausteine', $parsed);
        self::assertSame('BSI-TEST', $parsed['metadata']['code']);
        self::assertSame('BSI Test Framework', $parsed['metadata']['name']);
    }

    #[Test]
    public function exportYamlOrganisesChildrenUnderParentBaustein(): void
    {
        $framework = $this->buildSampleFramework();
        [$parentReq, $childReq] = $this->buildSampleRequirements($framework);

        $this->requirementRepo->method('findBy')->willReturn([$parentReq, $childReq]);

        $yaml = $this->service->exportYaml($framework);
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parse($yaml);

        self::assertCount(1, $parsed['bausteine']);
        $baustein = $parsed['bausteine'][0];
        self::assertSame('ISMS.1', $baustein['id']);
        self::assertCount(1, $baustein['anforderungen']);
        self::assertSame('ISMS.1.A1', $baustein['anforderungen'][0]['id']);
    }

    #[Test]
    public function exportCsvContainsHeaderAndDataRow(): void
    {
        $framework = $this->buildSampleFramework();
        [$parentReq, $childReq] = $this->buildSampleRequirements($framework);

        $this->requirementRepo->method('findBy')->willReturn([$parentReq, $childReq]);

        $csv = $this->service->exportCsv($framework);

        self::assertIsString($csv);
        self::assertStringContainsString('framework_code', $csv);
        self::assertStringContainsString('BSI-TEST', $csv);
        self::assertStringContainsString('ISMS.1', $csv);
    }

    #[Test]
    public function exportCsvHasCorrectColumnCount(): void
    {
        $framework = $this->buildSampleFramework();
        [$parentReq] = $this->buildSampleRequirements($framework);

        $this->requirementRepo->method('findBy')->willReturn([$parentReq]);

        $csv = $this->service->exportCsv($framework);
        $lines = explode("\n", trim($csv));
        $header = str_getcsv($lines[0], ',', '"', '\\');

        self::assertCount(10, $header);
    }

    #[Test]
    public function buildExportDataRoundtripProducesSameMetadata(): void
    {
        $framework = $this->buildSampleFramework();
        [$parentReq, $childReq] = $this->buildSampleRequirements($framework);

        $this->requirementRepo->method('findBy')->willReturn([$parentReq, $childReq]);

        $data = $this->service->buildExportData($framework);
        $yaml = Yaml::dump($data, 8, 2);

        /** @var array<string, mixed> $reparsed */
        $reparsed = Yaml::parse($yaml);

        self::assertSame($data['metadata']['code'], $reparsed['metadata']['code']);
        self::assertSame($data['metadata']['version'], $reparsed['metadata']['version']);
        self::assertCount(count($data['bausteine']), $reparsed['bausteine']);
    }

    #[Test]
    public function exportYamlMapsChildPriorityToLevel(): void
    {
        $framework = $this->buildSampleFramework();
        [$parentReq, $childReq] = $this->buildSampleRequirements($framework);

        // Make child priority 'high' → should map to 'erhoeht'
        $childReq->setPriority('high');

        $this->requirementRepo->method('findBy')->willReturn([$parentReq, $childReq]);

        $data = $this->service->buildExportData($framework);

        self::assertNotEmpty($data['bausteine']);
        $baustein = $data['bausteine'][0];
        self::assertSame('erhoeht', $baustein['anforderungen'][0]['level']);
    }

    private function buildSampleFramework(): ComplianceFramework
    {
        $framework = new ComplianceFramework();
        $framework->setCode('BSI-TEST');
        $framework->setName('BSI Test Framework');
        $framework->setVersion('2024.1');
        $framework->setApplicableIndustry('all');
        $framework->setRegulatoryBody('BSI');
        $framework->setMandatory(false);

        return $framework;
    }

    /**
     * @return array{ComplianceRequirement, ComplianceRequirement}
     */
    private function buildSampleRequirements(ComplianceFramework $framework): array
    {
        $parentReq = new ComplianceRequirement();
        $parentReq->setFramework($framework);
        $parentReq->setRequirementId('ISMS.1');
        $parentReq->setTitle('Sicherheitsmanagement');
        $parentReq->setDescription('Baustein description');
        $parentReq->setCategory('ISMS');
        $parentReq->setPriority('medium');
        $parentReq->setRequirementType('core');

        $childReq = new ComplianceRequirement();
        $childReq->setFramework($framework);
        $childReq->setRequirementId('ISMS.1.A1');
        $childReq->setTitle('Erste Anforderung');
        $childReq->setDescription('Text der Anforderung');
        $childReq->setCategory('ISMS');
        $childReq->setPriority('low');
        $childReq->setRequirementType('detailed');
        $childReq->setParentRequirement($parentReq);

        return [$parentReq, $childReq];
    }
}
