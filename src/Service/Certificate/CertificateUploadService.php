<?php

declare(strict_types=1);

namespace App\Service\Certificate;

use App\Entity\ComplianceCertificate;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\FileUploadSecurityService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Creates a ComplianceCertificate record from a manually uploaded certificate
 * file (the manual path — OCR/AI extraction is handled elsewhere).
 *
 * The uploaded file is security-validated, stored under the documents upload
 * directory, wrapped in a {@see Document} entity (with sha256), and linked to a
 * freshly-created {@see ComplianceCertificate} populated from the form fields.
 * A single audit-log entry records the creation.
 *
 * The Document creation mirrors {@see \App\Service\EvidenceCollectionService}'s
 * upload pattern (safe filename → move → hash → Document metadata).
 */
final class CertificateUploadService
{
    public function __construct(
        private readonly FileUploadSecurityService $fileUploadSecurityService,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Create a ComplianceCertificate from an uploaded file + form fields.
     *
     * @param array{
     *     frameworkCode?: string,
     *     certBody?: string,
     *     certNumber?: ?string,
     *     scopeText?: ?string,
     *     scopeTags?: array<int|string, mixed>,
     *     certClass?: ?string,
     *     issueDate?: ?DateTimeImmutable,
     *     validUntil?: ?DateTimeImmutable,
     *     holder?: ?string
     * } $fields
     */
    public function createFromUpload(UploadedFile $file, array $fields, Tenant $tenant, User $user): ComplianceCertificate
    {
        // 1. Security validation (extension, mime, size, …) — throws on failure.
        $this->fileUploadSecurityService->validateUploadedFile($file);

        // 2. Persist the file under the documents upload dir.
        $document = $this->createDocument($file, $tenant, $user);

        // 3. Map form fields onto a new certificate record.
        $cert = new ComplianceCertificate();
        $cert->setTenant($tenant)
            ->setUploadedBy($user)
            ->setCertificateDocument($document)
            ->setExtractionSource('manual')
            ->setStatus('active')
            ->setFrameworkCode((string) ($fields['frameworkCode'] ?? ''))
            ->setCertBody((string) ($fields['certBody'] ?? ''))
            ->setCertNumber($this->nullableString($fields['certNumber'] ?? null))
            ->setScopeText($this->nullableString($fields['scopeText'] ?? null))
            ->setScopeTags(is_array($fields['scopeTags'] ?? null) ? $fields['scopeTags'] : [])
            ->setCertClass($this->nullableString($fields['certClass'] ?? null))
            ->setIssueDate($fields['issueDate'] ?? null)
            ->setValidUntil($fields['validUntil'] ?? null)
            ->setHolder($this->nullableString($fields['holder'] ?? null));

        $this->em->persist($cert);
        $this->em->flush();

        $this->auditLogger->log(
            'create',
            'ComplianceCertificate',
            $cert->getId(),
            null,
            [
                'framework_code' => $cert->getFrameworkCode(),
                'cert_body' => $cert->getCertBody(),
                'cert_number' => $cert->getCertNumber(),
                'extraction_source' => 'manual',
            ],
            'Certificate uploaded manually: ' . (string) $cert->getCertNumber(),
        );

        return $cert;
    }

    /**
     * Store the uploaded file and wrap it in a Document entity.
     *
     * Mirrors EvidenceCollectionService::uploadAndLink's storage steps.
     */
    private function createDocument(UploadedFile $file, Tenant $tenant, User $user): Document
    {
        $safeFilename = $this->fileUploadSecurityService->generateSafeFilename($file);

        // Capture metadata before move() invalidates the temp file handle.
        $originalFilename = $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType();
        $fileSize = $file->getSize();

        $uploadDir = $this->projectDir . '/public/uploads/documents';
        $file->move($uploadDir, $safeFilename);

        $filePath = $uploadDir . '/' . $safeFilename;
        $sha256 = hash_file('sha256', $filePath) ?: null;

        $document = new Document();
        $document->setFilename($safeFilename)
            ->setOriginalFilename($originalFilename)
            ->setMimeType($mimeType)
            ->setFileSize($fileSize)
            ->setFilePath('/uploads/documents/' . $safeFilename)
            ->setCategory('certificate')
            ->setUploadedBy($user)
            ->setTenant($tenant)
            ->setSha256Hash($sha256);

        $this->em->persist($document);

        return $document;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
