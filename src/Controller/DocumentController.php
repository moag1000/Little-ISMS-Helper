<?php

namespace App\Controller;

use App\Entity\Document;
use App\Form\DocumentType;
use App\Repository\DocumentRepository;
use App\Service\DocumentService;
use App\Service\FileUploadSecurityService;
use App\Service\SecurityEventLogger;
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

#[Route('/document')]
#[IsGranted('ROLE_USER')]
class DocumentController extends AbstractController
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentService $documentService,
        private EntityManagerInterface $entityManager,
        private string $projectDir,
        private RateLimiterFactory $documentUploadLimiter,
        private FileUploadSecurityService $fileUploadSecurity,
        private SecurityEventLogger $securityLogger,
        private TranslatorInterface $translator,
        private Security $security
    ) {}

    #[Route('/', name: 'app_document_index')]
    public function index(Request $request): Response
    {
        // Get current tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get view filter parameter
        $view = $request->query->get('view', 'inherited'); // Default: inherited

        // Get documents based on view filter
        if ($tenant) {
            // Determine which documents to load based on view parameter
            switch ($view) {
                case 'own':
                    // Only own documents
                    $allDocuments = $this->documentRepository->findByTenant($tenant);
                    break;
                case 'subsidiaries':
                    // Own + from all subsidiaries (for parent companies)
                    $allDocuments = $this->documentRepository->findByTenantIncludingSubsidiaries($tenant);
                    break;
                case 'inherited':
                default:
                    // Own + inherited from parents (default behavior)
                    $allDocuments = $this->documentService->getDocumentsForTenant($tenant);
                    break;
            }

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

        // Filter to active only
        $documents = array_filter($allDocuments, fn($doc) => $doc->getStatus() === 'active');

        // Sort by upload date descending
        usort($documents, fn($a, $b) => $b->getUploadedAt() <=> $a->getUploadedAt());

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($documents, $tenant);
        } else {
            $detailedStats = ['own' => count($documents), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($documents)];
        }

        return $this->render('document/index.html.twig', [
            'documents' => $documents,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }

    #[Route('/new', name: 'app_document_new')]
    public function new(Request $request): Response
    {
        $document = new Document();
        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Security: Rate limit document uploads to prevent abuse
            $limiter = $this->documentUploadLimiter->create($request->getClientIp());

            if (false === $limiter->consume(1)->isAccepted()) {
                // Security: Log rate limit hit for monitoring
                $this->securityLogger->logRateLimitHit('document_upload');

                $this->addFlash('error', $this->translator->trans('document.error.too_many_uploads'));

                return $this->render('document/new.html.twig', [
                    'document' => $document,
                    'form' => $form,
                ]);
            }

            // Security: Validate uploaded file (MIME type, magic bytes, size, extension)
            $uploadedFile = $form->get('file')->getData();

            try {
                $this->fileUploadSecurity->validateUploadedFile($uploadedFile);

                // Security: Generate safe filename to prevent path traversal and overwrites
                $safeFilename = $this->fileUploadSecurity->generateSafeFilename($uploadedFile);

                // Move file to secure upload directory
                $uploadedFile->move(
                    $this->projectDir . '/public/uploads/documents',
                    $safeFilename
                );

                // Store file information
                $document->setFilename($safeFilename);
                $document->setOriginalFilename($uploadedFile->getClientOriginalName());
                $document->setMimeType($uploadedFile->getMimeType());
                $document->setFileSize($uploadedFile->getSize());
                $document->setFilePath('/uploads/documents/' . $safeFilename);
                $document->setUploadedBy($this->getUser());

                $this->entityManager->persist($document);
                $this->entityManager->flush();

                // Security: Log successful file upload
                $this->securityLogger->logFileUpload(
                    $safeFilename,
                    $uploadedFile->getMimeType(),
                    $uploadedFile->getSize(),
                    true
                );

                // Security: Log data change
                $this->securityLogger->logDataChange('Document', $document->getId(), 'CREATE');

                $this->addFlash('success', $this->translator->trans('document.success.uploaded'));
                return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);

            } catch (FileException $e) {
                // Security: Log failed upload attempt (potential attack)
                $this->securityLogger->logFileUpload(
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

    #[Route('/bulk-delete', name: 'app_document_bulk_delete', methods: ['POST'])]
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
            } catch (\Exception $e) {
                $errors[] = "Error deleting document ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        if (!empty($errors)) {
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

    #[Route('/{id}', name: 'app_document_show', requirements: ['id' => '\d+'])]
    public function show(Document $document): Response
    {
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

        return $this->render('document/show.html.twig', [
            'document' => $document,
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
        ]);
    }

    #[Route('/{id}/download', name: 'app_document_download', requirements: ['id' => '\d+'])]
    public function download(Document $document): Response
    {
        // Security: Check if user has permission to download this document (OWASP #1 - Broken Access Control)
        $this->denyAccessUnlessGranted('download', $document);

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

        $response = new BinaryFileResponse($filePath);

        // Security: Sanitize filename for content disposition header
        $safeOriginalName = preg_replace('/[^\w\s\.\-]/', '', $document->getOriginalName());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $safeOriginalName
        );

        return $response;
    }

    #[Route('/{id}/edit', name: 'app_document_edit', requirements: ['id' => '\d+'])]
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

        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('document.success.updated'));
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        return $this->render('document/edit.html.twig', [
            'document' => $document,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_document_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
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

    #[Route('/type/{type}', name: 'app_document_by_type')]
    public function byType(string $type): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get documents: tenant-filtered if user has tenant, all if not
        if ($tenant) {
            $allDocuments = $this->documentService->getDocumentsForTenant($tenant);
        } else {
            $allDocuments = $this->documentRepository->findAll();
        }

        // Filter by type
        $documents = array_filter($allDocuments, fn($doc) => $doc->getType() === $type);

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
