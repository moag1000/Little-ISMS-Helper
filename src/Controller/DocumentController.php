<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use Exception;
use App\Entity\Document;
use App\Form\DocumentType;
use App\Entity\DocumentVersion;
use App\Repository\CommentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentVersionRepository;
use App\Service\Evidence\EvidenceCascadeInvalidationService;
use App\Service\Evidence\DocumentReuseAnalyticsService;
use App\Service\Evidence\EvidenceVersioningService;
use App\Repository\EntityTagRepository;
use App\Service\DocumentService;
use App\Service\FileUploadSecurityService;
use App\Service\InverseCoverageService;
use App\Service\TenantContext;
use App\Repository\UserRepository;
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
        private readonly ?EntityTagRepository $entityTagRepository = null,
        private readonly ?CommentRepository $commentRepository = null,
        private readonly ?DocumentControlLinkRepository $documentControlLinkRepository = null,
        private readonly ?ComplianceRequirementRepository $complianceRequirementRepository = null,
        private readonly ?DocumentVersionRepository $documentVersionRepository = null,
        private readonly ?EvidenceVersioningService $evidenceVersioningService = null,
        private readonly ?EvidenceCascadeInvalidationService $evidenceCascadeInvalidationService = null,
        private readonly ?DocumentReuseAnalyticsService $documentReuseAnalyticsService = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?TenantContext $tenantContext = null,
    ) {}

    #[Route('/document/', name: 'app_document_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get current tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get view filter parameter
        $view = $request->query->get('view', 'own'); // Default: own documents

        // Status filter — 'archived' lifts the isOperational() exclusion
        $currentStatus = $request->query->get('status');

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

        // Hide soft-deleted/archived documents from the default list.
        // When the user explicitly filters by 'archived', lift the
        // isOperational() exclusion so archived docs become visible.
        if ($currentStatus === 'archived') {
            $documents = array_filter(
                $allDocuments,
                static fn(Document $document): bool => $document->getStatus() === 'archived',
            );
        } else {
            $documents = array_filter($allDocuments, fn(Document $document): bool => $document->isOperational());
        }

        // Policy-Wizard W7-C — collapse to current versions only (default).
        // A Document is hidden when ANY other Document in the list points
        // back to it via `supersedes`. We compute the "superseded ids" set
        // in PHP rather than via SQL so the inheritance / subsidiary
        // arrays stay the source of truth.
        $supersededIds = [];
        foreach ($documents as $doc) {
            $previous = $doc->getSupersedes();
            if ($previous !== null && $previous->getId() !== null) {
                $supersededIds[$previous->getId()] = true;
            }
        }
        if (!$includeHistory) {
            $documents = array_filter(
                $documents,
                static fn (Document $document): bool => !isset($supersededIds[$document->getId() ?? -1]),
            );
        }

        // Apply status filter (when not already handled by archived-branch above).
        if ($currentStatus !== null && $currentStatus !== 'archived') {
            $documents = array_filter(
                $documents,
                static fn (Document $document): bool => $document->getStatus() === $currentStatus,
            );
        }

        // Sort by upload date descending
        usort($documents, fn($a, $b): int => $b->getUploadedAt() <=> $a->getUploadedAt());

        // KPI counts: computed from the full operational pool (before status filter)
        // so the KPI tiles always reflect the real totals regardless of active chip.
        $allOperational = ($currentStatus === 'archived')
            ? array_filter($allDocuments, fn(Document $d): bool => $d->isOperational())
            : $documents;

        // Recompute on unfiltered operational set if a status-filter is active.
        $kpiPool = ($currentStatus !== null)
            ? array_filter($allDocuments, fn(Document $d): bool => $d->isOperational())
            : $documents;

        $kpiDrafts   = count(array_filter($kpiPool, static fn(Document $d): bool => $d->getStatus() === 'draft'));
        $kpiInReview = count(array_filter($kpiPool, static fn(Document $d): bool => $d->getStatus() === 'in_review'));

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats(
                $currentStatus !== null ? array_values($kpiPool) : array_values($documents),
                $tenant,
            );
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
            'currentStatus' => $currentStatus,
            'supersededIds' => $supersededIds,
            'kpiDrafts' => $kpiDrafts,
            'kpiInReview' => $kpiInReview,
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

    /**
     * Bulk status-change endpoint for the document list.
     *
     * Accepts JSON body: { "ids": [1,2,3], "newStatus": "approved" }
     * Enforces server-side status-transition rules per the document lifecycle:
     *   draft      → in_review
     *   in_review  → approved | draft  (approve or reject back)
     *   approved   → published
     *   published  → archived
     *   archived   → published          (re-activation)
     *
     * Returns: { "ok": true, "changed": N, "rejected": [{id, reason}], "batchId": "uuid" }
     *
     * ISO 27001 Cl. 7.5.3 — AuditLogger::logBulk() with per-entity old/new status entries.
     */
    #[Route('/document/bulk-status-change', name: 'app_document_bulk_status_change', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkStatusChange(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];
        $newStatus = $data['newStatus'] ?? '';

        if (empty($ids) || !is_array($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $allowedStatuses = ['draft', 'in_review', 'approved', 'published', 'archived'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            return $this->json(['error' => 'Invalid target status'], 400);
        }

        // Valid transitions: [from => [allowed targets]]
        $validTransitions = [
            'draft'     => ['in_review'],
            'in_review' => ['approved', 'draft'],
            'approved'  => ['published'],
            'published' => ['archived'],
            'archived'  => ['published'],
        ];

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $validChanges = [];
        $rejected = [];
        $perEntityData = [];

        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            $document = $this->documentRepository->find($id);

            if (!$document instanceof Document) {
                $rejected[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }

            if ($tenant !== null && $document->getTenant() !== $tenant) {
                $rejected[] = ['id' => $id, 'reason' => 'tenant_mismatch'];
                continue;
            }

            $currentStatus = $document->getStatus() ?? 'draft';
            $allowed = $validTransitions[$currentStatus] ?? [];

            if (!in_array($newStatus, $allowed, true)) {
                $rejected[] = ['id' => $id, 'reason' => 'invalid_transition'];
                continue;
            }

            $validChanges[] = ['id' => $id, 'oldStatus' => $currentStatus];
            $perEntityData[] = [
                'action'     => 'update',
                'entity_id'  => $id,
                'old_values' => ['status' => $currentStatus],
                'new_values' => ['status' => $newStatus],
            ];
        }

        if (!empty($validChanges)) {
            foreach ($validChanges as $entry) {
                $managed = $this->entityManager->find(Document::class, $entry['id']);
                if ($managed instanceof Document) {
                    $managed->setStatus($newStatus);
                }
            }
            $this->entityManager->flush();
        }

        $batchId = '';
        if ($this->auditLogger !== null && !empty($perEntityData)) {
            $batchId = $this->auditLogger->logBulk(
                'document.status_change',
                'Document',
                [
                    'new_status'    => $newStatus,
                    'changed_count' => count($validChanges),
                    'rejected_count' => count($rejected),
                ],
                $perEntityData,
                sprintf('Bulk status change to "%s": %d changed, %d rejected', $newStatus, count($validChanges), count($rejected)),
            );
        }

        return $this->json([
            'ok'       => true,
            'changed'  => count($validChanges),
            'rejected' => $rejected,
            'batchId'  => $batchId,
        ]);
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

    #[Route('/document/{id}', name: 'app_document_show', requirements: ['id' => '\d+'], methods: ['GET'])]
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

        // Compliance-Manager Wish — surface the `dora-validity:YYYY-MM-DD`
        // and `climate-change:amended` EntityTag flags directly in the
        // header. Auditors expect the DORA-Stand and Amd. 1:2024 marker
        // to be visible without grepping through the tag-list overlay.
        $doraValidityDate = null;
        $climateChangeAware = false;
        if ($this->entityTagRepository !== null && $document->getId() !== null) {
            $activeTags = $this->entityTagRepository->findActiveFor(Document::class, $document->getId());
            $tagNames = [];
            foreach ($activeTags as $entityTag) {
                $tag = $entityTag->getTag();
                if ($tag !== null) {
                    $name = $tag->getName();
                    if (is_string($name)) {
                        $tagNames[] = $name;
                    }
                }
            }
            $doraValidityDate = Document::parseDoraValidityFromTags($tagNames);
            $climateChangeAware = Document::isClimateChangeAwareFromTags($tagNames);
        }

        // V3 W2-H3: Comment-Thread (C7) — load thread for this Document.
        $comments = [];
        if ($this->commentRepository !== null && $tenant !== null && $document->getId() !== null) {
            $comments = $this->commentRepository->findThread($tenant, 'Document', $document->getId());
        }

        // Multi-framework evidence linkage: fetch DocumentControlLink rows +
        // group ComplianceRequirement evidenceDocuments by framework.
        $controlLinks = [];
        if ($this->documentControlLinkRepository !== null && $document->getId() !== null) {
            $controlLinks = $this->documentControlLinkRepository->findByDocument($document);
        }

        // Group ComplianceRequirements whose evidenceDocuments contain this doc, by framework.
        $requirementsByFramework = [];
        if ($this->complianceRequirementRepository !== null && $document->getId() !== null) {
            $linkedRequirements = $this->complianceRequirementRepository
                ->findByEvidenceDocument($document);
            foreach ($linkedRequirements as $req) {
                $fw = $req->getFramework();
                if ($fw === null) {
                    continue;
                }
                $fwCode = (string) $fw->getCode();
                if (!isset($requirementsByFramework[$fwCode])) {
                    $requirementsByFramework[$fwCode] = [
                        'framework' => $fw,
                        'requirements' => [],
                    ];
                }
                $requirementsByFramework[$fwCode]['requirements'][] = $req;
            }
        }

        return $this->render('document/show.html.twig', [
            'document' => $document,
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
            'inverse_coverage' => $inverseCoverage,
            'auditorScore' => $auditorScore,
            'policyBodyHtml' => $policyBodyHtml,
            'doraValidityDate' => $doraValidityDate,
            'climateChangeAware' => $climateChangeAware,
            // V3 W2-H3: Comments thread + form action
            'comments' => $comments,
            // Multi-framework evidence linkage (Phase 1+2)
            'controlLinks' => $controlLinks,
            'requirementsByFramework' => $requirementsByFramework,
        ]);
    }

    #[Route('/document/{id}/download', name: 'app_document_download', requirements: ['id' => '\d+'], methods: ['GET'])]
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

        $form = $this->createForm(DocumentType::class, $document, ['is_new' => false]);
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

    /**
     * Effectiveness-Review (Wirksamkeitspruefung).
     *
     * Captures an explicit ISB / Auditor review event for the policy
     * document. Stores the timestamp + reviewing user + free-text
     * notes; emits a `document_effectiveness_reviewed` audit-trail
     * entry. INTENTIONALLY does NOT bump the SoA implementation
     * status to `implemented` — that decision stays with the ISB and
     * happens via the regular Control edit form. The effectiveness
     * review is the audit evidence that the decision was reviewed,
     * not the decision itself.
     *
     * Authorisation: ROLE_AUDITOR (covers AUDITOR + MANAGER + ADMIN +
     * SUPER_ADMIN per security.yaml hierarchy). USERs can read but
     * not record reviews.
     */
    #[Route(
        '/document/{id}/mark-effectiveness-reviewed',
        name: 'app_document_mark_effectiveness_reviewed',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_AUDITOR')]
    public function markEffectivenessReviewed(Request $request, Document $document): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            $this->addFlash('error', $this->translator->trans('common.error.access_denied', [], 'messages'));
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        // Authorise per-document via the existing voter — the same
        // gate that protects view/edit. Auditor on a non-readable
        // document still gets blocked.
        $this->denyAccessUnlessGranted('view', $document);

        if (!$this->isCsrfTokenValid(
            'document_effectiveness_review_' . $document->getId(),
            (string) $request->request->get('_token'),
        )) {
            $this->addFlash('error', $this->translator->trans('common.error.invalid_csrf', [], 'messages'));
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        $notes = trim((string) $request->request->get('notes', ''));
        $confirmed = (bool) $request->request->get('confirmed', false);

        $reviewedAt = new DateTimeImmutable();
        $document->setLastEffectivenessReviewAt($reviewedAt);
        $document->setLastEffectivenessReviewBy($user);
        $document->setEffectivenessReviewNotes($notes !== '' ? $notes : null);

        $this->entityManager->flush();

        if ($this->auditLogger !== null) {
            $this->auditLogger->logCustom(
                action: 'document_effectiveness_reviewed',
                entityType: 'Document',
                entityId: $document->getId(),
                oldValues: null,
                newValues: [
                    'document_id'        => $document->getId(),
                    'reviewed_by_user_id' => $user->getId(),
                    'reviewed_at'        => $reviewedAt->format(\DateTimeInterface::ATOM),
                    'notes_length'       => strlen($notes),
                    'confirmed'          => $confirmed,
                ],
                description: sprintf(
                    'Effectiveness review recorded for Document #%d by %s (notes: %d chars, confirmed=%s)',
                    (int) $document->getId(),
                    $user->getFullName() ?? $user->getEmail() ?? '?',
                    strlen($notes),
                    $confirmed ? 'yes' : 'no',
                ),
            );
        }

        $this->addFlash('success', $this->translator->trans(
            'document.effectiveness.success.recorded',
            [],
            'document',
        ));

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
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

    #[Route('/document/type/{type}', name: 'app_document_by_type', methods: ['GET'])]
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

    // ── F4 Evidence-Versioning endpoints ──────────────────────────────────────

    /**
     * Version history drawer for a document.
     *
     * GET /document/{id}/versions
     */
    #[Route('/document/{id}/versions', name: 'app_document_versions', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function versions(Document $document): Response
    {
        $this->denyAccessUnlessGranted('view', $document);

        $versions = $this->documentVersionRepository?->findByDocument($document) ?? [];

        $reuseData = $this->documentReuseAnalyticsService?->getReuseFactorForDocument($document) ?? [
            'control_count' => 0,
            'framework_count' => 0,
            'label' => '',
        ];

        return $this->render('document/_version_list.html.twig', [
            'document' => $document,
            'versions' => $versions,
            'reuse' => $reuseData,
        ]);
    }

    /**
     * Download a specific version file.
     *
     * GET /document/{id}/version/{vid}/download
     */
    #[Route('/document/{id}/version/{vid}/download', name: 'app_document_version_download', requirements: ['id' => '\d+', 'vid' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function versionDownload(Document $document, int $vid): Response
    {
        $this->denyAccessUnlessGranted('view', $document);

        $version = $this->documentVersionRepository?->find($vid);
        if ($version === null || $version->getDocument()?->getId() !== $document->getId()) {
            throw $this->createNotFoundException('Version not found.');
        }

        $this->denyAccessUnlessGranted('download', $version);

        $fullPath = $this->projectDir . '/public' . $version->getFilePath();
        if (!file_exists($fullPath)) {
            $fullPath = $version->getFilePath();
        }

        if (!file_exists($fullPath)) {
            throw $this->createNotFoundException('Version file not found on disk.');
        }

        $response = new BinaryFileResponse($fullPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $version->getFileName(),
        );
        $response->headers->set('Content-Type', $version->getMimeType());
        return $response;
    }

    /**
     * Undo the last version upload (5-second window).
     *
     * POST /document/{id}/version/{vid}/undo
     */
    #[Route('/document/{id}/version/{vid}/undo', name: 'app_document_version_undo', requirements: ['id' => '\d+', 'vid' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function versionUndo(Document $document, int $vid, Request $request): Response
    {
        $this->denyAccessUnlessGranted('edit', $document);

        if (!$this->isCsrfTokenValid('version_undo_' . $vid, (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('document.undo.error.invalid_token', [], 'document'));
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        if ($this->evidenceVersioningService === null) {
            $this->addFlash('error', $this->translator->trans('document.undo.error.service_unavailable', [], 'document'));
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        $success = $this->evidenceVersioningService->undo($vid);

        if ($success) {
            $this->addFlash('success', $this->translator->trans('document.undo.success', [], 'document'));
        } else {
            $this->addFlash('warning', $this->translator->trans('document.undo.error.window_expired', [], 'document'));
        }

        return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
    }

    /**
     * Sprint-2 P-7 Wave-2 Trigger-4: Document acknowledgement audience picker.
     *
     * ISO 27001 A.6.3 — explicit audience selection for policy
     * acknowledgement, replacing the broadcast-to-all default. GET shows
     * a User multi-select scoped to the document's tenant; POST persists
     * the selection into Document::$acknowledgementAudience.
     */
    #[Route('/document/{id}/acknowledgement-audience', name: 'app_document_acknowledgement_audience_picker', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function acknowledgementAudiencePicker(Request $request, Document $document): Response
    {
        $tenant = $document->getTenant();
        $currentTenant = $this->tenantContext?->getCurrentTenant();
        if ($tenant === null || $tenant !== $currentTenant) {
            throw $this->createNotFoundException();
        }
        if (!$document->getRequiresAcknowledgement()) {
            $this->addFlash('warning', $this->translator->trans('document.acknowledgement_audience.flash_not_required', [], 'alva'));
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        if ($request->isMethod('POST') && $this->userRepository !== null) {
            $userIds = array_filter(
                (array) $request->request->all('user_ids'),
                static fn($id): bool => is_string($id) && ctype_digit($id),
            );

            // Reset + re-attach (idempotent)
            foreach ($document->getAcknowledgementAudience() as $existing) {
                $document->removeAcknowledgementAudience($existing);
            }
            foreach ($userIds as $id) {
                $u = $this->userRepository->find((int) $id);
                if ($u instanceof User && $u->getTenant() === $tenant) {
                    $document->addAcknowledgementAudience($u);
                }
            }
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('document.acknowledgement_audience.flash_saved', [], 'alva'));

            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        $candidates = $this->userRepository !== null
            ? $this->userRepository->findBy(['tenant' => $tenant, 'isActive' => true], ['email' => 'ASC'])
            : [];

        return $this->render('document/acknowledgement_audience_picker.html.twig', [
            'document' => $document,
            'candidates' => $candidates,
            'already_selected' => $document->getAcknowledgementAudience(),
        ]);
    }
}
