<?php

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\MappingLibraryLoader;
use App\Service\MappingQualityScoreService;
use App\Service\MappingValidatorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class MappingLibraryLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mapping-loader-test-' . uniqid('', true);
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.yaml') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function writeYaml(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private function makeLoader(
        bool $validatorOk = true,
        ?ComplianceFramework $sourceFw = null,
        ?ComplianceFramework $targetFw = null,
        ?ComplianceRequirement $sourceReq = null,
        ?ComplianceRequirement $targetReq = null,
        ?ComplianceMapping $existingMapping = null,
    ): MappingLibraryLoader {
        $em = $this->createStub(EntityManagerInterface::class);
        // persist() + flush() sind void — kein willReturn nötig.

        $validator = $this->createStub(MappingValidatorService::class);
        $validator->method('validate')->willReturn([
            'errors' => $validatorOk ? [] : ['Schema-Error'],
            'warnings' => [],
        ]);

        $mqs = $this->createStub(MappingQualityScoreService::class);
        $mqs->method('compute')->willReturn(['mqs' => 80, 'breakdown' => []]);

        $fwRepo = $this->createStub(ComplianceFrameworkRepository::class);
        $fwRepo->method('findOneBy')->willReturnCallback(
            function (array $criteria) use ($sourceFw, $targetFw): ?ComplianceFramework {
                $code = $criteria['code'] ?? $criteria['name'] ?? null;
                if ($code === 'ISO27001') return $sourceFw;
                if ($code === 'NIS2') return $targetFw;
                return null;
            }
        );

        $reqRepo = $this->createStub(ComplianceRequirementRepository::class);
        $reqRepo->method('findOneBy')->willReturnCallback(
            function (array $criteria) use ($sourceReq, $targetReq): ?ComplianceRequirement {
                $reqId = $criteria['requirementId'] ?? null;
                if ($reqId === 'A.5.7') return $sourceReq;
                if ($reqId === '21.2.f') return $targetReq;
                return null;
            }
        );

        $mappingRepo = $this->createStub(ComplianceMappingRepository::class);
        $mappingRepo->method('findOneBy')->willReturn($existingMapping);

        return new MappingLibraryLoader(
            $em,
            $validator,
            $mqs,
            $fwRepo,
            $reqRepo,
            $mappingRepo,
            $this->tmpDir,
        );
    }

    private function validYaml(): string
    {
        return <<<YAML
schema_version: '1.1'
library:
  type: mapping
  id: 'test_v1.0'
  source_framework: 'ISO27001'
  target_framework: 'NIS2'
  version: 1
  effective_from: '2024-09-01'
  provenance:
    primary_source: 'Test'
  methodology:
    type: 'text_comparison_with_expert_review'
    description: 'Test methodology'
  lifecycle:
    state: 'published'
mappings:
  - source: 'A.5.7'
    target: '21.2.f'
    relationship: 'equivalent'
    confidence: 'high'
    rationale: 'Test rationale'
YAML;
    }

    public function testFileNotReadableReturnsError(): void
    {
        $loader = $this->makeLoader();
        $result = $loader->load('/nonexistent/path/file.yaml');

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('not readable', $result['errors'][0]);
    }

    public function testInvalidYamlReturnsParseError(): void
    {
        $path = $this->writeYaml('broken.yaml', "schema_version: '1.1'\nbroken:\n  - [unclosed");
        $loader = $this->makeLoader();
        $result = $loader->load($path);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidationErrorsBlockImport(): void
    {
        $path = $this->writeYaml('invalid.yaml', $this->validYaml());
        $loader = $this->makeLoader(validatorOk: false);
        $result = $loader->load($path);

        $this->assertFalse($result['success']);
        $this->assertContains('Schema-Error', $result['errors']);
        $this->assertSame(0, $result['imported']);
    }

    public function testNewMappingIsImported(): void
    {
        $sourceFw = (new ComplianceFramework())->setCode('ISO27001');
        $targetFw = (new ComplianceFramework())->setCode('NIS2');
        $sourceReq = (new ComplianceRequirement())->setRequirementId('A.5.7');
        $targetReq = (new ComplianceRequirement())->setRequirementId('21.2.f');

        $path = $this->writeYaml('valid.yaml', $this->validYaml());
        $loader = $this->makeLoader(
            sourceFw: $sourceFw,
            targetFw: $targetFw,
            sourceReq: $sourceReq,
            targetReq: $targetReq,
        );
        $result = $loader->load($path);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['updated']);
    }

    public function testExistingMappingIsUpdated(): void
    {
        $sourceFw = (new ComplianceFramework())->setCode('ISO27001');
        $targetFw = (new ComplianceFramework())->setCode('NIS2');
        $sourceReq = (new ComplianceRequirement())->setRequirementId('A.5.7');
        $targetReq = (new ComplianceRequirement())->setRequirementId('21.2.f');
        $existingMapping = new ComplianceMapping();
        $existingMapping->setSourceRequirement($sourceReq);
        $existingMapping->setTargetRequirement($targetReq);

        $path = $this->writeYaml('valid.yaml', $this->validYaml());
        $loader = $this->makeLoader(
            sourceFw: $sourceFw,
            targetFw: $targetFw,
            sourceReq: $sourceReq,
            targetReq: $targetReq,
            existingMapping: $existingMapping,
        );
        $result = $loader->load($path);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['updated']);
        // Lifecycle aus YAML übernommen
        $this->assertSame('published', $existingMapping->getLifecycleState());
        // Provenance aus YAML übernommen
        $this->assertSame('Test', $existingMapping->getProvenanceSource());
    }

    public function testMissingRequirementsAreSkipped(): void
    {
        $sourceFw = (new ComplianceFramework())->setCode('ISO27001');
        $targetFw = (new ComplianceFramework())->setCode('NIS2');
        // Keine Requirements bereitgestellt → findOneBy gibt null zurück
        $path = $this->writeYaml('valid.yaml', $this->validYaml());
        $loader = $this->makeLoader(
            sourceFw: $sourceFw,
            targetFw: $targetFw,
            sourceReq: null,
            targetReq: null,
        );
        $result = $loader->load($path);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['imported']);
    }

    public function testRelationshipTranslatesToMappingPercentage(): void
    {
        $sourceFw = (new ComplianceFramework())->setCode('ISO27001');
        $targetFw = (new ComplianceFramework())->setCode('NIS2');
        $sourceReq = (new ComplianceRequirement())->setRequirementId('A.5.7');
        $targetReq = (new ComplianceRequirement())->setRequirementId('21.2.f');

        // Equivalent → 100
        $yaml = str_replace("'equivalent'", "'equivalent'", $this->validYaml());
        $path = $this->writeYaml('eq.yaml', $yaml);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted): void {
            $persisted[] = $e;
        });
        // flush() ist void — kein willReturn nötig.

        $validator = $this->createStub(MappingValidatorService::class);
        $validator->method('validate')->willReturn(['errors' => [], 'warnings' => []]);

        $mqs = $this->createStub(MappingQualityScoreService::class);
        $mqs->method('compute')->willReturn(['mqs' => 80, 'breakdown' => []]);

        $fwRepo = $this->createStub(ComplianceFrameworkRepository::class);
        $fwRepo->method('findOneBy')->willReturnCallback(
            fn(array $c) => match ($c['code'] ?? null) { 'ISO27001' => $sourceFw, 'NIS2' => $targetFw, default => null },
        );

        $reqRepo = $this->createStub(ComplianceRequirementRepository::class);
        $reqRepo->method('findOneBy')->willReturnCallback(
            fn(array $c) => match ($c['requirementId'] ?? null) { 'A.5.7' => $sourceReq, '21.2.f' => $targetReq, default => null },
        );

        $mappingRepo = $this->createStub(ComplianceMappingRepository::class);
        $mappingRepo->method('findOneBy')->willReturn(null);

        $loader = new MappingLibraryLoader($em, $validator, $mqs, $fwRepo, $reqRepo, $mappingRepo, $this->tmpDir);
        $loader->load($path);

        $mappings = array_filter($persisted, static fn($e) => $e instanceof ComplianceMapping);
        $this->assertNotEmpty($mappings);
        $first = array_values($mappings)[0];
        $this->assertSame(100, $first->getMappingPercentage(), 'equivalent should translate to 100%');
    }
}
