<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Library\VdaIsaImporter;
use App\Service\Tisax\TisaxCatalogueProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for VdaIsaImporter.
 *
 * Since the consolidation, the canonical TISAX catalogue is owned by
 * TisaxCatalogueProvider (single importer). For a TISAX YAML, VdaIsaImporter
 * delegates to that provider; for any other library YAML it keeps the generic
 * kontrollen seeding path. isSkeletonOnly() still advertises that ENX-licensed
 * full text must come via BYO upload (requiresUpload=true).
 */
final class VdaIsaSkeletonImportTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ComplianceFrameworkRepository&MockObject $frameworkRepository;
    private ComplianceRequirementRepository&MockObject $requirementRepository;
    private TisaxCatalogueProvider&MockObject $catalogueProvider;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->catalogueProvider = $this->createMock(TisaxCatalogueProvider::class);
    }

    private function makeImporter(string $dir): VdaIsaImporter
    {
        return new VdaIsaImporter(
            $this->entityManager,
            $this->frameworkRepository,
            $this->requirementRepository,
            $dir,
            $this->catalogueProvider,
        );
    }

    private function writeFixture(string $dir, string $yaml): void
    {
        $target = $dir . '/fixtures/library/frameworks';
        mkdir($target, 0777, true);
        file_put_contents($target . '/vda-isa-tisax-v6.yaml', $yaml);
    }

    private static function tisaxYaml(): string
    {
        // Uses canonical code 'TISAX' (§4.1 consolidation, 2026-06-01).
        return <<<YAML
metadata:
  code: TISAX
  name: TISAX (VDA-ISA 6.0)
  version: "6.0"
  body: VDA / ENX Association
  requiresUpload: true
  legalNote: ENX-licensed full text not redistributed.
requirements:
  - controlId: '1.1.1'
    title: 'VDA-ISA 1.1.1'
    category: 'information_security'
    section: '1.1'
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
    public function tisaxYamlDelegatesToCatalogueProviderAndSeedsNumbers(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tisax_skel_' . uniqid();
        $this->writeFixture($tmpDir, self::tisaxYaml());

        // Framework not yet present → frameworks_created path.
        $this->frameworkRepository->expects($this->once())->method('findOneBy')->willReturn(null);
        // The single importer is the provider; VdaIsaImporter must delegate to it.
        $this->catalogueProvider->expects($this->once())
            ->method('loadCatalogue')
            ->with(true)
            ->willReturn([
                'framework' => $this->createMock(ComplianceFramework::class),
                'created' => 80,
                'updated' => 0,
                'skipped' => 0,
                'total' => 80,
            ]);

        $stats = $this->makeImporter($tmpDir)->importDefault();

        $this->assertSame(80, $stats['requirements_created']);
        $this->assertSame(1, $stats['frameworks_created']);
        $this->assertEmpty($stats['errors']);
    }

    #[Test]
    public function isSkeletonOnlyReturnsTrueForTisaxFixture(): void
    {
        // requiresUpload=true → BYO upload still required for ENX full text.
        $tmpDir = sys_get_temp_dir() . '/tisax_skel_' . uniqid();
        $this->writeFixture($tmpDir, self::tisaxYaml());
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
    public function nonTisaxYamlStillSeedsViaGenericPath(): void
    {
        // code TISAX-TEST (not the canonical TISAX) → generic kontrollen path,
        // provider is NOT consulted.
        $tmpDir = sys_get_temp_dir() . '/tisax_full_' . uniqid();
        $this->writeFixture($tmpDir, self::fullYaml());

        $this->frameworkRepository->method('findOneBy')->willReturn(null);
        $this->requirementRepository->method('findOneBy')->willReturn(null);
        $this->catalogueProvider->expects($this->never())->method('loadCatalogue');
        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $stats = $this->makeImporter($tmpDir)->importDefault();

        $this->assertFalse($stats['skeleton_only']);
        $this->assertGreaterThan(0, $stats['requirements_created']);
    }
}
