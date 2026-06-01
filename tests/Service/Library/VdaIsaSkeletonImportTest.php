<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Library\VdaIsaImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for VdaIsaImporter skeleton-only guard (OQ-1 ENX licence compliance).
 */
final class VdaIsaSkeletonImportTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ComplianceFrameworkRepository&MockObject $frameworkRepository;
    private ComplianceRequirementRepository&MockObject $requirementRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
    }

    private function makeImporter(string $dir): VdaIsaImporter
    {
        return new VdaIsaImporter(
            $this->entityManager,
            $this->frameworkRepository,
            $this->requirementRepository,
            $dir,
        );
    }

    private function writeFixture(string $dir, string $yaml): void
    {
        $target = $dir . '/fixtures/library/frameworks';
        mkdir($target, 0777, true);
        file_put_contents($target . '/vda-isa-tisax-v6.yaml', $yaml);
    }

    private static function skeletonYaml(): string
    {
        // Uses canonical code 'TISAX' (§4.1 consolidation, 2026-06-01).
        // The legacy alias 'TISAX-VDA-ISA-6' is resolved by VdaIsaImporter to 'TISAX'.
        return <<<YAML
metadata:
  code: TISAX
  name: TISAX VDA ISA v6.0 (Skeleton)
  version: "6.0"
  body: VDA / ENX Association
  requiresUpload: true
  legalNote: ENX-licensed content not redistributed.
maturityLevels:
  0: { code: incomplete, name: Incomplete }
kontrollen: []
YAML;
    }

    private static function fullYaml(): string
    {
        return <<<YAML
metadata:
  code: TISAX-TEST
  name: TISAX Test
  version: "1.0"
  body: Test Body
kontrollen:
  - id: ISA-1.1.1
    title: Test Control
    description: Test description
    kapitel: '1'
    minReifegradFuerBasis: 3
YAML;
    }

    #[Test]
    public function skeletonYamlCreatesFrameworkButSeedsZeroRequirements(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tisax_skel_' . uniqid();
        $this->writeFixture($tmpDir, self::skeletonYaml());

        $this->frameworkRepository->expects($this->once())->method('findOneBy')->willReturn(null);
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->requirementRepository->expects($this->never())->method('findOneBy');

        $stats = $this->makeImporter($tmpDir)->importDefault();

        $this->assertTrue($stats['skeleton_only']);
        $this->assertSame(0, $stats['requirements_created']);
        $this->assertSame(0, $stats['requirements_updated']);
        $this->assertEmpty($stats['errors']);
    }

    #[Test]
    public function isSkeletonOnlyReturnsTrueForSkeletonFixture(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tisax_skel_' . uniqid();
        $this->writeFixture($tmpDir, self::skeletonYaml());
        $this->assertTrue($this->makeImporter($tmpDir)->isSkeletonOnly());
    }

    #[Test]
    public function isSkeletonOnlyReturnsFalseForFullFixture(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tisax_full_' . uniqid();
        $this->writeFixture($tmpDir, self::fullYaml());
        $this->assertFalse($this->makeImporter($tmpDir)->isSkeletonOnly());
    }

    #[Test]
    public function fullYamlStillSeedsRequirementsNormally(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tisax_full_' . uniqid();
        $this->writeFixture($tmpDir, self::fullYaml());

        $this->frameworkRepository->method('findOneBy')->willReturn(null);
        $this->requirementRepository->method('findOneBy')->willReturn(null);
        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $stats = $this->makeImporter($tmpDir)->importDefault();

        $this->assertFalse($stats['skeleton_only']);
        $this->assertGreaterThan(0, $stats['requirements_created']);
    }
}
