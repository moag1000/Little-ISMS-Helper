<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DocumentRepository;
use App\Service\AuditLogger;
use App\Service\EvidenceCollectionService;
use App\Service\SecurityEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Evidence Collection Controller
 *
 * Manages evidence document uploads and linkage to Controls,
 * ComplianceRequirements, and RiskTreatmentPlans for ISO 27001 audit preparation.
 */
#[Route('/evidence', name: 'app_evidence_')]
#[IsGranted('ROLE_USER')]
class EvidenceController extends AbstractController
{
    public function __construct(
        private readonly EvidenceCollectionService $evidenceService,
        private readonly DocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly SecurityEventLogger $securityEventLogger,
    ) {}

    /**
     * Evidence dashboard: coverage stats, recent uploads, gap overview.
     */
    #[Route('', name: 'index')]
    public function index(): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        if (!$tenant) {
            throw $this->createAccessDeniedException('Tenant context required.');
        }

        $coverage = $this->evidenceService->getEvidenceCoverage($tenant);
        $recentEvidence = $this->evidenceService->getRecentEvidence($tenant, 15);
        $totalDocuments = $this->evidenceService->getTotalEvidenceCount($tenant);

        return $this->render('evidence/index.html.twig', [
            'coverage' => $coverage,
            'recentEvidence' => $recentEvidence,
            'totalDocuments' => $totalDocuments,
        ]);
    }

    /**
     * Upload evidence file and link to entity.
     */
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        if (!$tenant) {
            throw $this->createAccessDeniedException('Tenant context required.');
        }

        // CSRF validation
        if (!$this->isCsrfTokenValid('evidence_upload', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('app_evidence_index');
        }

        $entityType = $request->request->get('entity_type');
        $entityId = (int) $request->request->get('entity_id');
        $file = $request->files->get('evidence_file');

        if (!$file) {
            $this->addFlash('error', $this->translator->trans('evidence.error.no_file', [], 'evidence'));
            return $this->redirectBack($request, $entityType, $entityId);
        }

        if (!$entityType || !$entityId) {
            $this->addFlash('error', $this->translator->trans('evidence.error.missing_entity', [], 'evidence'));
            return $this->redirectToRoute('app_evidence_index');
        }

        try {
            $document = $this->evidenceService->uploadAndLink($file, $entityType, $entityId, $user, $tenant);

            $this->securityEventLogger->logFileUpload(
                $document->getFilename(),
                $document->getMimeType(),
                $document->getFileSize(),
                true
            );
            $this->securityEventLogger->logDataChange('Document', $document->getId(), 'CREATE');

            $this->addFlash('success', $this->translator->trans('evidence.upload_success', [], 'evidence'));
        } catch (FileException $e) {
            $this->securityEventLogger->logFileUpload(
                $file->getClientOriginalName(),
                $file->getMimeType() ?? 'unknown',
                $file->getSize(),
                false,
                $e->getMessage()
            );
            $this->addFlash('error', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectBack($request, $entityType, $entityId);
    }

    /**
     * Link an existing document to an entity (AJAX).
     */
    #[Route('/link', name: 'link', methods: ['POST'])]
    public function link(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('evidence_link', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $documentId = (int) $request->request->get('document_id');
        $entityType = $request->request->get('entity_type');
        $entityId = (int) $request->request->get('entity_id');

        $document = $this->documentRepository->find($documentId);
        if (!$document) {
            return new JsonResponse(['error' => 'Document not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->evidenceService->linkToEntity($document, $entityType, $entityId);
            return new JsonResponse(['success' => true, 'message' => 'Evidence linked']);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Unlink a document from an entity (AJAX).
     */
    #[Route('/unlink', name: 'unlink', methods: ['POST'])]
    public function unlink(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('evidence_unlink', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $documentId = (int) $request->request->get('document_id');
        $entityType = $request->request->get('entity_type');
        $entityId = (int) $request->request->get('entity_id');

        $document = $this->documentRepository->find($documentId);
        if (!$document) {
            return new JsonResponse(['error' => 'Document not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->evidenceService->unlinkFromEntity($document, $entityType, $entityId);
            return new JsonResponse(['success' => true, 'message' => 'Evidence unlinked']);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Evidence coverage report page.
     */
    #[Route('/coverage', name: 'coverage')]
    public function coverage(): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        if (!$tenant) {
            throw $this->createAccessDeniedException('Tenant context required.');
        }

        $controlCoverage = $this->evidenceService->getControlEvidenceCoverage($tenant);
        $coverage = $this->evidenceService->getEvidenceCoverage($tenant);

        return $this->render('evidence/coverage.html.twig', [
            'controlCoverage' => $controlCoverage,
            'coverage' => $coverage,
        ]);
    }

    /**
     * Redirect back to the entity show page or evidence index.
     */
    private function redirectBack(Request $request, ?string $entityType, ?int $entityId): Response
    {
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_evidence_index');
    }
}
