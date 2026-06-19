<?php

declare(strict_types=1);

namespace App\Job;

use App\Entity\ComplianceCertificate;
use App\Exception\Module\ModuleNotActiveException;
use App\Repository\ComplianceCertificateRepository;
use App\Service\Certificate\CertificateFieldExtractor;
use App\Service\Certificate\OcrCapabilityDetector;
use App\Service\Certificate\PdfTextExtractor;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Async admin job (Phase B2): OCR a manually-uploaded compliance certificate
 * and write the extracted draft fields back onto the record for later
 * user review/confirmation (T12).
 *
 * Pipeline:
 *   1. Capability gate — {@see OcrCapabilityDetector::isAvailable()} (module +
 *      both binaries). If unavailable the job throws so the failure is recorded
 *      and the manual record is left untouched.
 *   2. Resolve the certificate's stored {@see \App\Entity\Document} to an
 *      absolute on-disk path (mirrors {@see \App\Service\Certificate\CertificateUploadService}:
 *      "%kernel.project_dir%/public" + Document::getFilePath()).
 *   3. {@see PdfTextExtractor} pulls text — pdftotext for digital PDFs, falling
 *      back to tesseract for scanned (text-layer-less) documents.
 *   4. {@see CertificateFieldExtractor::extract()} (pure heuristic) turns the
 *      raw text into a draft field set.
 *   5. The draft is mapped onto the certificate with extractionSource='ocr' and
 *      extractionConfidence set, then persisted.
 *
 * Request-bound services are NOT used — the certificate id arrives via job args
 * and the entity is reloaded through the repository, so the job is safe to run
 * after fastcgi_finish_request() / in a Messenger worker.
 *
 * Args:
 *   certificateId (int) — the ComplianceCertificate to OCR. Required.
 */
final class ProcessCertificateOcrJob implements AsyncJobInterface
{
    public function __construct(
        private readonly OcrCapabilityDetector $ocrDetector,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly CertificateFieldExtractor $fieldExtractor,
        private readonly ComplianceCertificateRepository $certificateRepository,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $certId = (int) $ctx->arg('certificateId');

        $ctx->progress(0, 4, 'Checking OCR availability…');

        // 1. Capability gate — leave the manual record untouched if OCR is off.
        if (!$this->ocrDetector->isAvailable()) {
            throw new ModuleNotActiveException(
                'ocr_processing',
                'OCR is not available (module disabled or pdftotext/tesseract missing).',
            );
        }

        $cert = $this->certificateRepository->find($certId);
        if (!$cert instanceof ComplianceCertificate) {
            // @intentional-assertion: dispatched id must reference a real cert
            throw new \RuntimeException(sprintf('ComplianceCertificate #%d not found.', $certId));
        }

        // 2. Resolve the stored document to an absolute disk path.
        $absPath = $this->resolveDocumentPath($cert);
        $ctx->progress(1, 4, 'Extracting text from certificate file…');

        // 3. pdftotext (digital) → tesseract (scan) fallback, behind a seam.
        $text = $this->pdfTextExtractor->extractText($absPath);

        // 4. Heuristic field extraction (pure).
        $ctx->progress(2, 4, 'Extracting certificate fields…');
        $draft = $this->fieldExtractor->extract($text);

        // 5. Map the draft onto the certificate (only the extractable fields).
        $ctx->progress(3, 4, 'Writing draft fields…');
        $this->applyDraft($cert, $draft);

        $this->em->flush();

        $ctx->progress(4, 4, sprintf(
            'Done. OCR draft written for certificate #%d (confidence %.0f%%).',
            $certId,
            $draft['confidence'] * 100,
        ));
    }

    /**
     * Build the absolute on-disk path from the linked Document.
     *
     * Mirrors CertificateUploadService: the file lives under public/ and the
     * Document stores a web-relative path like "/uploads/documents/<file>".
     */
    private function resolveDocumentPath(ComplianceCertificate $cert): string
    {
        $document = $cert->getCertificateDocument();
        $filePath = $document?->getFilePath();

        if ($filePath === null || $filePath === '') {
            throw new \RuntimeException(sprintf(
                'Certificate #%d has no stored document file to OCR.',
                (int) $cert->getId(),
            ));
        }

        return $this->projectDir . '/public' . $filePath;
    }

    /**
     * @param array{
     *     certBody: string|null,
     *     certNumber: string|null,
     *     validUntil: string|null,
     *     issueDate: string|null,
     *     holder: string|null,
     *     frameworkGuess: string|null,
     *     confidence: float,
     * } $draft
     */
    private function applyDraft(ComplianceCertificate $cert, array $draft): void
    {
        if ($draft['certBody'] !== null) {
            $cert->setCertBody($draft['certBody']);
        }
        if ($draft['certNumber'] !== null) {
            $cert->setCertNumber($draft['certNumber']);
        }
        if ($draft['holder'] !== null) {
            $cert->setHolder($draft['holder']);
        }
        if ($draft['frameworkGuess'] !== null) {
            $cert->setFrameworkCode($draft['frameworkGuess']);
        }
        if ($draft['validUntil'] !== null) {
            $cert->setValidUntil(new DateTimeImmutable($draft['validUntil']));
        }
        if ($draft['issueDate'] !== null) {
            $cert->setIssueDate(new DateTimeImmutable($draft['issueDate']));
        }

        $cert->setExtractionSource('ocr');
        $cert->setExtractionConfidence($draft['confidence']);
    }
}
