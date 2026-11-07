<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\User;
use App\Service\Evidence\ContentHashCalculator;
use App\Service\Evidence\EvidenceCascadeInvalidationService;
use App\Service\Evidence\EvidenceVersioningService;
use App\Service\FileUploadSecurityService;
use App\Service\SecurityEventLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Replaces the file of an existing document from the edit form.
 *
 * The edit form always offered a file field ("leave empty to keep the existing
 * file"), but the edit action never read it — a chosen file was silently
 * dropped. A replacement now:
 *  1. passes the same upload checks as a new document (MIME, magic bytes, size),
 *  2. keeps the previous file as a DocumentVersion (the first replacement
 *     snapshots the original upload as version 1, so nothing is lost),
 *  3. creates + publishes the new version (no-op when the content hash is
 *     unchanged) and
 *  4. runs the evidence cascade: controls / requirement fulfilments that use
 *     this document as evidence get a re-verification task.
 */
class DocumentFileReplacer
{
    private const string UPLOAD_DIR = '/public/uploads/documents';

    public function __construct(
        private readonly FileUploadSecurityService $fileUploadSecurityService,
        private readonly EvidenceVersioningService $versioningService,
        private readonly EvidenceCascadeInvalidationService $cascadeService,
        private readonly ContentHashCalculator $contentHashCalculator,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecurityEventLogger $securityEventLogger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Controller entry point: replaces the file and returns null, or returns
     * the reason a rejected / failed upload was not applied (other form
     * fields are saved either way).
     */
    public function replaceOrError(Document $document, UploadedFile $file, ?User $user): ?string
    {
        try {
            if ($this->replace($document, $file, $user)) {
                $this->securityEventLogger->logFileUpload(
                    (string) $document->getFilename(),
                    (string) $document->getMimeType(),
                    (int) $document->getFileSize(),
                    true,
                );
            }

            return null;
        } catch (\Throwable $e) {
            $this->securityEventLogger->logFileUpload(
                $file->getClientOriginalName(),
                'unknown',
                0,
                false,
                $e->getMessage(),
            );

            return $e->getMessage();
        }
    }

    /**
     * @return bool false when the uploaded file is identical to the current one
     * @throws \Throwable on a rejected upload (FileUploadSecurityService) or a move failure
     */
    public function replace(Document $document, UploadedFile $file, ?User $user): bool
    {
        $this->fileUploadSecurityService->validateUploadedFile($file);

        if ($document->getCurrentVersion() === null) {
            $this->snapshotOriginalFile($document);
        }

        $safeFilename = $this->fileUploadSecurityService->generateSafeFilename($file);
        $originalFilename = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();
        $publicPath = '/uploads/documents/' . $safeFilename;

        // Hash is computed from the temp file, so versioning runs before the move.
        $result = $this->versioningService->createVersion($document, $file, $publicPath, $safeFilename, $user);
        if ($result['is_duplicate']) {
            return false;
        }

        $version = $result['version'];
        try {
            $file->move($this->projectDir . self::UPLOAD_DIR, $safeFilename);
        } catch (\Throwable $e) {
            $this->entityManager->remove($version);
            $this->entityManager->flush();
            throw $e;
        }

        $document->setFilename($safeFilename);
        $document->setOriginalFilename($originalFilename);
        $document->setMimeType($mimeType);
        $document->setFileSize($fileSize);
        $document->setFilePath($publicPath);
        $document->setUploadedBy($user);
        $this->versioningService->publishVersion($version);
        $this->cascadeService->invalidate($version, $user);

        return true;
    }

    /**
     * Records the file uploaded with the document (before any versioning
     * existed) as version 1, so the versions page keeps the original.
     */
    private function snapshotOriginalFile(Document $document): void
    {
        $path = $document->getFilePath();
        $tenant = $document->getTenant();
        if ($path === null || $path === '' || $tenant === null) {
            return;
        }
        $absolute = $this->projectDir . '/public' . $path;
        if (!is_file($absolute)) {
            return;
        }

        $hash = $this->contentHashCalculator->calculateFromPath($absolute);
        $version = new DocumentVersion();
        $version->setTenant($tenant);
        $version->setDocument($document);
        $version->setVersionNumber(1);
        $version->setContentHash($hash);
        $version->setFileName((string) ($document->getFilename() ?? basename($path)));
        $version->setFilePath($path);
        $version->setFileSize((int) ($document->getFileSize() ?? filesize($absolute)));
        $version->setMimeType((string) ($document->getMimeType() ?? 'application/octet-stream'));
        $version->setUploadedBy($document->getUploadedBy());
        $version->setIsActive(true);
        $version->setPublishedAt(new DateTimeImmutable());

        $document->setContentHash($hash);
        $document->setCurrentVersion($version);
        $this->entityManager->persist($version);
        $this->entityManager->flush();
    }
}
