<?php

namespace App\Controller;

use App\Entity\DataProtectionImpactAssessment;
use App\Form\DataProtectionImpactAssessmentType;
use App\Service\DataProtectionImpactAssessmentService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/dpia')]
#[IsGranted('ROLE_USER')]
class DPIAController extends AbstractController
{
    public function __construct(
        private DataProtectionImpactAssessmentService $service,
        private PdfExportService $pdfService,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private TenantContext $tenantContext
    ) {}

    /**
     * List all DPIAs (index view)
     */
    #[Route('/', name: 'app_dpia_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $filter = $request->query->get('filter', 'all');

        $dpias = match ($filter) {
            'draft' => $this->service->findDrafts(),
            'in_review' => $this->service->findInReview(),
            'approved' => $this->service->findApproved(),
            'requires_revision' => $this->service->findRequiringRevision(),
            'high_risk' => $this->service->findHighRisk(),
            'incomplete' => $this->service->findIncomplete(),
            'due_for_review' => $this->service->findDueForReview(),
            'awaiting_dpo' => $this->service->findAwaitingDPOConsultation(),
            'requires_supervisory' => $this->service->findRequiringSupervisoryConsultation(),
            default => $this->service->findAll(),
        };

        // Get statistics for dashboard
        $statistics = $this->service->getDashboardStatistics();
        $complianceScore = $this->service->calculateComplianceScore();

        return $this->render('dpia/index.html.twig', [
            'dpias' => $dpias,
            'statistics' => $statistics,
            'compliance_score' => $complianceScore,
            'current_filter' => $filter,
        ]);
    }

    /**
     * Create new DPIA
     */
    #[Route('/new', name: 'app_dpia_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $dpia = new DataProtectionImpactAssessment();
        $dpia->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(DataProtectionImpactAssessmentType::class, $dpia);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->create($dpia);

            $this->addFlash('success', $this->translator->trans('dpia.created'));
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()],Response::HTTP_SEE_OTHER);
        }

        return $this->render('dpia/new.html.twig', [
            'form' => $form,
            'dpia' => $dpia,
        ]);
    }

    /**
     * Edit DPIA
     */
    #[Route('/{id}/edit', name: 'app_dpia_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        // Only draft and requires_revision can be edited
        if (!in_array($dpia->getStatus(), ['draft', 'requires_revision'])) {
            $this->addFlash('warning', 'Only draft or revision-required DPIAs can be edited');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $form = $this->createForm(DataProtectionImpactAssessmentType::class, $dpia);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->update($dpia);

            $this->addFlash('success', $this->translator->trans('dpia.updated'));
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('dpia/edit.html.twig', [
            'form' => $form,
            'dpia' => $dpia,
        ]);
    }

    /**
     * Delete DPIA
     */
    #[Route('/{id}/delete', name: 'app_dpia_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if ($this->isCsrfTokenValid('delete' . $dpia->getId(), $request->request->get('_token'))) {
            $this->service->delete($dpia);

            $this->addFlash('success', $this->translator->trans('dpia.deleted'));
        }

        return $this->redirectToRoute('app_dpia_index');
    }

    // ============================================================================
    // Workflow Actions
    // ============================================================================

    /**
     * Submit DPIA for review (draft → in_review)
     */
    #[Route('/{id}/submit-for-review', name: 'app_dpia_submit_for_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submitForReview(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('submit' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        try {
            $this->service->submitForReview($dpia);
            $this->addFlash('success', $this->translator->trans('dpia.submitted_for_review'));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
    }

    /**
     * Approve DPIA (in_review → approved)
     */
    #[Route('/{id}/approve', name: 'app_dpia_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function approve(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('approve' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $comments = $request->request->get('approval_comments');

        try {
            $this->service->approve($dpia, $this->getUser(), $comments);
            $this->addFlash('success', $this->translator->trans('dpia.approved'));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
    }

    /**
     * Reject DPIA (in_review → rejected)
     */
    #[Route('/{id}/reject', name: 'app_dpia_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function reject(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('reject' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $reason = $request->request->get('rejection_reason');

        if (empty($reason)) {
            $this->addFlash('danger', 'Rejection reason is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        try {
            $this->service->reject($dpia, $this->getUser(), $reason);
            $this->addFlash('success', $this->translator->trans('dpia.rejected'));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
    }

    /**
     * Request revision (in_review/approved → requires_revision)
     */
    #[Route('/{id}/request-revision', name: 'app_dpia_request_revision', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function requestRevision(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('revision' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $reason = $request->request->get('revision_reason');

        if (empty($reason)) {
            $this->addFlash('danger', 'Revision reason is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        try {
            $this->service->requestRevision($dpia, $reason);
            $this->addFlash('success', $this->translator->trans('dpia.revision_requested'));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
    }

    /**
     * Reopen DPIA (requires_revision → draft)
     */
    #[Route('/{id}/reopen', name: 'app_dpia_reopen', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reopen(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('reopen' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        try {
            $this->service->reopen($dpia);
            $this->addFlash('success', $this->translator->trans('dpia.reopened'));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_edit', ['id' => $dpia->getId()]);
    }

    // ============================================================================
    // DPO & Supervisory Authority Consultation
    // ============================================================================

    /**
     * Record DPO consultation (Art. 35(4))
     */
    #[Route('/{id}/dpo-consultation', name: 'app_dpia_dpo_consultation', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function dpConsultation(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('dpo' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $advice = $request->request->get('dpo_advice');

        if (empty($advice)) {
            $this->addFlash('danger', 'DPO advice is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $this->service->recordDPOConsultation($dpia, $this->getUser(), $advice);
        $this->addFlash('success', $this->translator->trans('dpia.dpo_consulted'));

        return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
    }

    /**
     * Record supervisory authority consultation (Art. 36)
     */
    #[Route('/{id}/supervisory-consultation', name: 'app_dpia_supervisory_consultation', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function supervisoryConsultation(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('supervisory' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $feedback = $request->request->get('supervisory_feedback');

        if (empty($feedback)) {
            $this->addFlash('danger', 'Supervisory authority feedback is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $this->service->recordSupervisoryConsultation($dpia, $feedback);
        $this->addFlash('success', $this->translator->trans('dpia.supervisory_consulted'));

        return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
    }

    // ============================================================================
    // Review Management (Art. 35(11))
    // ============================================================================

    /**
     * Mark DPIA for review
     */
    #[Route('/{id}/mark-for-review', name: 'app_dpia_mark_for_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markForReview(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('review' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $reason = $request->request->get('review_reason');
        $dueDateStr = $request->request->get('review_due_date');

        if (empty($reason)) {
            $this->addFlash('danger', 'Review reason is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $dueDate = $dueDateStr ? new \DateTime($dueDateStr) : null;

        $this->service->markForReview($dpia, $reason, $dueDate);
        $this->addFlash('success', $this->translator->trans('dpia.marked_for_review'));

        return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
    }

    /**
     * Complete review (Art. 35(11))
     */
    #[Route('/{id}/complete-review', name: 'app_dpia_complete_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function completeReview(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('complete-review' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $this->service->completeReview($dpia);
        $this->addFlash('success', $this->translator->trans('dpia.review_completed'));

        return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
    }

    // ============================================================================
    // Clone DPIA
    // ============================================================================

    /**
     * Clone a DPIA
     */
    #[Route('/{id}/clone', name: 'app_dpia_clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function clone(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        if (!$this->isCsrfTokenValid('clone' . $dpia->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dpia->getId()]);
        }

        $newTitle = $dpia->getTitle() . ' (Copy)';
        $clone = $this->service->clone($dpia, $newTitle);

        $this->addFlash('success', $this->translator->trans('dpia.cloned'));
        return $this->redirectToRoute('app_dpia_edit', ['id' => $clone->getId()]);
    }

    // ============================================================================
    // Dashboard & Reporting
    // ============================================================================

    /**
     * DPIA Dashboard (statistics and compliance overview)
     */
    #[Route('/dashboard', name: 'app_dpia_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $statistics = $this->service->getDashboardStatistics();
        $complianceScore = $this->service->calculateComplianceScore();

        $highRisk = $this->service->findHighRisk();
        $incomplete = $this->service->findIncomplete();
        $dueForReview = $this->service->findDueForReview();
        $awaitingDPO = $this->service->findAwaitingDPOConsultation();
        $requiresSupervisory = $this->service->findRequiringSupervisoryConsultation();

        return $this->render('dpia/dashboard.html.twig', [
            'statistics' => $statistics,
            'compliance_score' => $complianceScore,
            'high_risk' => $highRisk,
            'incomplete' => $incomplete,
            'due_for_review' => $dueForReview,
            'awaiting_dpo' => $awaitingDPO,
            'requires_supervisory' => $requiresSupervisory,
        ]);
    }

    /**
     * Search DPIAs (AJAX endpoint)
     */
    #[Route('/search', name: 'app_dpia_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return $this->json(['results' => []]);
        }

        $results = $this->service->search($query);

        $formattedResults = array_map(function (DataProtectionImpactAssessment $dpia) {
            return [
                'id' => $dpia->getId(),
                'reference_number' => $dpia->getReferenceNumber(),
                'title' => $dpia->getTitle(),
                'status' => $dpia->getStatus(),
                'risk_level' => $dpia->getRiskLevel(),
                'completeness' => $dpia->getCompletenessPercentage(),
            ];
        }, $results);

        return $this->json(['results' => $formattedResults]);
    }

    /**
     * Show DPIA details
     */
    #[Route('/{id}', name: 'app_dpia_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(DataProtectionImpactAssessment $dpia): Response
    {
        $complianceReport = $this->service->generateComplianceReport($dpia);

        return $this->render('dpia/show.html.twig', [
            'dpia' => $dpia,
            'compliance_report' => $complianceReport,
        ]);
    }

    /**
     * Export DPIA as PDF (Art. 35 documentation)
     */
    #[Route('/{id}/export/pdf', name: 'app_dpia_export_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportPdf(Request $request, DataProtectionImpactAssessment $dpia): Response
    {
        $complianceReport = $this->service->generateComplianceReport($dpia);

        // Close session to prevent blocking
        $request->getSession()->save();

        // Generate version from last update date (Format: Year.Month.Day)
        $lastUpdate = $dpia->getUpdatedAt() ?? $dpia->getCreatedAt() ?? new \DateTime();
        $version = $lastUpdate->format('Y.m.d');

        $pdf = $this->pdfService->generatePdf('dpia/dpia_pdf.html.twig', [
            'dpia' => $dpia,
            'report' => $complianceReport,
            'version' => $version,
        ]);

        $filename = sprintf(
            'DPIA-%s-%s.pdf',
            $dpia->getReferenceNumber(),
            (new \DateTime())->format('Y-m-d')
        );

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => strlen($pdf),
        ]);
    }

    /**
     * Compliance report for a single DPIA (JSON API)
     */
    #[Route('/{id}/compliance-report', name: 'app_dpia_compliance_report', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function complianceReport(DataProtectionImpactAssessment $dpia): Response
    {
        $report = $this->service->generateComplianceReport($dpia);

        return $this->json($report);
    }

    /**
     * Validate DPIA (AJAX endpoint)
     */
    #[Route('/{id}/validate', name: 'app_dpia_validate', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function validate(DataProtectionImpactAssessment $dpia): Response
    {
        $errors = $this->service->validate($dpia);
        $isCompliant = empty($errors);

        return $this->json([
            'is_compliant' => $isCompliant,
            'errors' => $errors,
            'completeness_percentage' => $dpia->getCompletenessPercentage(),
            'is_complete' => $dpia->isComplete(),
        ]);
    }
}
