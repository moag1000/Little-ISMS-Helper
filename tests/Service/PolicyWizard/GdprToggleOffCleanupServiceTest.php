<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\GdprToggleOffCleanupService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * W6 Gap-D — GdprToggleOffCleanupService unit tests.
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * line 287-289 (Compliance-Manager "Open Q" #2 lines 332-338).
 *
 * Verifies:
 *   - GDPR documents are archived (not deleted)
 *   - GDPR sections are locked (editLocked=true) with cleanup notice
 *   - The service is idempotent on re-run
 *   - The audit-log entry `gdpr_toggled_off_cleanup` is emitted with
 *     the full inventory.
 */
#[AllowMockObjectsWithoutExpectations]
final class GdprToggleOffCleanupServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DocumentRepository&MockObject $documentRepo;
    private DocumentSectionRepository&MockObject $sectionRepo;
    private AuditLogger&MockObject $auditLogger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->documentRepo = $this->createMock(DocumentRepository::class);
        $this->sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
    }

    private function makeService(): GdprToggleOffCleanupService
    {
        return new GdprToggleOffCleanupService(
            $this->em,
            $this->documentRepo,
            $this->sectionRepo,
            $this->auditLogger,
        );
    }

    private function makeTenant(): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(7);
        return $tenant;
    }

    private function makeDocument(int $id, string $status = 'published', bool $archived = false): Document
    {
        $doc = new Document();
        $doc->setStatus($status);
        $doc->setIsArchived($archived);
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($doc, $id);
        return $doc;
    }

    private function makeSection(int $id, bool $locked = false, ?string $snapshot = null): DocumentSection
    {
        $section = new DocumentSection();
        $section->setSectionKey('gdpr_lawful_basis_workplace');
        $section->setStatus(DocumentSection::STATUS_DRAFT);
        $section->setEditLocked($locked);
        if ($snapshot !== null) {
            $section->setContentSnapshot($snapshot);
        }
        $reflection = new \ReflectionProperty(DocumentSection::class, 'id');
        $reflection->setValue($section, $id);
        return $section;
    }

    private function stubQueryBuilder(array $result): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult', 'getSingleScalarResult'])
            ->getMock();
        $query->method('getResult')->willReturn($result);
        $query->method('getSingleScalarResult')->willReturn(0);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'leftJoin', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }

    #[Test]
    public function testGdprDocumentsArchived(): void
    {
        $tenant = $this->makeTenant();
        $doc1 = $this->makeDocument(101, 'published', false);
        $doc2 = $this->makeDocument(102, 'approved', false);

        $this->documentRepo->method('createQueryBuilder')
            ->willReturn($this->stubQueryBuilder([$doc1, $doc2]));
        $this->sectionRepo->method('createQueryBuilder')
            ->willReturn($this->stubQueryBuilder([]));

        $report = $this->makeService()->cleanupAfterGdprToggleOff($tenant);

        self::assertContains(101, $report['archived_documents']);
        self::assertContains(102, $report['archived_documents']);
        self::assertTrue($doc1->isArchived(), 'doc1 must be archived');
        self::assertSame('archived', $doc1->getStatus());
        self::assertTrue($doc2->isArchived());
    }

    #[Test]
    public function testGdprSectionsLocked(): void
    {
        $tenant = $this->makeTenant();
        $section1 = $this->makeSection(201, false);
        $section2 = $this->makeSection(202, false, 'pre-existing snapshot');

        $this->documentRepo->method('createQueryBuilder')
            ->willReturn($this->stubQueryBuilder([]));
        $this->sectionRepo->method('createQueryBuilder')
            ->willReturn($this->stubQueryBuilder([$section1, $section2]));

        $report = $this->makeService()->cleanupAfterGdprToggleOff($tenant);

        self::assertContains(201, $report['locked_sections']);
        self::assertContains(202, $report['locked_sections']);
        self::assertTrue($section1->isEditLocked());
        self::assertTrue($section2->isEditLocked());
        // Cleanup notice appended:
        self::assertStringContainsString('[GDPR-cleanup]', $section1->getContentSnapshot() ?? '');
        self::assertStringContainsString('[GDPR-cleanup]', $section2->getContentSnapshot() ?? '');
        // Pre-existing content preserved:
        self::assertStringContainsString('pre-existing snapshot', $section2->getContentSnapshot() ?? '');
    }

    #[Test]
    public function testCleanupIdempotent(): void
    {
        $tenant = $this->makeTenant();
        // Already archived doc + already locked section with marker.
        $alreadyArchivedDoc = $this->makeDocument(301, 'archived', true);
        $alreadyLockedSection = $this->makeSection(
            401,
            true,
            'body[GDPR-cleanup] already cleaned up earlier',
        );

        $this->documentRepo->method('createQueryBuilder')
            ->willReturn($this->stubQueryBuilder([$alreadyArchivedDoc]));
        $this->sectionRepo->method('createQueryBuilder')
            ->willReturn($this->stubQueryBuilder([$alreadyLockedSection]));

        $report = $this->makeService()->cleanupAfterGdprToggleOff($tenant);

        self::assertSame([], $report['archived_documents']);
        self::assertContains(301, $report['already_archived_documents']);
        self::assertSame([], $report['locked_sections']);
        self::assertContains(401, $report['already_locked_sections']);
    }

    #[Test]
    public function testAuditEventEmitted(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument(501);

        $this->documentRepo->method('createQueryBuilder')
            ->willReturn($this->stubQueryBuilder([$doc]));
        $this->sectionRepo->method('createQueryBuilder')
            ->willReturn($this->stubQueryBuilder([]));

        $captured = [];
        $this->auditLogger->method('logCustom')->willReturnCallback(
            function (string $action, ?string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues, ?string $description) use (&$captured): void {
                $captured[] = [
                    'action' => $action,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'newValues' => $newValues,
                ];
            },
        );

        $this->makeService()->cleanupAfterGdprToggleOff($tenant);

        $events = array_filter(
            $captured,
            static fn (array $e): bool => $e['action'] === GdprToggleOffCleanupService::AUDIT_ACTION,
        );
        self::assertCount(1, $events, 'gdpr_toggled_off_cleanup audit event must fire exactly once');

        $event = array_values($events)[0];
        self::assertSame('Tenant', $event['entityType']);
        self::assertSame(7, $event['entityId']);
        self::assertNotNull($event['newValues']['inventory'] ?? null);
        self::assertContains(501, $event['newValues']['inventory']['archived_documents']);
    }
}
