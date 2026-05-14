<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentVersionRepository;
use App\Service\AuditLogger;
use App\Service\Evidence\ContentHashCalculator;
use App\Service\Evidence\EvidenceVersioningService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * F4 — EvidenceVersioningService unit tests.
 */
#[AllowMockObjectsWithoutExpectations]
class EvidenceVersioningServiceTest extends TestCase
{
    private EvidenceVersioningService $service;
    private EntityManagerInterface $em;
    private DocumentVersionRepository $versionRepo;
    private ContentHashCalculator $hashCalculator;
    private RequestStack $requestStack;
    private AuditLogger $auditLogger;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->versionRepo = $this->createMock(DocumentVersionRepository::class);
        $this->hashCalculator = $this->createMock(ContentHashCalculator::class);
        $this->session = $this->createMock(SessionInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->requestStack->method('getSession')->willReturn($this->session);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->service = new EvidenceVersioningService(
            $this->em,
            $this->versionRepo,
            $this->hashCalculator,
            $this->requestStack,
            $this->auditLogger,
            '/project',
        );
    }

    #[Test]
    public function testCreateVersionReturnsDuplicateWhenHashMatches(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $document = $this->createMock(Document::class);
        $document->method('getTenant')->willReturn($tenant);
        $document->method('getId')->willReturn(1);
        $document->method('getCurrentVersion')->willReturn(null);

        $existingVersion = new DocumentVersion();

        $uploadedFile = $this->createMock(UploadedFile::class);
        $uploadedFile->method('getPathname')->willReturn('/tmp/test');
        $uploadedFile->method('getSize')->willReturn(1000);
        $uploadedFile->method('getMimeType')->willReturn('application/pdf');

        $this->hashCalculator
            ->method('calculateFromPath')
            ->willReturn('abc123def456');

        // Hash matches — existing version returned
        $this->versionRepo
            ->method('findByDocumentAndHash')
            ->willReturn($existingVersion);

        $result = $this->service->createVersion(
            $document,
            $uploadedFile,
            '/uploads/test.pdf',
            'test.pdf',
        );

        self::assertSame($existingVersion, $result['version']);
        self::assertTrue($result['is_duplicate']);
    }

    #[Test]
    public function testCanUndoReturnsFalseWhenNoSessionData(): void
    {
        $this->session->method('get')->willReturn(null);

        self::assertFalse($this->service->canUndo(42));
    }

    #[Test]
    public function testCanUndoReturnsFalseWhenVersionIdMismatch(): void
    {
        $this->session->method('get')->willReturn([
            'version_id' => 99,
            'document_id' => 1,
            'previous_version_id' => null,
            'created_at' => (new DateTimeImmutable())->getTimestamp(),
        ]);

        self::assertFalse($this->service->canUndo(42));
    }

    #[Test]
    public function testUndoReturnsFalseWhenSessionEmpty(): void
    {
        $this->session->method('get')->willReturn(null);

        $result = $this->service->undo(42);

        self::assertFalse($result);
    }

    #[Test]
    public function testUndoReturnsFalseWhenWindowExpired(): void
    {
        $this->session->method('get')->willReturn([
            'version_id' => 42,
            'document_id' => 1,
            'previous_version_id' => null,
            'created_at' => (new DateTimeImmutable('-10 seconds'))->getTimestamp(),
        ]);
        $this->session->expects($this->once())->method('remove');

        $result = $this->service->undo(42);

        self::assertFalse($result);
    }
}
