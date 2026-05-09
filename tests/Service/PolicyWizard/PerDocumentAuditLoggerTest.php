<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\PerDocumentAuditLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PerDocumentAuditLogger — Phase 4-C / W3-C.
 *
 * Closes Phase 4-C ISB-review item §7-#1: every bulk-approval batch
 * MUST emit one batch row + N per-document rows, all correlated via
 * a shared `batch_id`.
 */
#[AllowMockObjectsWithoutExpectations]
class PerDocumentAuditLoggerTest extends TestCase
{
    private AuditLogger $auditLogger;
    private PerDocumentAuditLogger $service;

    /** @var array<int, array{action: string, entityType: string, newValues: ?array<string, mixed>}> */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->captured = [];
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->auditLogger->method('logCustom')->willReturnCallback(
            function (
                string $action,
                string $entityType,
                ?int $entityId = null,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?string $description = null,
                ?string $userName = null,
            ): void {
                $this->captured[] = [
                    'action'     => $action,
                    'entityType' => $entityType,
                    'newValues'  => $newValues,
                ];
            },
        );

        $this->service = new PerDocumentAuditLogger($this->auditLogger);
    }

    private function makeDocument(int $id): Document
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn($id);
        return $doc;
    }

    private function makeUser(int $id = 7): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    private function makeRun(int $id = 11): WizardRun
    {
        $run = $this->createMock(WizardRun::class);
        $run->method('getId')->willReturn($id);
        return $run;
    }

    #[Test]
    public function bulkApprovalEmitsBatchPlusPerDocEntries(): void
    {
        $approver = $this->makeUser();
        $run = $this->makeRun();
        $documents = [
            $this->makeDocument(101),
            $this->makeDocument(102),
            $this->makeDocument(103),
        ];

        $this->service->logBulkApproval(
            $run,
            $documents,
            $approver,
            'Quarterly policy review — risk register cross-checked.',
        );

        // 1 batch row + 3 per-doc rows = 4 calls total.
        self::assertCount(4, $this->captured);
        self::assertSame('bulk_approval', $this->captured[0]['action']);
        self::assertSame('WizardRun', $this->captured[0]['entityType']);

        // All per-doc rows reference the same batch id and entity-type Document.
        $batchId = $this->captured[0]['newValues']['batch_id'] ?? null;
        self::assertIsString($batchId);
        self::assertNotEmpty($batchId);

        for ($i = 1; $i <= 3; $i++) {
            self::assertSame('approved', $this->captured[$i]['action']);
            self::assertSame('Document', $this->captured[$i]['entityType']);
            self::assertSame($batchId, $this->captured[$i]['newValues']['batch_id'] ?? null);
        }
    }

    #[Test]
    public function batchIdShapeIsUuidV4(): void
    {
        $approver = $this->makeUser();
        $run = $this->makeRun();
        $documents = [$this->makeDocument(201)];

        $this->service->logBulkApproval(
            $run,
            $documents,
            $approver,
            'Single-doc bulk batch (still emits a batch row).',
        );

        $batchId = $this->captured[0]['newValues']['batch_id'] ?? '';
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $batchId,
            'batch_id must be a UUIDv4-shaped string.',
        );
    }

    #[Test]
    public function perDocApprovalWithoutBatchOmitsBatchId(): void
    {
        $approver = $this->makeUser();
        $document = $this->makeDocument(301);

        $this->service->logPerDocApproval($document, $approver);

        self::assertCount(1, $this->captured);
        self::assertSame('approved', $this->captured[0]['action']);
        self::assertArrayNotHasKey('batch_id', $this->captured[0]['newValues'] ?? []);
    }

    #[Test]
    public function perDocApprovalWithBatchIdIncludesIt(): void
    {
        $approver = $this->makeUser();
        $document = $this->makeDocument(401);

        $this->service->logPerDocApproval($document, $approver, 'abc-123', 'Reuse rationale.');

        self::assertCount(1, $this->captured);
        self::assertSame('abc-123', $this->captured[0]['newValues']['batch_id'] ?? null);
        self::assertSame('Reuse rationale.', $this->captured[0]['newValues']['rationale'] ?? null);
    }
}
