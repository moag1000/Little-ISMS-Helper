<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WorkflowInstance;
use Exception;
use App\Entity\WorkflowStep;
use App\Entity\User;
use App\Entity\Document;
use App\Enum\WorkflowInstanceStatus;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Document Approval Service
 *
 * ISO 27001:2022 Clause 5.2.3 (Information security policy) compliant
 * ISO 27001:2022 Clause 7.5 (Documented information) compliant
 *
 * Implements automatic approval workflow for critical documents based on category.
 *
 * Approval Levels (based on category):
 * - Policy: 3-level approval (Document Owner → CISO → Management, 120h SLA)
 * - Procedure: 2-level approval (Document Owner → CISO, 72h SLA)
 * - Guideline: 1-level approval (Document Owner or CISO, 48h SLA)
 * - Other categories: No approval required
 *
 * Prerequisites:
 * - Workflow definition must exist in database with entityType='Document'
 * - Workflow should be created via: php bin/console app:seed-workflow-definitions
 *
 * Usage:
 * - Automatically triggered by WorkflowAutoTriggerListener on Document postPersist
 * - Manual trigger: $service->requestApproval($document, $isNew)
 */
class DocumentApprovalService
{
    // Document categories requiring approval
    private const string CATEGORY_POLICY = 'policy';
    private const string CATEGORY_PROCEDURE = 'procedure';
    private const string CATEGORY_GUIDELINE = 'guideline';

    public function __construct(
        private readonly WorkflowService $workflowService,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
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
            'filename' => $document->getOriginalFilename(),
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

        // Check if workflow already exists for this document
        $existingWorkflow = $this->workflowService->getWorkflowInstance(
            'Document',
            $document->getId()
        );

        if ($existingWorkflow instanceof WorkflowInstance && in_array($existingWorkflow->getStatus(), [WorkflowInstanceStatus::Pending->value, WorkflowInstanceStatus::InProgress->value])) {
            $this->logger->info('Active workflow already exists for document', [
                'document_id' => $document->getId(),
                'workflow_id' => $existingWorkflow->getId(),
                'status' => $existingWorkflow->getStatus(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'workflow_already_active',
                'workflow_id' => $existingWorkflow->getId(),
            ];
        }

        // Start workflow using WorkflowService
        // This will look for a Workflow definition with entityType='Document'
        try {
            $workflowInstance = $this->workflowService->startWorkflow(
                'Document',
                $document->getId(),
                'document_approval' // Optional: specific workflow name
            );

            if (!$workflowInstance instanceof WorkflowInstance) {
                $this->logger->warning('No workflow definition found for Document', [
                    'document_id' => $document->getId(),
                ]);

                return [
                    'approval_level' => $approvalLevel,
                    'workflow_started' => false,
                    'reason' => 'no_workflow_definition',
                    'message' => 'Workflow definition for Document not found. Please run: php bin/console app:seed-workflow-definitions',
                ];
            }

            // Send notifications to approvers
            $this->sendApprovalNotifications($document, $workflowInstance, $approvalLevel, $isNewDocument);

            // Log audit event
            $this->auditLogger->logCustom(
                'document_approval_requested',
                'Document',
                $document->getId(),
                null, // oldValues
                [
                    'approval_level' => $approvalLevel,
                    'workflow_id' => $workflowInstance->getId(),
                    'category' => $document->getCategory(),
                    'is_new_document' => $isNewDocument,
                ], // newValues
                sprintf('Approval requested for %s document (level: %s, file: %s)',
                    $document->getCategory(),
                    $approvalLevel,
                    $document->getOriginalFilename()
                ) // description
            );

            $this->logger->info('Document approval workflow started', [
                'document_id' => $document->getId(),
                'workflow_id' => $workflowInstance->getId(),
                'approval_level' => $approvalLevel,
                'status' => $workflowInstance->getStatus(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => true,
                'workflow_id' => $workflowInstance->getId(),
                'status' => $workflowInstance->getStatus(),
            ];

        } catch (Exception $e) {
            $this->logger->error('Failed to start document approval workflow', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'workflow_start_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if document requires approval based on category
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
     * Send approval notifications to workflow approvers
     */
    private function sendApprovalNotifications(
        Document $document,
        WorkflowInstance $workflowInstance,
        string $approvalLevel,
        bool $isNewDocument
    ): void {
        // Get current step approver
        $currentStep = $workflowInstance->getCurrentStep();
        if (!$currentStep instanceof WorkflowStep) {
            return;
        }

        $assignedUsers = $currentStep->getApproverUsers();
        $assignedRole = $currentStep->getApproverRole();

        // Send to specific users if assigned
        if ($assignedUsers && is_array($assignedUsers)) {
            foreach ($assignedUsers as $userId) {
                $user = $this->userRepository->find($userId);
                if ($user) {
                    $this->sendNotificationToUser($document, $user, $approvalLevel, $isNewDocument, $workflowInstance);
                }
            }
        }

        // Send to all users with assigned role
        if ($assignedRole) {
            $roleUsers = $this->userRepository->findByRole($assignedRole);
            foreach ($roleUsers as $roleUser) {
                $this->sendNotificationToUser($document, $roleUser, $approvalLevel, $isNewDocument, $workflowInstance);
            }
        }
    }

    /**
     * Send notification email to a specific user.
     *
     * Persona-Walkthrough Risk-Owner-Business (Task #124, KRITISCH):
     * passes Approval-Screen + Reject + Clarify deep-links plus a 1-sentence
     * "what is it about?" heuristic, so the Business-Summary-Block in
     * `templates/emails/document_approval_notification.html.twig` can render
     * for non-ITSec approvers without ITSec-jargon.
     */
    private function sendNotificationToUser(
        Document $document,
        User $user,
        string $approvalLevel,
        bool $isNewDocument,
        ?WorkflowInstance $workflowInstance = null
    ): void {
        try {
            $context = [
                'document'         => $document,
                'user'             => $user,
                'approval_level'   => $approvalLevel,
                'is_new_document'  => $isNewDocument,
                'category'         => $document->getCategory(),
                'workflow_instance'=> $workflowInstance,
                'what_is_it'       => $this->extractWhatIsIt($document),
            ];

            // Generate deep-links to Approval-Screen — only when UrlGenerator is wired.
            if ($workflowInstance instanceof WorkflowInstance && $this->urlGenerator !== null) {
                try {
                    $approvalUrl = $this->urlGenerator->generate(
                        'app_workflow_instance_show',
                        ['id' => $workflowInstance->getId()],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    );
                    $context['approval_link'] = $approvalUrl;
                    $context['reject_link']   = $approvalUrl . '#rejectModal';
                    $context['clarify_link']  = $approvalUrl . '#clarifyModal';
                } catch (Exception $urlError) {
                    $this->logger->debug('Could not generate approval deep-links', [
                        'workflow_instance_id' => $workflowInstance->getId(),
                        'error'                => $urlError->getMessage(),
                    ]);
                }
            }

            $this->emailNotificationService->sendGenericNotification(
                sprintf('Document Approval Required: %s', $document->getOriginalFilename() ?? '(untitled)'),
                'emails/document_approval_notification.html.twig',
                $context,
                [$user],
            );

            $this->logger->info('Approval notification sent', [
                'document_id' => $document->getId(),
                'user_email' => $user->getEmail(),
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to send approval notification', [
                'document_id' => $document->getId(),
                'user_email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate a 1-sentence "what is this about?" summary for the
     * Business-Summary-Block in the approval-email.
     *
     * Heuristic priority (first non-empty wins):
     *   1. Document.description (if author wrote it)
     *   2. First sentence of Document.policyBody (Markdown body)
     *   3. NULL → email falls back to a generic translation key
     *
     * Output is hard-capped at 240 chars to keep the summary block scannable.
     */
    private function extractWhatIsIt(Document $document): ?string
    {
        $description = method_exists($document, 'getDescription') ? $document->getDescription() : null;
        if (is_string($description) && trim($description) !== '') {
            return $this->trimToSentence($description, 240);
        }

        $body = method_exists($document, 'getPolicyBody') ? $document->getPolicyBody() : null;
        if (is_string($body) && trim($body) !== '') {
            // Strip Markdown headings and lists, then take the first sentence.
            $plain = preg_replace('/^[#>\-*]\s*/m', '', $body) ?? $body;
            $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;
            return $this->trimToSentence(trim($plain), 240);
        }

        return null;
    }

    private function trimToSentence(string $text, int $maxLen): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        // Cut at first sentence end (., !, ?) within the first $maxLen chars.
        $slice = mb_substr($text, 0, $maxLen);
        if (preg_match('/^(.+?[\.!\?])(\s|$)/u', $slice, $m)) {
            return trim($m[1]);
        }
        // No sentence boundary — fall back to ellipsised cut.
        return mb_strlen($text) > $maxLen ? mb_substr($text, 0, $maxLen - 1) . '…' : $text;
    }
}
