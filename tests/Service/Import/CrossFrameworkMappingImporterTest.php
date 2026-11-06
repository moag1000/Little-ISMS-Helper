<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use App\Service\Import\CrossFrameworkMappingImporter;
use App\Service\MappingValidatorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class CrossFrameworkMappingImporterTest extends TestCase
{
    #[Test]
    public function aliasFrameworkCodeAndPrefixedRequirementIdsResolveThroughTheValidator(): void
    {
        $source = (new ComplianceFramework())->setCode('ISO-22301');
        $target = (new ComplianceFramework())->setCode('ISO27001');
        $srcReq = (new ComplianceRequirement())->setRequirementId('ISO-22301-8.2');
        $tgtReq = (new ComplianceRequirement())->setRequirementId('ISO27001-A.5.1');

        $validator = $this->createStub(MappingValidatorService::class);
        $validator->method('resolveFramework')->willReturnCallback(
            static fn (string $code): ?ComplianceFramework => match ($code) {
                'ISO22301' => $source, // alias of ISO-22301
                'ISO27001' => $target,
                default => null,
            }
        );
        $validator->method('resolveRequirement')->willReturnCallback(
            static fn (ComplianceFramework $fw, string $id): ?ComplianceRequirement => match ($id) {
                '8.2' => $srcReq,
                'A.5.1' => $tgtReq,
                default => null,
            }
        );

        $mappingRepo = $this->createStub(ComplianceMappingRepository::class);
        $mappingRepo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        $importer = new CrossFrameworkMappingImporter($em, $mappingRepo, $validator);
        $csv = "source_requirement_id,target_requirement_id,mapping_type\n8.2,A.5.1,partial\n8.2,NOPE,partial\n";
        $result = $importer->import($csv, 'ISO22301', 'ISO27001');

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['skipped_missing']);
        self::assertCount(1, array_filter($persisted, static fn (object $e): bool => $e instanceof ComplianceMapping));
    }
}
