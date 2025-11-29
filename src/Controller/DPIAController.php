<?php

namespace App\Controller;

use RuntimeException;
use DateTime;
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

#[IsGranted('ROLE_USER')]
class DPIAController extends AbstractController
{
    public function __construct(
        private readonly DataProtectionImpactAssessmentService $dataProtectionImpactAssessmentService,
        private readonly PdfExportService $pdfExportService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * List all DPIAs (index view)
     */
    #[Route('/dpia/', name: 'app_dpia_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $filter = $request->query->get('filter', 'all');

        $dpias = match ($filter) {
            'draft' => $this->dataProtectionImpactAssessmentService->findDrafts(),
            'in_review' => $this->dataProtectionImpactAssessmentService->findInReview(),
            'approved' => $this->dataProtectionImpactAssessmentService->findApproved(),
            'requires_revision' => $this->dataProtectionImpactAssessmentService->findRequiringRevision(),
            'high_risk' => $this->dataProtectionImpactAssessmentService->findHighRisk(),
            'incomplete' => $this->dataProtectionImpactAssessmentService->findIncomplete(),
            'due_for_review' => $this->dataProtectionImpactAssessmentService->findDueForReview(),
            'awaiting_dpo' => $this->dataProtectionImpactAssessmentService->findAwaitingDPOConsultation(),
            'requires_supervisory' => $this->dataProtectionImpactAssessmentService->findRequiringSupervisoryConsultation(),
            default => $this->dataProtectionImpactAssessmentService->findAll(),
        };

        // Get statistics for dashboard
        $statistics = $this->dataProtectionImpactAssessmentService->getDashboardStatistics();
        $complianceScore = $this->dataProtectionImpactAssessmentService->calculateComplianceScore();

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
    #[Route('/dpia/new', name: 'app_dpia_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $dataProtectionImpactAssessment = new DataProtectionImpactAssessment();
        $dataProtectionImpactAssessment->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(DataProtectionImpactAssessmentType::class, $dataProtectionImpactAssessment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->dataProtectionImpactAssessmentService->create($dataProtectionImpactAssessment);

            $this->addFlash('success', $this->translator->trans('dpia.created'));
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()],Response::HTTP_SEE_OTHER);
        }

        return $this->render('dpia/new.html.twig', [
            'form' => $form,
            'dpia' => $dataProtectionImpactAssessment,
        ]);
    }

    /**
     * Edit DPIA
     */
    #[Route('/dpia/{id}/edit', name: 'app_dpia_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        // Only draft and requires_revision can be edited
        if (!in_array($dataProtectionImpactAssessment->getStatus(), ['draft', 'requires_revision'])) {
            $this->addFlash('warning', 'Only draft or revision-required DPIAs can be edited');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $form = $this->createForm(DataProtectionImpactAssessmentType::class, $dataProtectionImpactAssessment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->dataProtectionImpactAssessmentService->update($dataProtectionImpactAssessment);

            $this->addFlash('success', $this->translator->trans('dpia.updated'));
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('dpia/edit.html.twig', [
            'form' => $form,
            'dpia' => $dataProtectionImpactAssessment,
        ]);
    }

    /**
     * Delete DPIA
     */
    #[Route('/dpia/{id}/delete', name: 'app_dpia_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if ($this->isCsrfTokenValid('delete' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->dataProtectionImpactAssessmentService->delete($dataProtectionImpactAssessment);

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
    #[Route('/dpia/{id}/submit-for-review', name: 'app_dpia_submit_for_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submitForReview(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('submit' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        try {
            $this->dataProtectionImpactAssessmentService->submitForReview($dataProtectionImpactAssessment);
            $this->addFlash('success', $this->translator->trans('dpia.submitted_for_review'));
        } catch (RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
    }

    /**
     * Approve DPIA (in_review → approved)
     */
    #[Route('/dpia/{id}/approve', name: 'app_dpia_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function approve(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('approve' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $comments = $request->request->get('approval_comments');

        try {
            $this->dataProtectionImpactAssessmentService->approve($dataProtectionImpactAssessment, $this->getUser(), $comments);
            $this->addFlash('success', $this->translator->trans('dpia.approved'));
        } catch (RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
    }

    /**
     * Reject DPIA (in_review → rejected)
     */
    #[Route('/dpia/{id}/reject', name: 'app_dpia_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function reject(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('reject' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $reason = $request->request->get('rejection_reason');

        if (empty($reason)) {
            $this->addFlash('danger', 'Rejection reason is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        try {
            $this->dataProtectionImpactAssessmentService->reject($dataProtectionImpactAssessment, $this->getUser(), $reason);
            $this->addFlash('success', $this->translator->trans('dpia.rejected'));
        } catch (RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
    }

    /**
     * Request revision (in_review/approved → requires_revision)
     */
    #[Route('/dpia/{id}/request-revision', name: 'app_dpia_request_revision', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function requestRevision(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('revision' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $reason = $request->request->get('revision_reason');

        if (empty($reason)) {
            $this->addFlash('danger', 'Revision reason is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        try {
            $this->dataProtectionImpactAssessmentService->requestRevision($dataProtectionImpactAssessment, $reason);
            $this->addFlash('success', $this->translator->trans('dpia.revision_requested'));
        } catch (RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
    }

    /**
     * Reopen DPIA (requires_revision → draft)
     */
    #[Route('/dpia/{id}/reopen', name: 'app_dpia_reopen', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reopen(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('reopen' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        try {
            $this->dataProtectionImpactAssessmentService->reopen($dataProtectionImpactAssessment);
            $this->addFlash('success', $this->translator->trans('dpia.reopened'));
        } catch (RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_dpia_edit', ['id' => $dataProtectionImpactAssessment->getId()]);
    }

    // ============================================================================
    // DPO & Supervisory Authority Consultation
    // ============================================================================

    /**
     * Record DPO consultation (Art. 35(4))
     */
    #[Route('/dpia/{id}/dpo-consultation', name: 'app_dpia_dpo_consultation', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function dpConsultation(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('dpo' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $advice = $request->request->get('dpo_advice');

        if (empty($advice)) {
            $this->addFlash('danger', 'DPO advice is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $this->dataProtectionImpactAssessmentService->recordDPOConsultation($dataProtectionImpactAssessment, $this->getUser(), $advice);
        $this->addFlash('success', $this->translator->trans('dpia.dpo_consulted'));

        return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
    }

    /**
     * Record supervisory authority consultation (Art. 36)
     */
    #[Route('/dpia/{id}/supervisory-consultation', name: 'app_dpia_supervisory_consultation', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function supervisoryConsultation(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('supervisory' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $feedback = $request->request->get('supervisory_feedback');

        if (empty($feedback)) {
            $this->addFlash('danger', 'Supervisory authority feedback is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $this->dataProtectionImpactAssessmentService->recordSupervisoryConsultation($dataProtectionImpactAssessment, $feedback);
        $this->addFlash('success', $this->translator->trans('dpia.supervisory_consulted'));

        return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
    }

    // ============================================================================
    // Review Management (Art. 35(11))
    // ============================================================================

    /**
     * Mark DPIA for review
     */
    #[Route('/dpia/{id}/mark-for-review', name: 'app_dpia_mark_for_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markForReview(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('review' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $reason = $request->request->get('review_reason');
        $dueDateStr = $request->request->get('review_due_date');

        if (empty($reason)) {
            $this->addFlash('danger', 'Review reason is required');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $dueDate = $dueDateStr ? new DateTime($dueDateStr) : null;

        $this->dataProtectionImpactAssessmentService->markForReview($dataProtectionImpactAssessment, $reason, $dueDate);
        $this->addFlash('success', $this->translator->trans('dpia.marked_for_review'));

        return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
    }

    /**
     * Complete review (Art. 35(11))
     */
    #[Route('/dpia/{id}/complete-review', name: 'app_dpia_complete_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function completeReview(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('complete-review' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $this->dataProtectionImpactAssessmentService->completeReview($dataProtectionImpactAssessment);
        $this->addFlash('success', $this->translator->trans('dpia.review_completed'));

        return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
    }

    // ============================================================================
    // Clone DPIA
    // ============================================================================

    /**
     * Clone a DPIA
     */
    #[Route('/dpia/{id}/clone', name: 'app_dpia_clone', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function clone(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        if (!$this->isCsrfTokenValid('clone' . $dataProtectionImpactAssessment->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_dpia_show', ['id' => $dataProtectionImpactAssessment->getId()]);
        }

        $newTitle = $dataProtectionImpactAssessment->getTitle() . ' (Copy)';
        $clone = $this->dataProtectionImpactAssessmentService->clone($dataProtectionImpactAssessment, $newTitle);

        $this->addFlash('success', $this->translator->trans('dpia.cloned'));
        return $this->redirectToRoute('app_dpia_edit', ['id' => $clone->getId()]);
    }

    // ============================================================================
    // Dashboard & Reporting
    // ============================================================================

    /**
     * DPIA Dashboard (statistics and compliance overview)
     */
    #[Route('/dpia/dashboard', name: 'app_dpia_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $statistics = $this->dataProtectionImpactAssessmentService->getDashboardStatistics();
        $complianceScore = $this->dataProtectionImpactAssessmentService->calculateComplianceScore();

        $highRisk = $this->dataProtectionImpactAssessmentService->findHighRisk();
        $incomplete = $this->dataProtectionImpactAssessmentService->findIncomplete();
        $dueForReview = $this->dataProtectionImpactAssessmentService->findDueForReview();
        $awaitingDPO = $this->dataProtectionImpactAssessmentService->findAwaitingDPOConsultation();
        $requiresSupervisory = $this->dataProtectionImpactAssessmentService->findRequiringSupervisoryConsultation();

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
    #[Route('/dpia/search', name: 'app_dpia_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return $this->json(['results' => []]);
        }

        $results = $this->dataProtectionImpactAssessmentService->search($query);

        $formattedResults = array_map(fn(DataProtectionImpactAssessment $dataProtectionImpactAssessment): array => [
            'id' => $dataProtectionImpactAssessment->getId(),
            'reference_number' => $dataProtectionImpactAssessment->getReferenceNumber(),
            'title' => $dataProtectionImpactAssessment->getTitle(),
            'status' => $dataProtectionImpactAssessment->getStatus(),
            'risk_level' => $dataProtectionImpactAssessment->getRiskLevel(),
            'completeness' => $dataProtectionImpactAssessment->getCompletenessPercentage(),
        ], $results);

        return $this->json(['results' => $formattedResults]);
    }

    /**
     * Show DPIA details
     */
    #[Route('/dpia/{id}', name: 'app_dpia_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        $complianceReport = $this->dataProtectionImpactAssessmentService->generateComplianceReport($dataProtectionImpactAssessment);

        return $this->render('dpia/show.html.twig', [
            'dpia' => $dataProtectionImpactAssessment,
            'compliance_report' => $complianceReport,
        ]);
    }

    /**
     * Export DPIA as PDF (Art. 35 documentation)
     */
    #[Route('/dpia/{id}/export/pdf', name: 'app_dpia_export_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportPdf(Request $request, DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        $complianceReport = $this->dataProtectionImpactAssessmentService->generateComplianceReport($dataProtectionImpactAssessment);

        // Close session to prevent blocking
        $request->getSession()->save();

        // Generate version from last update date (Format: Year.Month.Day)
        $lastUpdate = $dataProtectionImpactAssessment->getUpdatedAt() ?? $dataProtectionImpactAssessment->getCreatedAt() ?? new DateTime();
        $version = $lastUpdate->format('Y.m.d');

        $pdf = $this->pdfExportService->generatePdf('dpia/dpia_pdf.html.twig', [
            'dpia' => $dataProtectionImpactAssessment,
            'report' => $complianceReport,
            'version' => $version,
        ]);

        $filename = sprintf(
            'DPIA-%s-%s.pdf',
            $dataProtectionImpactAssessment->getReferenceNumber(),
            new DateTime()->format('Y-m-d')
        );

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => strlen($pdf),
        ]);
    }

    /**
     * Compliance report for a single DPIA (JSON API)
     */
    #[Route('/dpia/{id}/compliance-report', name: 'app_dpia_compliance_report', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function complianceReport(DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        $report = $this->dataProtectionImpactAssessmentService->generateComplianceReport($dataProtectionImpactAssessment);

        return $this->json($report);
    }

    /**
     * Validate DPIA (AJAX endpoint)
     */
    #[Route('/dpia/{id}/validate', name: 'app_dpia_validate', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function validate(DataProtectionImpactAssessment $dataProtectionImpactAssessment): Response
    {
        $errors = $this->dataProtectionImpactAssessmentService->validate($dataProtectionImpactAssessment);
        $isCompliant = $errors === [];

        return $this->json([
            'is_compliant' => $isCompliant,
            'errors' => $errors,
            'completeness_percentage' => $dataProtectionImpactAssessment->getCompletenessPercentage(),
            'is_complete' => $dataProtectionImpactAssessment->isComplete(),
        ]);
    }
}
