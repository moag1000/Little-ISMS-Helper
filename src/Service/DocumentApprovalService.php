<?php

namespace App\Service;

use App\Entity\Document;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Document Approval Service
 *
 * ISO 27001:2022 Clause 5.2.3 (Information security policy) compliant
 * ISO 27001:2022 Clause 7.5 (Documented information) compliant
 *
 * Implements automatic approval workflow for critical documents:
 * - Policies: Multi-level approval (Document Owner → CISO → Management)
 * - Procedures: Two-level approval (Document Owner → CISO)
 * - Guidelines: Single approval (Document Owner or CISO)
 * - Other documents: No approval required (auto-approved)
 *
 * Features:
 * - Automatic approval routing based on document category
 * - Multi-level approval chain for policies
 * - Role-based notification to appropriate approvers
 * - Version control integration
 * - Audit trail for compliance
 *
 * Approval Levels:
 * - Level 1 (Guidelines): Document Owner or CISO
 * - Level 2 (Procedures): Document Owner + CISO
 * - Level 3 (Policies): Document Owner + CISO + Management
 */
class DocumentApprovalService
{
    // Document categories requiring approval
    private const CATEGORY_POLICY = 'policy';
    private const CATEGORY_PROCEDURE = 'procedure';
    private const CATEGORY_GUIDELINE = 'guideline';

    // Approval SLAs (hours)
    private const SLA_POLICY = 120;      // 5 days
    private const SLA_PROCEDURE = 72;    // 3 days
    private const SLA_GUIDELINE = 48;    // 2 days

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowService $workflowService,
        private readonly EmailNotificationService $emailService,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {}

    /**
     * Request approval for a document
     *
     * @param Document $document The document requiring approval
     * @param bool $isNewDocument Whether this is a new document or revision
     * @return array Approval status and workflow information
     */
    public function requestApproval(Document $document, bool $isNewDocument = true): array
    {
        $this->logger->info('Requesting approval for document', [
            'document_id' => $document->getId(),
            'category' => $document->getCategory(),
            'is_new' => $isNewDocument,
        ]);

        // Check if document category requires approval
        if (!$this->requiresApproval($document)) {
            $this->logger->info('Document category does not require approval', [
                'document_id' => $document->getId(),
                'category' => $document->getCategory(),
            ]);

            return [
                'approval_level' => 'none',
                'workflow_started' => false,
                'reason' => 'approval_not_required',
            ];
        }

        // Determine approval level based on category
        $approvalLevel = $this->determineApprovalLevel($document);
        $slaHours = $this->getSlaForApprovalLevel($approvalLevel);

        // Get approvers based on level
        $approvers = $this->getApproversForLevel($approvalLevel, $document);

        if (empty($approvers)) {
            $this->logger->warning('No approvers found for document', [
                'document_id' => $document->getId(),
                'approval_level' => $approvalLevel,
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'no_approvers_found',
                'sla_hours' => $slaHours,
            ];
        }

        // Check for existing active workflow to prevent duplicates
        $existingWorkflow = $this->workflowService->getActiveWorkflowForEntity($document);
        if ($existingWorkflow) {
            $this->logger->info('Active workflow already exists for document', [
                'document_id' => $document->getId(),
                'workflow_id' => $existingWorkflow->getId(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'workflow_already_active',
                'workflow_id' => $existingWorkflow->getId(),
            ];
        }

        // Create workflow instance
        try {
            $workflow = $this->createApprovalWorkflow($document, $approvalLevel, $approvers, $slaHours, $isNewDocument);

            // Send notifications
            $this->sendApprovalNotifications($document, $approvers, $approvalLevel, $isNewDocument);

            // Log audit event
            $this->auditLogger->log(
                'document_approval_requested',
                'Document',
                $document->getId(),
                [
                    'approval_level' => $approvalLevel,
                    'category' => $document->getCategory(),
                    'approvers' => array_map(fn($u) => $u->getEmail(), $approvers),
                    'is_new' => $isNewDocument,
                    'sla_hours' => $slaHours,
                ]
            );

            $this->logger->info('Document approval workflow created', [
                'document_id' => $document->getId(),
                'workflow_id' => $workflow->getId(),
                'approval_level' => $approvalLevel,
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => true,
                'workflow_id' => $workflow->getId(),
                'approvers_count' => count($approvers),
                'sla_hours' => $slaHours,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to create document approval workflow', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'workflow_creation_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if document requires approval based on category
     *
     * @param Document $document
     * @return bool
     */
    private function requiresApproval(Document $document): bool
    {
        return in_array($document->getCategory(), [
            self::CATEGORY_POLICY,
            self::CATEGORY_PROCEDURE,
            self::CATEGORY_GUIDELINE,
        ]);
    }

    /**
     * Determine approval level based on document category
     *
     * @param Document $document
     * @return string Approval level (policy, procedure, guideline)
     */
    private function determineApprovalLevel(Document $document): string
    {
        return match ($document->getCategory()) {
            self::CATEGORY_POLICY => 'policy',        // 3-level: Owner → CISO → Management
            self::CATEGORY_PROCEDURE => 'procedure',  // 2-level: Owner → CISO
            self::CATEGORY_GUIDELINE => 'guideline',  // 1-level: Owner or CISO
            default => 'none',
        };
    }

    /**
     * Get SLA hours for approval level
     *
     * @param string $approvalLevel
     * @return int SLA hours
     */
    private function getSlaForApprovalLevel(string $approvalLevel): int
    {
        return match ($approvalLevel) {
            'policy' => self::SLA_POLICY,
            'procedure' => self::SLA_PROCEDURE,
            'guideline' => self::SLA_GUIDELINE,
            default => 48,
        };
    }

    /**
     * Get approvers for approval level
     *
     * @param string $approvalLevel
     * @param Document $document
     * @return User[] Array of users who can approve
     */
    private function getApproversForLevel(string $approvalLevel, Document $document): array
    {
        $approvers = [];

        // Level 1: Document Owner (uploader) - always included for all levels
        $documentOwner = $document->getUploadedBy();
        if ($documentOwner) {
            $approvers[] = $documentOwner;
        }

        // Level 2+: CISO (for procedures and policies)
        if (in_array($approvalLevel, ['procedure', 'policy'])) {
            $cisos = $this->userRepository->findByRole('ROLE_CISO');
            $approvers = array_merge($approvers, $cisos);
        }

        // Level 3: Management (for policies only)
        if ($approvalLevel === 'policy') {
            $management = $this->userRepository->findByRole('ROLE_MANAGEMENT');
            $approvers = array_merge($approvers, $management);
        }

        // For guidelines, if no document owner or CISO found, use admins
        if ($approvalLevel === 'guideline' && empty($approvers)) {
            $cisos = $this->userRepository->findByRole('ROLE_CISO');
            if (!empty($cisos)) {
                $approvers = $cisos;
            }
        }

        // Fallback: If no specific role found, use admins
        if (empty($approvers)) {
            $this->logger->warning('No role-specific approvers found, falling back to admins', [
                'approval_level' => $approvalLevel,
                'document_id' => $document->getId(),
            ]);
            $approvers = $this->userRepository->findByRole('ROLE_ADMIN');
        }

        return array_unique($approvers, SORT_REGULAR);
    }

    /**
     * Create approval workflow instance
     *
     * @param Document $document
     * @param string $approvalLevel
     * @param User[] $approvers
     * @param int $slaHours
     * @param bool $isNewDocument
     * @return \App\Entity\WorkflowInstance
     */
    private function createApprovalWorkflow(
        Document $document,
        string $approvalLevel,
        array $approvers,
        int $slaHours,
        bool $isNewDocument
    ): \App\Entity\WorkflowInstance {
        // Find or create workflow definition
        $workflowType = $isNewDocument ? 'document_approval_new' : 'document_approval_revision';
        $workflowDefinition = $this->workflowService->findOrCreateWorkflowDefinition(
            $workflowType,
            ucfirst($approvalLevel) . ' Document Approval',
            sprintf('Approval workflow for %s documents (%s)', $approvalLevel, $isNewDocument ? 'new' : 'revision')
        );

        // Calculate deadline
        $deadline = new \DateTime();
        $deadline->modify(sprintf('+%d hours', $slaHours));

        // Create workflow instance
        $instance = $this->workflowService->startWorkflow(
            $workflowDefinition,
            $document,
            [
                'approval_level' => $approvalLevel,
                'category' => $document->getCategory(),
                'is_new_document' => $isNewDocument,
                'filename' => $document->getOriginalFilename(),
                'approvers' => array_map(fn($u) => $u->getId(), $approvers),
                'deadline' => $deadline->format('Y-m-d H:i:s'),
            ]
        );

        // Add approval steps based on level
        $this->addApprovalSteps($instance, $approvalLevel, $approvers, $document);

        return $instance;
    }

    /**
     * Add approval steps to workflow instance
     *
     * @param \App\Entity\WorkflowInstance $instance
     * @param string $approvalLevel
     * @param User[] $approvers
     * @param Document $document
     */
    private function addApprovalSteps(
        \App\Entity\WorkflowInstance $instance,
        string $approvalLevel,
        array $approvers,
        Document $document
    ): void {
        $stepOrder = 1;

        // Step 1: Document Owner review (for all levels)
        $documentOwner = $document->getUploadedBy();
        if ($documentOwner && in_array($documentOwner, $approvers)) {
            $this->workflowService->addWorkflowStep(
                $instance,
                'owner_review',
                'Document Owner Review',
                $documentOwner,
                $stepOrder++
            );
        }

        // Step 2: CISO approval (for procedures and policies)
        if (in_array($approvalLevel, ['procedure', 'policy'])) {
            $cisos = array_filter($approvers, fn($u) => in_array('ROLE_CISO', $u->getRoles()));
            if (!empty($cisos)) {
                $this->workflowService->addWorkflowStep(
                    $instance,
                    'ciso_approval',
                    'CISO Approval',
                    reset($cisos),
                    $stepOrder++
                );
            }
        }

        // Step 3: Management approval (for policies only)
        if ($approvalLevel === 'policy') {
            $management = array_filter($approvers, fn($u) => in_array('ROLE_MANAGEMENT', $u->getRoles()));
            if (!empty($management)) {
                $this->workflowService->addWorkflowStep(
                    $instance,
                    'management_approval',
                    'Management Approval',
                    reset($management),
                    $stepOrder++
                );
            }
        }

        // For guidelines with no owner, add CISO step
        if ($approvalLevel === 'guideline' && !$documentOwner) {
            $cisos = array_filter($approvers, fn($u) => in_array('ROLE_CISO', $u->getRoles()));
            if (!empty($cisos)) {
                $this->workflowService->addWorkflowStep(
                    $instance,
                    'ciso_review',
                    'CISO Review',
                    reset($cisos),
                    $stepOrder++
                );
            }
        }
    }

    /**
     * Send approval notifications to approvers
     *
     * @param Document $document
     * @param User[] $approvers
     * @param string $approvalLevel
     * @param bool $isNewDocument
     */
    private function sendApprovalNotifications(
        Document $document,
        array $approvers,
        string $approvalLevel,
        bool $isNewDocument
    ): void {
        $documentUrl = $this->urlGenerator->generate(
            'app_document_show',
            ['id' => $document->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        foreach ($approvers as $approver) {
            try {
                $this->emailService->sendEmail(
                    $approver->getEmail(),
                    sprintf('Document Approval Required: %s', $document->getOriginalFilename()),
                    'emails/document_approval_notification.html.twig',
                    [
                        'document' => $document,
                        'approver' => $approver,
                        'approval_level' => $approvalLevel,
                        'is_new_document' => $isNewDocument,
                        'document_url' => $documentUrl,
                    ]
                );

                $this->logger->info('Approval notification sent', [
                    'document_id' => $document->getId(),
                    'approver_email' => $approver->getEmail(),
                ]);

            } catch (\Exception $e) {
                $this->logger->error('Failed to send approval notification', [
                    'document_id' => $document->getId(),
                    'approver_email' => $approver->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
