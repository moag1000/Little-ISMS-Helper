<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Service\Upload\ProtectedUploads;
use App\Entity\ComplianceRequirement;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Enum\DocumentStatus;
use App\Repository\WorkflowInstanceRepository;
use App\Service\Compliance\Fulfillment\RequirementEvidence;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Evidence documents as exports hand them to an auditor: which documents
 * stand behind a requirement (the organisation's own, from the requirement,
 * its fulfilment and the controls the rule reads — {@see RequirementEvidence}),
 * who approved each one and its SHA-256. Shared by the audit package and the
 * certification bundle so both list the same evidence.
 */
final class EvidenceDocuments
{
    public function __construct(
        private readonly RequirementEvidence $requirementEvidence,
        private readonly ?WorkflowInstanceRepository $workflowInstanceRepository = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ProtectedUploads $uploads = null,
    ) {
    }

    /** The readable file of an uploaded document (var/uploads, not yet moved, bulk import), or null. */
    public function file(Document $doc): ?string
    {
        if ($this->uploads !== null) {
            return $this->uploads->resolve($doc->getFilePath());
        }
        $path = (string) $doc->getFilePath();

        return $path !== '' && is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * The organisation's current evidence for a requirement (archived documents left out).
     *
     * @return list<Document>
     */
    public function forRequirement(ComplianceRequirement $requirement, Tenant $tenant): array
    {
        return array_values(array_filter(
            $this->requirementEvidence->documentsFor($requirement, $tenant),
            static fn (Document $doc): bool => !$doc->isArchived() && $doc->getStatus() !== DocumentStatus::Archived->value,
        ));
    }

    /**
     * Resolve approver identity for a document.
     *
     * Priority chain:
     *   1) WorkflowInstance with entityType='Document' + status='approved'
     *      — most recent completedAt wins. Source of truth for documents
     *      that ran through a formal approval workflow.
     *   2) policyBodyEditedBy / policyBodyEditedAt — wizard docs that
     *      were customised + saved with status='approved' fall here.
     *   3) uploadedBy / uploadedAt — fallback for legacy upload-only flow.
     *
     * Empty fields ('') when we cannot establish identity, so auditors
     * see explicit gaps instead of silent fallbacks.
     *
     * @return array{user_email: string, user_id: ?int, approved_at: string}
     */
    public function approver(Document $doc): array
    {
        $documentId = $doc->getId();

        // 1) Workflow approval — highest evidentiary weight.
        if ($this->workflowInstanceRepository !== null && $documentId !== null) {
            try {
                $instances = $this->workflowInstanceRepository->findByEntity('Document', $documentId);
                $best = null;
                foreach ($instances as $instance) {
                    if ($instance->getStatus() !== 'approved') {
                        continue;
                    }
                    if ($best === null || ($instance->getCompletedAt() !== null
                        && $best->getCompletedAt() !== null
                        && $instance->getCompletedAt() > $best->getCompletedAt())
                    ) {
                        $best = $instance;
                    }
                }
                if ($best !== null) {
                    $approver = $best->getInitiatedBy();
                    return [
                        'user_email' => $approver?->getEmail() ?? '',
                        'user_id' => $approver?->getId(),
                        'approved_at' => $best->getCompletedAt()?->format(DateTimeInterface::ATOM) ?? '',
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger?->debug(
                    'Evidence: workflow lookup failed; falling through.',
                    ['document_id' => $documentId, 'error' => $e->getMessage()],
                );
            }
        }

        // 2) Wizard-policy edit signal — locked + edited == approved-by-editor.
        if ($doc->getStatus() === DocumentStatus::Approved->value) {
            $editor = $doc->getPolicyBodyEditedBy();
            $editedAt = $doc->getPolicyBodyEditedAt();
            if ($editor !== null && $editedAt !== null) {
                return [
                    'user_email' => $editor->getEmail() ?? '',
                    'user_id' => $editor->getId(),
                    'approved_at' => $editedAt->format(DateTimeInterface::ATOM),
                ];
            }
        }

        // 3) Upload fallback — only treat as "approved by uploader" when
        // status actually says approved. Otherwise we leave it blank so
        // the auditor sees the gap rather than a misleading identity.
        if ($doc->getStatus() === DocumentStatus::Approved->value) {
            $uploader = $doc->getUploadedBy();
            $uploadedAt = $doc->getUploadedAt();
            if ($uploader !== null) {
                return [
                    'user_email' => $uploader->getEmail() ?? '',
                    'user_id' => $uploader->getId(),
                    'approved_at' => $uploadedAt instanceof DateTimeInterface
                        ? $uploadedAt->format(DateTimeInterface::ATOM)
                        : '',
                ];
            }
        }

        return ['user_email' => '', 'user_id' => null, 'approved_at' => ''];
    }

    /**
     * Compute SHA-256 of the document body. Priority:
     *   1) Rendered binary (wizard PDF render path) — always exact.
     *   2) Existing `sha256Hash` column — set on upload by file-upload service.
     *   3) Hash the file on disk if readable.
     *   4) Hash the persisted policy body (wizard fallback when the
     *      column is blank and there is no on-disk file).
     *   5) '' — explicit empty so auditors can grep for gaps.
     */
    public function sha256(Document $doc, ?string $renderedBinary = null): string
    {
        if ($renderedBinary !== null && $renderedBinary !== '') {
            return hash('sha256', $renderedBinary);
        }

        $stored = (string) ($doc->getSha256Hash() ?? '');
        if ($stored !== '') {
            return $stored;
        }

        $filePath = $this->file($doc);
        if ($filePath !== null) {
            $hash = hash_file('sha256', $filePath);
            if (is_string($hash)) {
                return $hash;
            }
        }

        $body = (string) ($doc->getPolicyBody() ?? '');
        if ($body !== '') {
            return hash('sha256', $body);
        }

        return '';
    }
}
