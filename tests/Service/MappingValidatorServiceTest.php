<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\MappingValidatorService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MappingValidatorServiceTest extends TestCase
{
    private function makeService(?ComplianceFramework $foundFw = null, ?ComplianceRequirement $foundReq = null): MappingValidatorService
    {
        $fwRepo = $this->createStub(ComplianceFrameworkRepository::class);
        $fwRepo->method('findOneBy')->willReturn($foundFw);

        $reqRepo = $this->createStub(ComplianceRequirementRepository::class);
        $reqRepo->method('findOneBy')->willReturn($foundReq);
        $reqRepo->method('findBy')->willReturn(array_fill(0, 100, $foundReq ?? new ComplianceRequirement()));

        return new MappingValidatorService($fwRepo, $reqRepo);
    }

    private function validPayload(): array
    {
        return [
            'schema_version' => '1.1',
            'library' => [
                'type' => 'mapping',
                'source_framework' => 'ISO27001',
                'target_framework' => 'NIS2',
                'version' => 1,
                'effective_from' => '2024-09-01',
                'provenance' => [
                    'primary_source' => 'ENISA Guidance NIS2 v2024-09',
                ],
                'methodology' => [
                    'type' => 'text_comparison_with_expert_review',
                    'description' => 'Volltext + 4-Augen-Review.',
                ],
                'lifecycle' => ['state' => 'published'],
            ],
            'mappings' => [
                [
                    'source' => 'A.5.7',
                    'target' => '21.2.f',
                    'relationship' => 'equivalent',
                    'confidence' => 'high',
                    'rationale' => 'A.5.7 == 21.2.f.',
                ],
            ],
        ];
    }

    #[Test]
    public function testValidPayloadHasNoErrors(): void
    {
        $fw = new ComplianceFramework();
        $req = new ComplianceRequirement();
        $req->setFramework($fw);

        $svc = $this->makeService($fw, $req);
        $result = $svc->validate($this->validPayload());

        $this->assertEmpty($result['errors'], 'Expected no errors, got: ' . implode('; ', $result['errors']));
    }

    #[Test]
    public function testMissingSchemaVersionIsError(): void
    {
        $payload = $this->validPayload();
        unset($payload['schema_version']);

        $svc = $this->makeService(new ComplianceFramework(), new ComplianceRequirement());
        $result = $svc->validate($payload);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('schema_version', $result['errors'][0]);
    }

    #[Test]
    public function testMissingProvenanceIsError(): void
    {
        $payload = $this->validPayload();
        unset($payload['library']['provenance']);

        $svc = $this->makeService(new ComplianceFramework(), new ComplianceRequirement());
        $result = $svc->validate($payload);

        $errors = implode(' | ', $result['errors']);
        $this->assertStringContainsString('provenance', $errors);
    }

    #[Test]
    public function testInvalidRelationshipIsError(): void
    {
        $payload = $this->validPayload();
        $payload['mappings'][0]['relationship'] = 'totally_invented';

        $svc = $this->makeService(new ComplianceFramework(), new ComplianceRequirement());
        $result = $svc->validate($payload);

        $errors = implode(' | ', $result['errors']);
        $this->assertStringContainsString('relationship', $errors);
    }

    #[Test]
    public function testInvalidLifecycleStateIsError(): void
    {
        $payload = $this->validPayload();
        $payload['library']['lifecycle']['state'] = 'frozen_indefinitely';

        $svc = $this->makeService(new ComplianceFramework(), new ComplianceRequirement());
        $result = $svc->validate($payload);

        $errors = implode(' | ', $result['errors']);
        $this->assertStringContainsString('lifecycle.state', $errors);
    }

    #[Test]
    public function testMissingFrameworkInDbIsError(): void
    {
        $payload = $this->validPayload();
        // Service mit findOneBy() = null → Framework nicht gefunden
        $svc = $this->makeService(null, null);
        $result = $svc->validate($payload);

        $errors = implode(' | ', $result['errors']);
        $this->assertStringContainsString('not found in DB', $errors);
    }

    #[Test]
    public function testMissingRationaleIsWarning(): void
    {
        $payload = $this->validPayload();
        unset($payload['mappings'][0]['rationale']);

        $fw = new ComplianceFramework();
        $req = new ComplianceRequirement();
        $req->setFramework($fw);
        $svc = $this->makeService($fw, $req);
        $result = $svc->validate($payload);

        $warnings = implode(' | ', $result['warnings']);
        $this->assertStringContainsString('rationale empty', $warnings);
        $this->assertEmpty($result['errors']);
    }

    #[Test]
    public function testUnknownRequirementIdIsSkippedWithWarningNotError(): void
    {
        $fw = new ComplianceFramework();
        $svc = $this->makeService($fw, null);
        $result = $svc->validate($this->validPayload());

        $this->assertEmpty($result['errors'], implode('; ', $result['errors']));
        $warnings = implode(' | ', $result['warnings']);
        $this->assertStringContainsString('pairs skipped: A.5.7', $warnings);
        $this->assertStringContainsString('pairs skipped: 21.2.f', $warnings);
    }

    #[Test]
    public function testResolveRequirementFallsBackToFrameworkPrefixedId(): void
    {
        $fw = (new ComplianceFramework())->setCode('ISO27001');
        $clause = (new ComplianceRequirement())->setRequirementId('ISO27001-4.1');
        $svc = $this->makeLookupService(static fn (array $criteria): ?ComplianceRequirement =>
            ($criteria['requirementId'] ?? null) === 'ISO27001-4.1' ? $clause : null);

        $this->assertSame($clause, $svc->resolveRequirement($fw, '4.1'));
        $this->assertSame($clause, $svc->resolveRequirement($fw, 'ISO27001-4.1'));
        $this->assertNull($svc->resolveRequirement($fw, '4.2'));
    }

    #[Test]
    public function testResolveRequirementMapsGrundschutzBausteinToFirstRequirement(): void
    {
        $bsi = (new ComplianceFramework())->setCode('BSI_GRUNDSCHUTZ');
        $first = (new ComplianceRequirement())->setRequirementId('ISMS.1.A1');
        $svc = $this->makeLookupService(static fn (array $criteria): ?ComplianceRequirement =>
            ($criteria['category'] ?? null) === 'ISMS.1' ? $first : null);

        $this->assertSame($first, $svc->resolveRequirement($bsi, 'ISMS.1'));
        // Baustein rule is IT-Grundschutz-only and needs a Baustein-shaped code.
        $this->assertNull($svc->resolveRequirement((new ComplianceFramework())->setCode('BSI-GSPP'), 'ISMS.1'));
        $this->assertNull($svc->resolveRequirement($bsi, 'Kapitel 4'));
    }

    /**
     * @param \Closure(array<string, mixed>): ?ComplianceRequirement $lookup
     */
    private function makeLookupService(\Closure $lookup): MappingValidatorService
    {
        $reqRepo = $this->createStub(ComplianceRequirementRepository::class);
        $reqRepo->method('findOneBy')->willReturnCallback($lookup);

        return new MappingValidatorService($this->createStub(ComplianceFrameworkRepository::class), $reqRepo);
    }
}
