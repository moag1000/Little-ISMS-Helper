<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentVersionRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * F4 Evidence-Versioning — versioning lifecycle service.
 *
 * Responsibilities:
 *  - Auto-version on re-upload: when a new file is uploaded for an existing
 *    Document, create a new DocumentVersion, mark the previous one replaced.
 *  - Hash-match-detection: if the uploaded file has the same SHA-256 as the
 *    current version, skip versioning (no-op, no new version created).
 *  - 5s-Undo-Buffer: the new DocumentVersion starts as a draft (publishedAt=null).
 *    A session key stores the new version ID. The undo endpoint reads this key
 *    and — if within 5 seconds — deletes the draft version and restores the
 *    previous one.
 *
 * Audit events: document.version.created (via AuditLogger::logCustom).
 */
class EvidenceVersioningService
{
    private const string SESSION_UNDO_KEY = 'evidence.undo.version_id';
    private const int UNDO_WINDOW_SECONDS = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentVersionRepository $documentVersionRepository,
        private readonly ContentHashCalculator $contentHashCalculator,
        private readonly RequestStack $requestStack,
        private readonly AuditLogger $auditLogger,
        private readonly string $projectDir,
    ) {}

    /**
     * Create a new DocumentVersion for the given document from an uploaded file.
     *
     * If the uploaded file produces the same SHA-256 as the current version,
     * returns the existing DocumentVersion (no new version) and a flag indicating
     * the upload was a no-op due to hash match.
     *
     * @return array{version: DocumentVersion, is_duplicate: bool}
     */
    public function createVersion(
        Document $document,
        UploadedFile $uploadedFile,
        string $storedFilePath,
        string $storedFileName,
        ?User $uploadedBy = null,
    ): array {
        $contentHash = $this->contentHashCalculator->calculateFromPath(
            $uploadedFile->getPathname(),
        );

        // Hash-match detection — same content, skip versioning
        $existing = $this->documentVersionRepository->findByDocumentAndHash($document, $contentHash);
        if ($existing !== null) {
            return ['version' => $existing, 'is_duplicate' => true];
        }

        $tenant = $document->getTenant();
        if ($tenant === null) {
            throw new RuntimeException('EvidenceVersioningService: document has no tenant.');
        }

        // Deactivate previous active versions
        $previousVersion = $document->getCurrentVersion();
        $nextNumber = $this->resolveNextVersionNumber($document);

        $version = new DocumentVersion();
        $version->setTenant($tenant);
        $version->setDocument($document);
        $version->setVersionNumber($nextNumber);
        $version->setContentHash($contentHash);
        $version->setFileName($storedFileName);
        $version->setFilePath($storedFilePath);
        $version->setFileSize((int) $uploadedFile->getSize());
        $version->setMimeType($uploadedFile->getMimeType() ?? 'application/octet-stream');
        $version->setUploadedBy($uploadedBy);
        $version->setIsActive(true);

        // Mark previous version as replaced
        if ($previousVersion !== null) {
            $previousVersion->setIsActive(false);
        }

        // Update document-level hash and current version
        $document->setContentHash($contentHash);
        $document->setCurrentVersion($version);

        $this->entityManager->persist($version);

        // Store undo info in session (5s window)
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_UNDO_KEY, [
            'version_id' => null, // Set after flush gives us the ID
            'document_id' => $document->getId(),
            'previous_version_id' => $previousVersion?->getId(),
            'created_at' => (new DateTimeImmutable())->getTimestamp(),
        ]);

        $this->entityManager->flush();

        // Now update session with actual ID
        $session->set(self::SESSION_UNDO_KEY, [
            'version_id' => $version->getId(),
            'document_id' => $document->getId(),
            'previous_version_id' => $previousVersion?->getId(),
            'created_at' => (new DateTimeImmutable())->getTimestamp(),
        ]);

        // Audit event
        $this->auditLogger->logCustom(
            action: 'create',
            entityType: 'document.version',
            entityId: $version->getId(),
            newValues: [
                'document_id' => $document->getId(),
                'version_number' => $nextNumber,
                'content_hash' => $contentHash,
                'file_name' => $storedFileName,
                'file_size' => $version->getFileSize(),
            ],
            description: sprintf(
                'document.version.created: doc#%d v%d (hash=%s)',
                $document->getId() ?? 0,
                $nextNumber,
                substr($contentHash, 0, 8) . '…',
            ),
        );

        return ['version' => $version, 'is_duplicate' => false];
    }

    /**
     * Publish the version (end of 5s undo window).
     * Sets publishedAt on the version, making it immutable.
     */
    public function publishVersion(DocumentVersion $version): void
    {
        if ($version->isPublished()) {
            return;
        }
        $version->setPublishedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        // Clear undo session key
        $this->requestStack->getSession()->remove(self::SESSION_UNDO_KEY);
    }

    /**
     * Attempt to undo the last version upload within the 5s window.
     *
     * Returns true when the undo succeeded, false when the window has passed
     * or no pending undo state exists.
     */
    public function undo(int $versionId): bool
    {
        $session = $this->requestStack->getSession();
        $undoData = $session->get(self::SESSION_UNDO_KEY);

        if (!is_array($undoData)
            || ($undoData['version_id'] ?? null) !== $versionId
        ) {
            return false;
        }

        $elapsed = (new DateTimeImmutable())->getTimestamp() - (int) $undoData['created_at'];
        if ($elapsed > self::UNDO_WINDOW_SECONDS) {
            $session->remove(self::SESSION_UNDO_KEY);
            return false;
        }

        $version = $this->documentVersionRepository->find($versionId);
        if ($version === null || $version->isPublished()) {
            $session->remove(self::SESSION_UNDO_KEY);
            return false;
        }

        $document = $version->getDocument();
        if ($document === null) {
            $session->remove(self::SESSION_UNDO_KEY);
            return false;
        }

        // Restore previous version as current
        $previousVersionId = $undoData['previous_version_id'] ?? null;
        $previousVersion = $previousVersionId !== null
            ? $this->documentVersionRepository->find($previousVersionId)
            : null;

        if ($previousVersion !== null) {
            $previousVersion->setIsActive(true);
            $document->setCurrentVersion($previousVersion);
            $document->setContentHash($previousVersion->getContentHash());
        } else {
            $document->setCurrentVersion(null);
            $document->setContentHash(null);
        }

        $this->entityManager->remove($version);
        $this->entityManager->flush();

        $session->remove(self::SESSION_UNDO_KEY);
        return true;
    }

    /**
     * True when an undo is possible for the given version (within window, not published).
     */
    public function canUndo(int $versionId): bool
    {
        $session = $this->requestStack->getSession();
        $undoData = $session->get(self::SESSION_UNDO_KEY);

        if (!is_array($undoData) || ($undoData['version_id'] ?? null) !== $versionId) {
            return false;
        }

        $elapsed = (new DateTimeImmutable())->getTimestamp() - (int) $undoData['created_at'];
        return $elapsed <= self::UNDO_WINDOW_SECONDS;
    }

    /**
     * Resolve next version number (max existing + 1, or 1 if no versions yet).
     */
    private function resolveNextVersionNumber(Document $document): int
    {
        $latest = $this->documentVersionRepository->findLatestForDocument($document);
        return $latest !== null ? $latest->getVersionNumber() + 1 : 1;
    }
}
