<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Import\BsiProfileXmlImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for the BSI-Profile-XML importer.
 *
 * Uses PHPUnit stubs only — no DB roundtrip. Frameworks and requirements
 * are returned by repository stubs so the analyse() path can be tested
 * end-to-end against a real fixture file on disk.
 */
final class BsiProfileXmlImporterTest extends TestCase
{
    private const FIXTURE_VALID = __DIR__ . '/../../Fixtures/import/bsi_profile_v1_sample.xml';

    #[Test]
    public function testValidFixtureProducesRows(): void
    {
        $importer = $this->buildImporter(knownFrameworks: ['BSI_GRUNDSCHUTZ', 'ISO27001']);

        $result = $importer->analyse(self::FIXTURE_VALID);

        self::assertNull($result['header_error'], 'Valid fixture must not report header_error');
        self::assertCount(4, $result['rows'], 'Fixture has 4 mappings');
        // With repositories returning stub requirements, none should be "error".
        $statuses = array_column($result['rows'], 'status');
        self::assertNotContains('error', $statuses, 'Valid rows should not be flagged as error');
    }

    #[Test]
    public function testMalformedXmlProducesHeaderError(): void
    {
        $path = $this->writeTempXml('<not-a-profile version="1.0"><foo/></not-a-profile>');
        try {
            $importer = $this->buildImporter(knownFrameworks: []);
            $result = $importer->analyse($path);

            self::assertSame('compliance_import.preview.xml_parse_error', $result['header_error']);
            self::assertSame([], $result['rows']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function testOutOfRangePercentageIsFlagged(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<bsi-profile version="1.0" source="test">
  <metadata>
    <profile-name>Out of range</profile-name>
  </metadata>
  <mappings>
    <mapping>
      <source framework="BSI_GRUNDSCHUTZ" requirement="CON.1.A1"/>
      <target framework="ISO27001" requirement="A.5.23"/>
      <percentage>200</percentage>
      <confidence>high</confidence>
      <rationale>Too high.</rationale>
    </mapping>
  </mappings>
</bsi-profile>
XML;
        $path = $this->writeTempXml($xml);
        try {
            $importer = $this->buildImporter(knownFrameworks: ['BSI_GRUNDSCHUTZ', 'ISO27001']);
            $result = $importer->analyse($path);

            self::assertNull($result['header_error']);
            self::assertCount(1, $result['rows']);
            self::assertSame('error', $result['rows'][0]['status']);
            self::assertSame(
                'compliance_import.preview.xml_percentage_invalid',
                $result['rows'][0]['message'],
            );
            self::assertSame(1, $result['summary']['error']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param list<string> $knownFrameworks
     */
    private function buildImporter(array $knownFrameworks): BsiProfileXmlImporter
    {
        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findOneBy')->willReturnCallback(
            function (array $criteria) use ($knownFrameworks): ?ComplianceFramework {
                $code = (string) ($criteria['code'] ?? '');
                if (!in_array($code, $knownFrameworks, true)) {
                    return null;
                }
                $framework = $this->createMock(ComplianceFramework::class);
                $framework->method('getCode')->willReturn($code);

                return $framework;
            }
        );

        $requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $requirementRepo->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?ComplianceRequirement {
                // Always resolve if framework is set — emulates existing requirements.
                if (!isset($criteria['framework']) || !isset($criteria['requirementId'])) {
                    return null;
                }

                return $this->createMock(ComplianceRequirement::class);
            }
        );

        $mappingRepo = $this->createMock(ComplianceMappingRepository::class);
        $mappingRepo->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        return new BsiProfileXmlImporter(
            $entityManager,
            $frameworkRepo,
            $requirementRepo,
            $mappingRepo,
        );
    }

    private function writeTempXml(string $xml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bsiprof_') . '.xml';
        file_put_contents($path, $xml);

        return $path;
    }
}
