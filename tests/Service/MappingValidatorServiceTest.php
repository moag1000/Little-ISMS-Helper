<?php

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\MappingValidatorService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class MappingValidatorServiceTest extends TestCase
{
    private function makeService(?ComplianceFramework $foundFw = null, ?ComplianceRequirement $foundReq = null): MappingValidatorService
    {
        $fwRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $fwRepo->method('findOneBy')->willReturn($foundFw);

        $reqRepo = $this->createMock(ComplianceRequirementRepository::class);
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
}
