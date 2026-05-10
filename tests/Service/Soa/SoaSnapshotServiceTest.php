<?php

declare(strict_types=1);

namespace App\Tests\Service\Soa;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\SoaSnapshot;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\SoaSnapshotRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\AuditLogger;
use App\Service\Soa\SoaSnapshotService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SoaSnapshotServiceTest extends TestCase
{
    private ControlRepository&MockObject $controlRepository;
    private DocumentRepository&MockObject $documentRepository;
    private SoaSnapshotRepository&MockObject $snapshotRepository;
    private WorkflowInstanceRepository&MockObject $workflowInstanceRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private AuditLogger&MockObject $auditLogger;
    private Tenant $tenant;
    private User $user;
    private SoaSnapshotService $service;

    protected function setUp(): void
    {
        $this->controlRepository           = $this->createMock(ControlRepository::class);
        $this->documentRepository          = $this->createMock(DocumentRepository::class);
        $this->snapshotRepository          = $this->createMock(SoaSnapshotRepository::class);
        $this->workflowInstanceRepository  = $this->createMock(WorkflowInstanceRepository::class);
        $this->entityManager               = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger                 = $this->createMock(AuditLogger::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Test Tenant');
        $this->tenant->setCode('test_tenant');

        $this->user = new User();
        $this->user->setEmail('isb@example.com');
        $this->user->setFirstName('I');
        $this->user->setLastName('SB');
        $this->user->setRoles(['ROLE_AUDITOR']);
        $this->user->setPassword('hashed');
        $this->user->setIsActive(true);
        $this->user->setTenant($this->tenant);

        $this->service = new SoaSnapshotService(
            $this->controlRepository,
            $this->documentRepository,
            $this->snapshotRepository,
            $this->workflowInstanceRepository,
            $this->entityManager,
            $this->auditLogger,
        );
    }

    #[Test]
    public function createSnapshotPersistsImmutableRecordWithChecksum(): void
    {
        $control = $this->makeControl('5.15', 'Access Control', 'A.5', 'implemented', true);

        $this->controlRepository->method('findByTenant')->willReturn([$control]);
        $this->documentRepository->method('findByTenant')->willReturn([]);
        $this->workflowInstanceRepository->method('findByEntity')->willReturn([]);

        $persisted = null;
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($e) use (&$persisted): bool {
                $persisted = $e;
                return $e instanceof SoaSnapshot;
            }));
        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                $this->equalTo('soa_snapshot_created'),
                $this->equalTo('SoaSnapshot'),
            );

        $asOf = new DateTimeImmutable('2026-06-15');
        $snapshot = $this->service->createSnapshot($this->tenant, $asOf, $this->user, 'Audit 2026-06-15', 'TÜV pre-cert');

        self::assertSame($snapshot, $persisted);
        self::assertSame('2026-06-15', $snapshot->getAsOfDate()->format('Y-m-d'));
        self::assertSame('Audit 2026-06-15', $snapshot->getPurpose());
        self::assertSame('TÜV pre-cert', $snapshot->getNotes());
        self::assertSame(1, $snapshot->getControlCount());
        self::assertSame($this->user, $snapshot->getCreatedBy());
        self::assertSame(64, strlen($snapshot->getChecksumSha256()), 'SHA-256 hex digest must be 64 chars');
        $payload = $snapshot->getPayload();
        self::assertSame('Test Tenant', $payload['tenant_name']);
        self::assertArrayHasKey('5.15', $payload['controls']);
        self::assertSame('implemented', $payload['controls']['5.15']['status']);
    }

    #[Test]
    public function asOfDateResolvesEvidenceVersionViaSupersedesChain(): void
    {
        $control = $this->makeControl('5.15', 'Access Control', 'A.5', 'implemented', true);
        $template = $this->makePolicyTemplate(['A.5.15']);

        // v1 uploaded 2026-01-01, superseded by v2 on 2026-03-01.
        $v1 = $this->makeDocument(101, 'access-policy-v1.pdf', '2026-01-01', $template);
        $v2 = $this->makeDocument(102, 'access-policy-v2.pdf', '2026-03-01', $template);
        $v2->setSupersedes($v1);
        // v3 uploaded 2026-08-01, AFTER our 2026-06-15 cut-off — must be excluded.
        $v3 = $this->makeDocument(103, 'access-policy-v3.pdf', '2026-08-01', $template);
        $v3->setSupersedes($v2);

        $this->controlRepository->method('findByTenant')->willReturn([$control]);
        $this->documentRepository->method('findByTenant')->willReturn([$v1, $v2, $v3]);
        $this->workflowInstanceRepository->method('findByEntity')->willReturn([]);
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $snapshot = $this->service->createSnapshot(
            $this->tenant,
            new DateTimeImmutable('2026-06-15'),
            $this->user,
            null,
            null,
        );

        $controlState = $snapshot->getPayload()['controls']['5.15'];
        self::assertCount(1, $controlState['evidence_documents'], 'Expected exactly v2 to be current at cut-off');
        self::assertSame(102, $controlState['evidence_documents'][0]['document_id']);
        self::assertSame(2, $controlState['evidence_documents'][0]['version']);
        self::assertSame([101], $controlState['evidence_documents'][0]['supersedes_chain']);
    }

    #[Test]
    public function approvedByResolutionPicksLatestApprovalBeforeAsOfDate(): void
    {
        $control = $this->makeControl('5.15', 'Access Control', 'A.5', 'implemented', true);
        // Reflection to set id (real ORM assigns).
        $this->setEntityId($control, 42);

        $approverEarly = (new User())->setEmail('first@example.com')->setFirstName('F')->setLastName('A')
            ->setRoles(['ROLE_AUDITOR'])->setPassword('h')->setIsActive(true);
        $this->setEntityId($approverEarly, 1);
        $approverLate = (new User())->setEmail('latest@example.com')->setFirstName('L')->setLastName('A')
            ->setRoles(['ROLE_AUDITOR'])->setPassword('h')->setIsActive(true);
        $this->setEntityId($approverLate, 2);

        $earlyInstance = $this->makeApprovedWorkflowInstance(11, $approverEarly, '2026-02-15');
        $lateInstance  = $this->makeApprovedWorkflowInstance(12, $approverLate,  '2026-05-30');
        $futureInstance = $this->makeApprovedWorkflowInstance(13, $approverLate, '2026-07-01'); // after cut-off

        $this->controlRepository->method('findByTenant')->willReturn([$control]);
        $this->documentRepository->method('findByTenant')->willReturn([]);
        $this->workflowInstanceRepository
            ->method('findByEntity')
            ->willReturn([$earlyInstance, $lateInstance, $futureInstance]);
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $snapshot = $this->service->createSnapshot(
            $this->tenant,
            new DateTimeImmutable('2026-06-15'),
            $this->user,
            null,
            null,
        );

        $state = $snapshot->getPayload()['controls']['5.15'];
        self::assertSame('latest@example.com', $state['approved_by_email']);
        self::assertSame(2, $state['approved_by_user_id']);
        self::assertSame(12, $state['approval_workflow_instance_id']);
    }

    #[Test]
    public function checksumIsDeterministicAcrossRuns(): void
    {
        $control = $this->makeControl('5.15', 'Access Control', 'A.5', 'implemented', true);

        $this->controlRepository->method('findByTenant')->willReturn([$control]);
        $this->documentRepository->method('findByTenant')->willReturn([]);
        $this->workflowInstanceRepository->method('findByEntity')->willReturn([]);
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $asOf = new DateTimeImmutable('2026-06-15');
        $a = $this->service->createSnapshot($this->tenant, $asOf, $this->user, 'p', 'n');
        $b = $this->service->createSnapshot($this->tenant, $asOf, $this->user, 'p', 'n');

        self::assertSame($a->getChecksumSha256(), $b->getChecksumSha256(), 'Same inputs must yield same SHA-256');
        self::assertSame(64, strlen($a->getChecksumSha256()));
    }

    #[Test]
    public function exportPayloadCsvEmitsHeaderAndRowsWithBom(): void
    {
        $snapshot = new SoaSnapshot();
        $snapshot->setTenant($this->tenant);
        $snapshot->setAsOfDate(new DateTimeImmutable('2026-06-15'));
        $snapshot->setPayload([
            'controls' => [
                '5.15' => [
                    'control_id'         => '5.15',
                    'name'               => 'Access Control',
                    'category'           => 'A.5',
                    'status'             => 'implemented',
                    'applicable'         => true,
                    'evidence_documents' => [
                        [
                            'document_id'      => 102,
                            'filename'         => 'policy.pdf',
                            'version'          => 2,
                            'supersedes_chain' => [101],
                            'sha256'           => 'abc',
                        ],
                    ],
                    'approved_by_email'   => 'ciso@example.com',
                    'approved_by_user_id' => 7,
                    'approved_at'         => '2026-05-30T10:00:00+00:00',
                ],
            ],
        ]);

        $csv = $this->service->exportPayloadCsv($snapshot);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'CSV must be UTF-8 BOM prefixed');
        self::assertStringContainsString('control_id', $csv);
        self::assertStringContainsString('5.15', $csv);
        self::assertStringContainsString('ciso@example.com', $csv);
        self::assertStringContainsString('101', $csv); // supersedes chain entry
    }

    // ─── Test fixtures ──────────────────────────────────────────────

    private function makeControl(string $id, string $name, string $category, string $status, bool $applicable): Control
    {
        $c = new Control();
        $c->setControlId($id);
        $c->setName($name);
        $c->setCategory($category);
        $c->setDescription('test');
        $c->setImplementationStatus($status);
        $c->setApplicable($applicable);
        $c->setTenant($this->tenant);
        return $c;
    }

    /**
     * @param list<string> $linkedAnnex
     */
    private function makePolicyTemplate(array $linkedAnnex): PolicyTemplate
    {
        $t = new PolicyTemplate();
        $t->setLinkedAnnexAControls($linkedAnnex);
        return $t;
    }

    private function makeDocument(int $id, string $filename, string $uploadedAt, PolicyTemplate $template): Document
    {
        $d = new Document();
        $d->setOriginalFilename($filename);
        $d->setFilename($filename);
        $d->setStatus('approved');
        $d->setGeneratedFromTemplate($template);
        // Use reflection to set immutable id + uploadedAt for test determinism.
        $this->setEntityId($d, $id);
        $ref = new \ReflectionProperty(Document::class, 'uploadedAt');
        $ref->setValue($d, new DateTimeImmutable($uploadedAt));
        return $d;
    }

    private function makeApprovedWorkflowInstance(int $id, User $approver, string $completedAt): WorkflowInstance
    {
        $wi = new WorkflowInstance();
        $wi->setEntityType('Control');
        $wi->setEntityId(42);
        $wi->setStatus('approved');
        $wi->setInitiatedBy($approver);
        $ref = new \ReflectionProperty(WorkflowInstance::class, 'completedAt');
        $ref->setValue($wi, new DateTimeImmutable($completedAt));
        $this->setEntityId($wi, $id);
        return $wi;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }
}
