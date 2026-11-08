<?php

declare(strict_types=1);

namespace App\Tests\Service\DataIntegrity;

use App\Service\DataIntegrity\UploadOrphanChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UploadOrphanCheckerTest extends TestCase
{
    private MockObject $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    #[Test]
    public function testReturnsEmptyWhenProjectDirIsNull(): void
    {
        $checker = new UploadOrphanChecker($this->entityManager, '');
        $result = $checker->findOrphanedUploads();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('files', $result);
        $this->assertArrayHasKey('scanned', $result);
        $this->assertArrayHasKey('referenced', $result);
        $this->assertArrayHasKey('uploads_dir', $result);
        $this->assertSame([], $result['files']);
        $this->assertSame(0, $result['scanned']);
        $this->assertSame(0, $result['referenced']);
        $this->assertNull($result['uploads_dir']);
    }

    #[Test]
    public function testReturnsEmptyWhenUploadsDirDoesNotExist(): void
    {
        $checker = new UploadOrphanChecker($this->entityManager, '/nonexistent/path');
        $result = $checker->findOrphanedUploads();

        $this->assertSame([], $result['files']);
        $this->assertSame(0, $result['scanned']);
        $this->assertNull($result['uploads_dir']);
    }

    #[Test]
    public function testCollectReferencedUploadPathsReturnsArrayWhenEntityManagerHasNoData(): void
    {
        // EM metadata factory throws for unknown classes → checker returns empty set.
        $metaFactory = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadataFactory::class);
        $metaFactory->method('getMetadataFor')->willThrowException(new \RuntimeException('no metadata'));
        $this->entityManager->method('getMetadataFactory')->willReturn($metaFactory);

        $checker = new UploadOrphanChecker($this->entityManager, '/some/dir');
        $result = $checker->collectReferencedUploadPaths();

        $this->assertIsArray($result);
    }

    #[Test]
    public function testReturnStructureHasExpectedKeys(): void
    {
        $checker = new UploadOrphanChecker($this->entityManager, '');
        $result = $checker->findOrphanedUploads();

        // Verify the return-type contract matches what callers (DataRepairController,
        // ScanBrokenReferencesJob, DataIntegrityService) expect.
        $this->assertArrayHasKey('files', $result);
        $this->assertArrayHasKey('scanned', $result);
        $this->assertArrayHasKey('referenced', $result);
        $this->assertArrayHasKey('uploads_dir', $result);
        $this->assertIsArray($result['files']);
        $this->assertIsInt($result['scanned']);
        $this->assertIsInt($result['referenced']);
    }

    #[Test]
    public function evidenceAreasBelowVarAreScannedButImportScratchIsNot(): void
    {
        // S11: since S7 documents and training materials live below var/uploads; the scan
        // only knew public/uploads and never saw them.
        $dir = sys_get_temp_dir() . '/orphan_' . uniqid();
        mkdir($dir . '/var/uploads/documents', 0750, true);
        mkdir($dir . '/var/uploads/compliance-import', 0750, true);
        file_put_contents($dir . '/var/uploads/documents/lost.pdf', '%PDF');
        file_put_contents($dir . '/var/uploads/compliance-import/pending.xlsx', 'x');

        $metaFactory = $this->createStub(\Doctrine\ORM\Mapping\ClassMetadataFactory::class);
        $metaFactory->method('getMetadataFor')->willThrowException(new \RuntimeException('no metadata'));
        $this->entityManager->method('getMetadataFactory')->willReturn($metaFactory);

        $result = (new UploadOrphanChecker($this->entityManager, $dir))->findOrphanedUploads();

        self::assertSame(['/uploads/documents/lost.pdf'], array_column($result['files'], 'relative'));
        self::assertSame(1, $result['scanned']);

        unlink($dir . '/var/uploads/documents/lost.pdf');
        unlink($dir . '/var/uploads/compliance-import/pending.xlsx');
    }

    #[Test]
    public function trainingMaterialsAreReferenced(): void
    {
        $set = UploadOrphanChecker::trainingMaterialPaths([
            ['files' => [['filename' => 'kurs.pdf']]],
            ['files' => '[{"filename":"awareness.pptx"}]'],
        ]);

        self::assertArrayHasKey('/uploads/training-materials/kurs.pdf', $set);
        self::assertArrayHasKey('/uploads/training-materials/awareness.pptx', $set);
    }
}
