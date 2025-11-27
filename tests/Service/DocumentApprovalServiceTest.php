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
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Test suite for DocumentApprovalService
 *
 * Tests the approval workflow triggering logic for documents.
 */
class DocumentApprovalServiceTest extends TestCase
{
    private DocumentApprovalService $service;
    private WorkflowService&MockObject $workflowService;
    private EmailNotificationService&MockObject $emailService;
    private UserRepository&MockObject $userRepository;
    private AuditLogger&MockObject $auditLogger;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->workflowService = $this->createMock(WorkflowService::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new DocumentApprovalService(
            $this->workflowService,
            $this->emailService,
            $this->userRepository,
            $this->auditLogger,
            $this->logger
        );
    }

    /**
     * Test policy document approval
     */
    public function testRequestApprovalPolicy(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com');
        $document = $this->createDocument('policy', 'Security Policy.pdf', $owner);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(1);
        $workflowInstance->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')
            ->with('Document', 1)
            ->willReturn(null);

        $this->workflowService->method('startWorkflow')
            ->with('Document', 1, 'document_approval')
            ->willReturn($workflowInstance);

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertSame('policy', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
        $this->assertSame(1, $result['workflow_id']);
    }

    /**
     * Test procedure document approval
     */
    public function testRequestApprovalProcedure(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com');
        $document = $this->createDocument('procedure', 'Backup Procedure.pdf', $owner);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(2);
        $workflowInstance->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')->willReturn($workflowInstance);

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertSame('procedure', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
    }

    /**
     * Test guideline document approval
     */
    public function testRequestApprovalGuideline(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com');
        $document = $this->createDocument('guideline', 'Email Guideline.pdf', $owner);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(3);
        $workflowInstance->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')->willReturn($workflowInstance);

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertSame('guideline', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
    }

    /**
     * Test non-approval category (report, certificate, etc.)
     */
    public function testRequestApprovalNotRequired(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com');
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
        $owner = $this->createUser('owner@test.com');
        $document = $this->createDocument('policy', 'Security Policy v2.pdf', $owner);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(4);
        $workflowInstance->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')->willReturn($workflowInstance);

        // Act
        $result = $this->service->requestApproval($document, false); // isNewDocument = false

        // Assert
        $this->assertTrue($result['workflow_started']);
        $this->assertSame('policy', $result['approval_level']);
    }

    /**
     * Test approval request when workflow already active
     */
    public function testRequestApprovalWorkflowAlreadyActive(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com');
        $document = $this->createDocument('policy', 'Policy.pdf', $owner);
        $existingWorkflow = $this->createMock(WorkflowInstance::class);
        $existingWorkflow->method('getId')->willReturn(99);
        $existingWorkflow->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($existingWorkflow);

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('workflow_already_active', $result['reason']);
        $this->assertSame(99, $result['workflow_id']);
    }

    /**
     * Test approval request when no workflow definition exists
     */
    public function testRequestApprovalNoWorkflowDefinition(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com');
        $document = $this->createDocument('policy', 'Policy.pdf', $owner);

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')->willReturn(null); // No definition found

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('no_workflow_definition', $result['reason']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test approval request handles exception gracefully
     */
    public function testRequestApprovalHandlesException(): void
    {
        // Arrange
        $owner = $this->createUser('owner@test.com');
        $document = $this->createDocument('policy', 'Policy.pdf', $owner);

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')
            ->willThrowException(new \Exception('Database error'));

        // Act
        $result = $this->service->requestApproval($document, true);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('workflow_start_failed', $result['reason']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test all document categories
     */
    public function testAllDocumentCategories(): void
    {
        $owner = $this->createUser('owner@test.com');
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')->willReturn($workflowInstance);

        $categories = [
            'policy' => ['policy', true],
            'procedure' => ['procedure', true],
            'guideline' => ['guideline', true],
            'report' => ['none', false],
            'certificate' => ['none', false],
            'contract' => ['none', false],
        ];

        foreach ($categories as $category => [$expectedLevel, $shouldStart]) {
            $document = $this->createDocument($category, ucfirst($category) . '.pdf', $owner);
            $result = $this->service->requestApproval($document, true);

            $this->assertSame($expectedLevel, $result['approval_level'], "Failed for category: $category");
            $this->assertSame($shouldStart, $result['workflow_started'], "Failed for category: $category");
        }
    }

    /**
     * Helper: Create document mock
     */
    private function createDocument(string $category, string $filename, ?User $owner): Document
    {
        static $idCounter = 1;

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn($idCounter++);
        $document->method('getCategory')->willReturn($category);
        $document->method('getOriginalFilename')->willReturn($filename);
        $document->method('getUploadedBy')->willReturn($owner);

        return $document;
    }

    /**
     * Helper: Create user mock
     */
    private function createUser(string $email): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);
        $user->method('getId')->willReturn(rand(1, 1000));

        return $user;
    }
}
