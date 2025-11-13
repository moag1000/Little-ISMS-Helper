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
    public function index(): Response
    {
        // Get current tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        if (!$tenant) {
            throw $this->createAccessDeniedException('No tenant associated with user');
        }

        // Get documents based on governance model
        $allDocuments = $this->documentService->getDocumentsForTenant($tenant);

        // Filter to active only
        $documents = array_filter($allDocuments, fn($doc) => $doc->getStatus() === 'active');

        // Sort by upload date descending
        usort($documents, fn($a, $b) => $b->getUploadedAt() <=> $a->getUploadedAt());

        // Get inheritance info
        $inheritanceInfo = $this->documentService->getDocumentInheritanceInfo($tenant);

        return $this->render('document/index_modern.html.twig', [
            'documents' => $documents,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
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

                return $this->render('document/new_modern.html.twig', [
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

                return $this->render('document/new_modern.html.twig', [
                    'document' => $document,
                    'form' => $form,
                ]);
            }
        }

        return $this->render('document/new_modern.html.twig', [
            'document' => $document,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_document_show', requirements: ['id' => '\d+'])]
    public function show(Document $document): Response
    {
        // Security: Check if user has permission to view this document (OWASP #1 - Broken Access Control)
        $this->denyAccessUnlessGranted('view', $document);

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        if (!$tenant) {
            throw $this->createAccessDeniedException('No tenant associated with user');
        }

        // Check if document is inherited and can be edited
        $isInherited = $this->documentService->isInheritedDocument($document, $tenant);
        $canEdit = $this->documentService->canEditDocument($document, $tenant);

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

        if (!$tenant) {
            throw $this->createAccessDeniedException('No tenant associated with user');
        }

        // Check if document can be edited (not inherited)
        if (!$this->documentService->canEditDocument($document, $tenant)) {
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

        if (!$tenant) {
            throw $this->createAccessDeniedException('No tenant associated with user');
        }

        // Check if document can be deleted (not inherited)
        if (!$this->documentService->canEditDocument($document, $tenant)) {
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

        if (!$tenant) {
            throw $this->createAccessDeniedException('No tenant associated with user');
        }

        // Get documents based on governance model
        $allDocuments = $this->documentService->getDocumentsForTenant($tenant);

        // Filter by type
        $documents = array_filter($allDocuments, fn($doc) => $doc->getType() === $type);

        return $this->render('document/by_type.html.twig', [
            'documents' => $documents,
            'type' => $type,
            'currentTenant' => $tenant,
        ]);
    }
}
