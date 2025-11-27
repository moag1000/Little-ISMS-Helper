<?php

namespace App\Tests\Service;

use App\Entity\Document;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\UserRepository;
use App\Service\DocumentApprovalService;
use App\Service\WorkflowService;
use App\Service\EmailNotificationService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Test suite for DocumentApprovalService
 *
 * Tests the approval workflow logic for documents based on category.
 */
class DocumentApprovalServiceTest extends TestCase
{
    private DocumentApprovalService $service;
    private EntityManagerInterface&MockObject $entityManager;
    private WorkflowService&MockObject $workflowService;
    private EmailNotificationService&MockObject $emailService;
    private UserRepository&MockObject $userRepository;
    private AuditLogger&MockObject $auditLogger;
    private LoggerInterface&MockObject $logger;
    private UrlGeneratorInterface&MockObject $urlGenerator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->workflowService = $this->createMock(WorkflowService::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $this->service = new DocumentApprovalService(
            $this->entityManager,
            $this->workflowService,
            $this->emailService,
            $this->userRepository,
            $this->auditLogger,
            $this->logger,
            $this->urlGenerator
        );
    }

    /**
     * Test policy document approval (3-level: Owner → CISO → Management)
     */
    public function testRequestApprovalPolicy(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com', ['ROLE_USER']);
        $document = $this->createDocument('policy', 'Security Policy.pdf', $owner);
        $ciso = $this->createUser('ciso@test.com', ['ROLE_CISO']);
        $management = $this->createUser('ceo@test.com', ['ROLE_MANAGEMENT']);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(1);

        $this->userRepository->method('findByRole')
            ->willReturnMap([
                ['ROLE_CISO', [$ciso]],
                ['ROLE_MANAGEMENT', [$management]],
            ]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn(null);

        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willReturn($this->createMock(\App\Entity\Workflow::class));

        $this->workflowService->method('startWorkflow')
            ->willReturn($workflowInstance);

        $this->urlGenerator->method('generate')
            ->willReturn('http://test.com/document/1');

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertSame('policy', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
        $this->assertSame(1, $result['workflow_id']);
        $this->assertSame(3, $result['approvers_count']); // Owner + CISO + Management
        $this->assertSame(120, $result['sla_hours']); // 5 days SLA
    }

    /**
     * Test procedure document approval (2-level: Owner → CISO)
     */
    public function testRequestApprovalProcedure(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com', ['ROLE_USER']);
        $document = $this->createDocument('procedure', 'Backup Procedure.pdf', $owner);
        $ciso = $this->createUser('ciso@test.com', ['ROLE_CISO']);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(2);

        $this->userRepository->method('findByRole')
            ->willReturnMap([
                ['ROLE_CISO', [$ciso]],
            ]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn(null);

        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willReturn($this->createMock(\App\Entity\Workflow::class));

        $this->workflowService->method('startWorkflow')
            ->willReturn($workflowInstance);

        $this->urlGenerator->method('generate')
            ->willReturn('http://test.com/document/2');

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertSame('procedure', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
        $this->assertSame(2, $result['approvers_count']); // Owner + CISO
        $this->assertSame(72, $result['sla_hours']); // 3 days SLA
    }

    /**
     * Test guideline document approval (1-level: Owner or CISO)
     */
    public function testRequestApprovalGuideline(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com', ['ROLE_USER']);
        $document = $this->createDocument('guideline', 'Email Guideline.pdf', $owner);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(3);

        $this->userRepository->method('findByRole')
            ->willReturn([]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn(null);

        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willReturn($this->createMock(\App\Entity\Workflow::class));

        $this->workflowService->method('startWorkflow')
            ->willReturn($workflowInstance);

        $this->urlGenerator->method('generate')
            ->willReturn('http://test.com/document/3');

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertSame('guideline', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
        $this->assertSame(1, $result['approvers_count']); // Owner only
        $this->assertSame(48, $result['sla_hours']); // 2 days SLA
    }

    /**
     * Test non-approval category (report, certificate, etc.)
     */
    public function testRequestApprovalNotRequired(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com', ['ROLE_USER']);
        $document = $this->createDocument('report', 'Audit Report.pdf', $owner);

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertSame('none', $result['approval_level']);
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('approval_not_required', $result['reason']);
    }

    /**
     * Test document revision (not new document)
     */
    public function testRequestApprovalRevision(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com', ['ROLE_USER']);
        $document = $this->createDocument('policy', 'Security Policy v2.pdf', $owner);
        $ciso = $this->createUser('ciso@test.com', ['ROLE_CISO']);
        $management = $this->createUser('ceo@test.com', ['ROLE_MANAGEMENT']);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(4);

        $this->userRepository->method('findByRole')
            ->willReturnMap([
                ['ROLE_CISO', [$ciso]],
                ['ROLE_MANAGEMENT', [$management]],
            ]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn(null);

        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willReturn($this->createMock(\App\Entity\Workflow::class));

        $this->workflowService->method('startWorkflow')
            ->willReturn($workflowInstance);

        $this->urlGenerator->method('generate')
            ->willReturn('http://test.com/document/4');

        // Act
        $result = $this->service->requestApproval($document, false); // isNewDocument = false

        // Assert
        $this->assertTrue($result['workflow_started']);
        $this->assertSame('policy', $result['approval_level']);
    }

    /**
     * Test approval request when no approvers found
     */
    public function testRequestApprovalNoApproversFound(): void
    {
        // Arrange
        $document = $this->createDocument('policy', 'Policy.pdf', null); // No owner

        $this->userRepository->method('findByRole')
            ->willReturn([]); // No users found

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('no_approvers_found', $result['reason']);
    }

    /**
     * Test approval request when workflow already active
     */
    public function testRequestApprovalWorkflowAlreadyActive(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com', ['ROLE_USER']);
        $document = $this->createDocument('policy', 'Policy.pdf', $owner);
        $ciso = $this->createUser('ciso@test.com', ['ROLE_CISO']);
        $existingWorkflow = $this->createMock(WorkflowInstance::class);
        $existingWorkflow->method('getId')->willReturn(99);

        $this->userRepository->method('findByRole')
            ->willReturn([$ciso]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn($existingWorkflow);

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('workflow_already_active', $result['reason']);
        $this->assertSame(99, $result['workflow_id']);
    }

    /**
     * Test approval request handles exception gracefully
     */
    public function testRequestApprovalHandlesException(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com', ['ROLE_USER']);
        $document = $this->createDocument('policy', 'Policy.pdf', $owner);
        $ciso = $this->createUser('ciso@test.com', ['ROLE_CISO']);

        $this->userRepository->method('findByRole')
            ->willReturn([$ciso]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn(null);

        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willThrowException(new \Exception('Database error'));

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('workflow_creation_failed', $result['reason']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test all document categories
     */
    public function testAllDocumentCategories(): void
    {
        $owner = $this->createUser('owner@test.com', ['ROLE_USER']);
        $ciso = $this->createUser('ciso@test.com', ['ROLE_CISO']);
        $management = $this->createUser('ceo@test.com', ['ROLE_MANAGEMENT']);

        $this->userRepository->method('findByRole')
            ->willReturnMap([
                ['ROLE_CISO', [$ciso]],
                ['ROLE_MANAGEMENT', [$management]],
            ]);

        $this->workflowService->method('getActiveWorkflowForEntity')->willReturn(null);
        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willReturn($this->createMock(\App\Entity\Workflow::class));
        $this->workflowService->method('startWorkflow')
            ->willReturn($this->createMock(WorkflowInstance::class));
        $this->urlGenerator->method('generate')->willReturn('http://test.com/doc');

        $categories = [
            'policy' => ['policy', true, 120],
            'procedure' => ['procedure', true, 72],
            'guideline' => ['guideline', true, 48],
            'report' => ['none', false, null],
            'certificate' => ['none', false, null],
            'contract' => ['none', false, null],
        ];

        foreach ($categories as $category => [$expectedLevel, $shouldStart, $expectedSla]) {
            $document = $this->createDocument($category, ucfirst($category) . '.pdf', $owner);
            $result = $this->service->requestApproval($document, true);

            $this->assertSame($expectedLevel, $result['approval_level'], "Failed for category: $category");
            $this->assertSame($shouldStart, $result['workflow_started'], "Failed for category: $category");

            if ($expectedSla !== null) {
                $this->assertSame($expectedSla, $result['sla_hours'], "Failed SLA for category: $category");
            }
        }
    }

    /**
     * Helper: Create document mock
     */
    private function createDocument(string $category, string $filename, ?User $owner): Document
    {
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(rand(1, 1000));
        $document->method('getCategory')->willReturn($category);
        $document->method('getOriginalFilename')->willReturn($filename);
        $document->method('getUploadedBy')->willReturn($owner);

        return $document;
    }

    /**
     * Helper: Create user mock
     */
    private function createUser(string $email, array $roles): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);
        $user->method('getId')->willReturn(rand(1, 1000));
        $user->method('getRoles')->willReturnCallback(
            fn() => $roles
        );

        return $user;
    }
}
