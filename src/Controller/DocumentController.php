<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use Exception;
use App\Entity\Document;
use App\Form\DocumentType;
use App\Repository\DocumentRepository;
use App\Service\DocumentService;
use App\Service\FileUploadSecurityService;
use App\Service\InverseCoverageService;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\AuditorScoreCalculator;
use App\Service\PolicyWizard\Diff\SettingsDriftDetector;
use App\Service\SecurityEventLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class DocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentService $documentService,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
        private readonly RateLimiterFactory $rateLimiterFactory,
        private readonly FileUploadSecurityService $fileUploadSecurityService,
        private readonly SecurityEventLogger $securityEventLogger,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ?InverseCoverageService $inverseCoverageService = null,
        private readonly ?SettingsDriftDetector $settingsDriftDetector = null,
        private readonly ?AuditorScoreCalculator $auditorScoreCalculator = null,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    #[Route('/document/', name: 'app_document_index')]
    public function index(Request $request): Response
    {
        // Get current tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get view filter parameter
        $view = $request->query->get('view', 'own'); // Default: own documents

        // Policy-Wizard W7-C — history toggle. Default OFF: only the
        // current version of each policy is listed (a Document is
        // "current" when no other Document points back to it via
        // supersedes). Toggle via `?include_history=1` (persona-review
        // 05 lines 243-244 — without this the list balloons after
        // ~3 years of policy iterations).
        $includeHistory = (bool) $request->query->get('include_history', false);

        // Get documents based on view filter
        if ($tenant) {
            // Determine which documents to load based on view parameter
            $allDocuments = match ($view) {
                // Only own documents
                'own' => $this->documentRepository->findByTenant($tenant),
                // Own + from all subsidiaries (for parent companies)
                'subsidiaries' => $this->documentRepository->findByTenantIncludingSubsidiaries($tenant),
                // Own + inherited from parents (default behavior)
                default => $this->documentService->getDocumentsForTenant($tenant),
            };
            $inheritanceInfo = $this->documentService->getDocumentInheritanceInfo($tenant);
            $inheritanceInfo['hasSubsidiaries'] = $tenant->getSubsidiaries()->count() > 0;
            $inheritanceInfo['currentView'] = $view;
        } else {
            $allDocuments = $this->documentRepository->findAll();
            $inheritanceInfo = [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        // Hide soft-deleted/archived documents from the default list. KPI / audit
        // views can bypass via dedicated endpoints.
        $documents = array_filter($allDocuments, fn(Document $document): bool => $document->isOperational());

        // Policy-Wizard W7-C — collapse to current versions only (default).
        // A Document is hidden when ANY other Document in the list points
        // back to it via `supersedes`. We compute the "superseded ids" set
        // in PHP rather than via SQL so the inheritance / subsidiary
        // arrays stay the source of truth.
        if (!$includeHistory) {
            $supersededIds = [];
            foreach ($documents as $doc) {
                $previous = $doc->getSupersedes();
                if ($previous !== null && $previous->getId() !== null) {
                    $supersededIds[$previous->getId()] = true;
                }
            }
            $documents = array_filter(
                $documents,
                static fn (Document $document): bool => !isset($supersededIds[$document->getId() ?? -1]),
            );
        }

        // Sort by upload date descending
        usort($documents, fn($a, $b): int => $b->getUploadedAt() <=> $a->getUploadedAt());

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($documents, $tenant);
        } else {
            $detailedStats = ['own' => count($documents), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($documents)];
        }

        // Policy-Wizard W7-C — settings-drift map. For every Document in
        // the listing, ask the SettingsDriftDetector whether the snapshot
        // diverges from current tenant settings. Result map is keyed on
        // Document.id so the template can render the badge inline. The
        // service is optional in the DI graph; when missing, the map
        // stays empty and no badge renders.
        $driftMap = [];
        if ($this->settingsDriftDetector !== null && $tenant !== null) {
            foreach ($documents as $document) {
                $documentId = $document->getId();
                if ($documentId === null) {
                    continue;
                }
                if ($document->getSubstitutionVariables() === null) {
                    continue; // not a wizard-generated doc — drift n/a
                }
                $driftMap[$documentId] = $this->settingsDriftDetector->detectDriftFor($document, $tenant);
            }
        }

        // Junior-ISB Wish #5 — compute Auditor-Score for every
        // Wizard-generated document in the listing. Optional service:
        // when DI omits it, the badge column simply stays empty.
        $auditorScores = [];
        if ($this->auditorScoreCalculator !== null) {
            $auditorScores = $this->auditorScoreCalculator->calculateForBatch($documents);
        }

        return $this->render('document/index.html.twig', [
            'documents' => $documents,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
            'includeHistory' => $includeHistory,
            'driftMap' => $driftMap,
            'auditorScores' => $auditorScores,
        ]);
    }

    #[Route('/document/new', name: 'app_document_new')]
    public function new(Request $request): Response
    {
        $document = new Document();

        // Set tenant from current user
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant()) {
            $document->setTenant($user->getTenant());
        }

        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Security: Rate limit document uploads to prevent abuse
            $limiter = $this->rateLimiterFactory->create($request->getClientIp());

            if (false === $limiter->consume(1)->isAccepted()) {
                // Security: Log rate limit hit for monitoring
                $this->securityEventLogger->logRateLimitHit('document_upload');

                $this->addFlash('error', $this->translator->trans('document.error.too_many_uploads'));

                return $this->render('document/new.html.twig', [
                    'document' => $document,
                    'form' => $form,
                ]);
            }

            // Security: Validate uploaded file (MIME type, magic bytes, size, extension)
            $uploadedFile = $form->get('file')->getData();

            try {
                $this->fileUploadSecurityService->validateUploadedFile($uploadedFile);

                // Security: Generate safe filename to prevent path traversal and overwrites
                $safeFilename = $this->fileUploadSecurityService->generateSafeFilename($uploadedFile);

                // Extract metadata BEFORE moving file (temp file gets deleted after move)
                $originalFilename = $uploadedFile->getClientOriginalName();
                $mimeType = $uploadedFile->getMimeType();
                $fileSize = $uploadedFile->getSize();

                // Move file to secure upload directory
                $uploadedFile->move(
                    $this->projectDir . '/public/uploads/documents',
                    $safeFilename
                );

                // Store file information
                $document->setFilename($safeFilename);
                $document->setOriginalFilename($originalFilename);
                $document->setMimeType($mimeType);
                $document->setFileSize($fileSize);
                $document->setFilePath('/uploads/documents/' . $safeFilename);
                $document->setUploadedBy($this->getUser());

                $this->entityManager->persist($document);
                $this->entityManager->flush();

                // Security: Log successful file upload
                $this->securityEventLogger->logFileUpload(
                    $safeFilename,
                    $mimeType,
                    $fileSize,
                    true
                );

                // Security: Log data change
                $this->securityEventLogger->logDataChange('Document', $document->getId(), 'CREATE');

                $this->addFlash('success', $this->translator->trans('document.success.uploaded'));
                return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);

            } catch (FileException $e) {
                // Security: Log failed upload attempt (potential attack)
                $this->securityEventLogger->logFileUpload(
                    $uploadedFile->getClientOriginalName(),
                    $uploadedFile->getMimeType() ?? 'unknown',
                    $uploadedFile->getSize(),
                    false,
                    $e->getMessage()
                );

                $this->addFlash('error', $this->translator->trans('document.error.upload_failed') . ': ' . $e->getMessage());

                return $this->render('document/new.html.twig', [
                    'document' => $document,
                    'form' => $form,
                ]);
            }
        }

        return $this->render('document/new.html.twig', [
            'document' => $document,
            'form' => $form,
        ]);
    }

    /**
     * Dependency-check endpoint for the Aurora bulk-delete-confirmation
     * Stimulus controller. Documents have no blocking FK relations
     * preventing delete (other entities reference them via nullable FK
     * with ON DELETE SET NULL), so we always return an empty
     * dependencies list — the controller hides the "blocked" warning
     * and proceeds straight to the count-confirmation prompt.
     *
     * Without this endpoint the JS modal renders "Fehler beim Laden
     * der Abhängigkeiten: HTTP 404: Not Found" before letting the user
     * confirm.
     */
    #[Route('/document/bulk-delete-check', name: 'app_document_bulk_delete_check', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDeleteCheck(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];
        // Surface the count so the modal label can echo it back; empty
        // dependencies list = nothing blocks deletion.
        return $this->json([
            'dependencies' => [],
            'checked_count' => is_array($ids) ? count($ids) : 0,
        ]);
    }

    /**
     * Bulk-export endpoint: pack selected Documents into a ZIP and
     * stream it back. Wizard-generated docs render via PolicyPdfExporter
     * (Tenant-Branding letterhead); legacy uploads stream the original
     * file. Used by the Aurora bulk-actions controller (export-button).
     */
    #[Route('/document/bulk-export', name: 'app_document_bulk_export', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkExport(
        Request $request,
        \App\Service\PolicyWizard\Export\PolicyPdfExporter $pdfExporter,
        ?\App\Repository\TenantBrandingRepository $brandingRepository = null,
    ): Response {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();
        $branding = $tenant !== null && $brandingRepository !== null
            ? $brandingRepository->findOneBy(['tenant' => $tenant])
            : null;

        $tmpZip = tempnam(sys_get_temp_dir(), 'doc_bulk_export_') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->json(['error' => 'Cannot create ZIP'], 500);
        }

        $packed = 0;
        foreach ($ids as $id) {
            $doc = $this->documentRepository->find($id);
            if (!$doc instanceof Document) {
                continue;
            }
            // Tenant scope guard.
            if ($tenant !== null && $doc->getTenant() !== $tenant) {
                continue;
            }
            $safeName = preg_replace('/[^\w\.\-]+/', '_', (string) ($doc->getOriginalFilename() ?: 'document-' . $doc->getId()));

            if ($doc->getGeneratedFromTemplate() !== null
                || str_starts_with((string) $doc->getFilePath(), 'virtual:')) {
                // Wizard-generated → render PDF on the fly.
                try {
                    $pdf = $pdfExporter->exportDocument($doc, $branding);
                    $zip->addFromString($safeName . '.pdf', $pdf);
                    $packed++;
                } catch (\Throwable) {
                    // skip on render error; never abort the whole ZIP
                }
                continue;
            }

            // Legacy uploaded file.
            $path = (string) $doc->getFilePath();
            if (!str_starts_with($path, '/')) {
                $path = $this->projectDir . '/public' . (str_starts_with($path, '/') ? '' : '/') . ltrim($path, '/');
            }
            if (is_file($path) && is_readable($path)) {
                $zip->addFile($path, $safeName);
                $packed++;
            }
        }
        $zip->close();

        if ($packed === 0) {
            @unlink($tmpZip);
            return $this->json(['error' => 'No exportable documents'], 404);
        }

        $response = new BinaryFileResponse($tmpZip);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'documents-export-' . date('Y-m-d-His') . '.zip',
        );
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true);
        return $response;
    }

    #[Route('/document/bulk-delete', name: 'app_document_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $document = $this->documentRepository->find($id);

                if (!$document) {
                    $errors[] = "Document ID $id not found";
                    continue;
                }

                // Security check: only allow deletion of own tenant's documents
                if ($tenant && $document->getTenant() !== $tenant) {
                    $errors[] = "Document ID $id does not belong to your organization";
                    continue;
                }

                // Delete physical file if exists
                $filePath = $document->getFilePath();
                if ($filePath && file_exists($filePath)) {
                    @unlink($filePath);
                }

                $this->entityManager->remove($document);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting document ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        if ($errors !== []) {
            return $this->json([
                'success' => $deleted > 0,
                'deleted' => $deleted,
                'errors' => $errors
            ], $deleted > 0 ? 200 : 400);
        }

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => "$deleted documents deleted successfully"
        ]);
    }

    #[Route('/document/{id}', name: 'app_document_show', requirements: ['id' => '\d+'])]
    public function show(
        Document $document,
        ?\App\Service\PolicyWizard\Export\PolicyPdfExporter $pdfExporter = null,
    ): Response {
        // Security: Check if user has permission to view this document (OWASP #1 - Broken Access Control)
        $this->denyAccessUnlessGranted('view', $document);

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if document is inherited and can be edited (only if user has tenant)
        if ($tenant) {
            $isInherited = $this->documentService->isInheritedDocument($document, $tenant);
            $canEdit = $this->documentService->canEditDocument($document, $tenant);
        } else {
            $isInherited = false;
            $canEdit = true;
        }

        $inverseCoverage = $this->inverseCoverageService?->forDocument($document) ?? ['total' => 0, 'frameworks' => []];

        // Junior-ISB Wish #5 — Auditor-Score for the show view.
        // calculateForDocument returns null for non-Wizard docs.
        $auditorScore = $this->auditorScoreCalculator?->calculateForDocument($document);

        // Body-preview: only for wizard-generated docs (legacy uploads
        // have no resolvable body and would render a stub). Reuses the
        // PDF exporter's safe-subset Markdown → HTML renderer so the
        // preview matches the printed PDF.
        $policyBodyHtml = null;
        if ($document->getGeneratedFromTemplate() !== null && $pdfExporter !== null) {
            $policyBodyHtml = $pdfExporter->renderBodyHtmlPublic($document, false);
        }

        return $this->render('document/show.html.twig', [
            'document' => $document,
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
            'inverse_coverage' => $inverseCoverage,
            'auditorScore' => $auditorScore,
            'policyBodyHtml' => $policyBodyHtml,
        ]);
    }

    #[Route('/document/{id}/download', name: 'app_document_download', requirements: ['id' => '\d+'])]
    public function download(Document $document, ?Request $request = null): Response
    {
        $request ??= new Request();
        // Security: Check if user has permission to download this document (OWASP #1 - Broken Access Control)
        $this->denyAccessUnlessGranted('download', $document);

        // Policy-Wizard generated documents have no uploaded file — they live
        // as rendered body text. Route them through the PolicyExportController
        // which renders TenantBranding-letterhead PDFs on demand.
        if ($document->getGeneratedFromTemplate() !== null
            || str_starts_with((string) $document->getFilePath(), 'virtual:')) {
            return $this->redirectToRoute('app_policy_export_document_pdf', [
                'id' => $document->getId(),
            ]);
        }

        // Security: Validate filename to prevent path traversal attacks
        $fileName = $document->getFileName();
        if (!$fileName || preg_match('/[\/\\\\]/', $fileName)) {
            throw $this->createNotFoundException('Invalid file');
        }

        // Security: Use realpath to prevent path traversal
        $uploadDir = realpath($this->projectDir . '/public/uploads/documents');
        $filePath = $uploadDir . DIRECTORY_SEPARATOR . basename($fileName);

        // Security: Verify the resolved path is still within upload directory
        if (!$uploadDir || !str_starts_with(realpath($filePath) ?: '', $uploadDir)) {
            throw $this->createNotFoundException('Invalid file path');
        }

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found');
        }

        $binaryFileResponse = new BinaryFileResponse($filePath);

        // Security: Sanitize filename for content disposition header
        $safeOriginalName = preg_replace('/[^\w\s\.\-]/', '', (string) $document->getOriginalFilename());
        // ?inline=1 (e.g. Bestandsaufnahme PDF preview drawer) renders the
        // file inside an <iframe> instead of triggering a browser download.
        $disposition = $request->query->getBoolean('inline')
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $binaryFileResponse->setContentDisposition(
            $disposition,
            $safeOriginalName
        );

        return $binaryFileResponse;
    }

    #[Route('/document/{id}/edit', name: 'app_document_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Document $document): Response
    {
        // Security: Check if user has permission to edit this document (OWASP #1 - Broken Access Control)
        $this->denyAccessUnlessGranted('edit', $document);

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if document can be edited (not inherited) - only if user has tenant
        if ($tenant && !$this->documentService->canEditDocument($document, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_edit_inherited'));
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        // Snapshot the current policyBody BEFORE form binding so we can
        // detect post-generation edits + emit an audit-trail event.
        $policyBodyBefore = $document->getPolicyBody();

        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $policyBodyAfter = $document->getPolicyBody();

            // Track post-generation edits to the wizard-generated body.
            // The change-detection compares the persisted body BEFORE
            // the form bound the new value against the new value. Only
            // a real diff updates the audit columns + audit-trail entry
            // — saving the form without touching the textarea is a
            // no-op for the policy-body audit (other fields still flush).
            $bodyChanged = $form->has('policyBody') && $policyBodyBefore !== $policyBodyAfter;
            if ($bodyChanged) {
                $document->setPolicyBodyEditedAt(new DateTimeImmutable());
                if ($user instanceof User) {
                    $document->setPolicyBodyEditedBy($user);
                }
            }

            $this->entityManager->flush();

            if ($bodyChanged && $this->auditLogger !== null) {
                $beforeLen = is_string($policyBodyBefore) ? strlen($policyBodyBefore) : 0;
                $afterLen = is_string($policyBodyAfter) ? strlen($policyBodyAfter) : 0;
                $delta = $afterLen - $beforeLen;
                $this->auditLogger->logCustom(
                    action: 'policy_body_edited',
                    entityType: 'Document',
                    entityId: $document->getId(),
                    oldValues: [
                        'policy_body_chars' => $beforeLen,
                    ],
                    newValues: [
                        'policy_body_chars' => $afterLen,
                        'chars_delta' => $delta,
                        'cleared' => $afterLen === 0,
                    ],
                    description: sprintf(
                        'Policy body of Document #%d edited (%+d chars)',
                        (int) $document->getId(),
                        $delta,
                    ),
                );
            }

            $this->addFlash('success', $this->translator->trans('document.success.updated'));
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        return $this->render('document/edit.html.twig', [
            'document' => $document,
            'form' => $form,
        ]);
    }

    #[Route('/document/{id}/delete', name: 'app_document_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Document $document): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if document can be deleted (not inherited) - only if user has tenant
        if ($tenant && !$this->documentService->canEditDocument($document, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_delete_inherited'));
            return $this->redirectToRoute('app_document_index');
        }

        if ($this->isCsrfTokenValid('delete'.$document->getId(), $request->request->get('_token'))) {
            // Mark as deleted instead of actually removing
            $document->setStatus('deleted');
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('document.success.deleted'));
        }

        return $this->redirectToRoute('app_document_index');
    }

    #[Route('/document/type/{type}', name: 'app_document_by_type')]
    public function byType(string $type): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get documents: tenant-filtered if user has tenant, all if not
        $allDocuments = $tenant ? $this->documentService->getDocumentsForTenant($tenant) : $this->documentRepository->findAll();

        // Filter by type
        $documents = array_filter($allDocuments, fn(Document $document): bool => $document->getMimeType() === $type);

        return $this->render('document/by_type.html.twig', [
            'documents' => $documents,
            'type' => $type,
            'currentTenant' => $tenant,
        ]);
    }

    /**
     * Calculate detailed statistics showing breakdown by origin
     */
    private function calculateDetailedStats(array $items, $currentTenant): array
    {
        $ownCount = 0;
        $inheritedCount = 0;
        $subsidiariesCount = 0;

        // Get ancestors and subsidiaries for comparison
        $ancestors = $currentTenant->getAllAncestors();
        $ancestorIds = array_map(fn($t) => $t->getId(), $ancestors);

        $subsidiaries = $currentTenant->getAllSubsidiaries();
        $subsidiaryIds = array_map(fn($t) => $t->getId(), $subsidiaries);

        foreach ($items as $item) {
            $itemTenant = $item->getTenant();
            if (!$itemTenant) {
                continue;
            }

            $itemTenantId = $itemTenant->getId();
            $currentTenantId = $currentTenant->getId();

            if ($itemTenantId === $currentTenantId) {
                $ownCount++;
            } elseif (in_array($itemTenantId, $ancestorIds)) {
                $inheritedCount++;
            } elseif (in_array($itemTenantId, $subsidiaryIds)) {
                $subsidiariesCount++;
            }
        }

        return [
            'own' => $ownCount,
            'inherited' => $inheritedCount,
            'subsidiaries' => $subsidiariesCount,
            'total' => $ownCount + $inheritedCount + $subsidiariesCount
        ];
    }
}
