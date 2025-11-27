<?php

namespace App\Controller;

use RuntimeException;
use DateTime;
use Exception;
use App\Entity\ProcessingActivity;
use App\Form\ProcessingActivityType;
use App\Service\ProcessingActivityService;
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
class ProcessingActivityController extends AbstractController
{
    public function __construct(
        private readonly ProcessingActivityService $processingActivityService,
        private readonly PdfExportService $pdfExportService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * List all processing activities (VVT index)
     */
    #[Route('/processing-activity/', name: 'app_processing_activity_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $filter = $request->query->get('filter', 'all');

        $processingActivities = match ($filter) {
            'active' => $this->processingActivityService->findActive(),
            'incomplete' => $this->processingActivityService->findIncomplete(),
            'requiring_dpia' => $this->processingActivityService->findRequiringDPIA(),
            'due_for_review' => $this->processingActivityService->findDueForReview(),
            'special_categories' => $this->processingActivityService->findProcessingSpecialCategories(),
            'third_country_transfers' => $this->processingActivityService->findWithThirdCountryTransfers(),
            default => $this->processingActivityService->findAll(),
        };

        // Get statistics for dashboard
        $statistics = $this->processingActivityService->getDashboardStatistics();
        $complianceScore = $this->processingActivityService->calculateComplianceScore();

        return $this->render('processing_activity/index.html.twig', [
            'processing_activities' => $processingActivities,
            'statistics' => $statistics,
            'compliance_score' => $complianceScore,
            'current_filter' => $filter,
        ]);
    }

    /**
     * Create new processing activity
     */
    #[Route('/processing-activity/new', name: 'app_processing_activity_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $processingActivity = new ProcessingActivity();
        $processingActivity->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(ProcessingActivityType::class, $processingActivity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->processingActivityService->create($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.created'));
            return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
        }

        return $this->render('processing_activity/new.html.twig', [
            'form' => $form,
            'processing_activity' => $processingActivity,
        ]);
    }

    /**
     * Edit processing activity
     */
    #[Route('/processing-activity/{id}/edit', name: 'app_processing_activity_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ProcessingActivity $processingActivity): Response
    {
        $form = $this->createForm(ProcessingActivityType::class, $processingActivity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->processingActivityService->update($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.updated'));
            return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
        }

        return $this->render('processing_activity/edit.html.twig', [
            'form' => $form,
            'processing_activity' => $processingActivity,
        ]);
    }

    /**
     * Delete processing activity
     */
    #[Route('/processing-activity/{id}/delete', name: 'app_processing_activity_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($this->isCsrfTokenValid('delete' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->processingActivityService->delete($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.deleted'));
        }

        return $this->redirectToRoute('app_processing_activity_index');
    }

    /**
     * Activate a draft processing activity
     */
    #[Route('/processing-activity/{id}/activate', name: 'app_processing_activity_activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(Request $request, ProcessingActivity $processingActivity): Response
    {
        if (!$this->isCsrfTokenValid('activate' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
        }

        try {
            $this->processingActivityService->activate($processingActivity);
            $this->addFlash('success', $this->translator->trans('processing_activity.activated'));
        } catch (RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
    }

    /**
     * Archive a processing activity
     */
    #[Route('/processing-activity/{id}/archive', name: 'app_processing_activity_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($this->isCsrfTokenValid('archive' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->processingActivityService->archive($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.archived'));
        }

        return $this->redirectToRoute('app_processing_activity_index');
    }

    /**
     * Mark for review
     */
    #[Route('/processing-activity/{id}/mark-for-review', name: 'app_processing_activity_mark_for_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markForReview(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($this->isCsrfTokenValid('review' . $processingActivity->getId(), $request->request->get('_token'))) {
            $reviewDateStr = $request->request->get('review_date');
            $reviewDate = $reviewDateStr ? new DateTime($reviewDateStr) : null;

            $this->processingActivityService->markForReview($processingActivity, $reviewDate);

            $this->addFlash('success', $this->translator->trans('processing_activity.marked_for_review'));
        }

        return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
    }

    /**
     * Complete review
     */
    #[Route('/processing-activity/{id}/complete-review', name: 'app_processing_activity_complete_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function completeReview(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($this->isCsrfTokenValid('complete-review' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->processingActivityService->completeReview($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.review_completed'));
        }

        return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
    }

    /**
     * Clone a processing activity
     */
    #[Route('/processing-activity/{id}/clone', name: 'app_processing_activity_clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function clone(Request $request, ProcessingActivity $processingActivity): Response
    {
        if (!$this->isCsrfTokenValid('clone' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
        }

        $newName = $processingActivity->getName() . ' (Copy)';
        $clone = $this->processingActivityService->clone($processingActivity, $newName);

        $this->addFlash('success', $this->translator->trans('processing_activity.cloned'));
        return $this->redirectToRoute('app_processing_activity_edit', ['id' => $clone->getId()]);
    }

    /**
     * Dashboard (statistics and compliance overview)
     */
    #[Route('/processing-activity/dashboard', name: 'app_processing_activity_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $statistics = $this->processingActivityService->getDashboardStatistics();
        $complianceScore = $this->processingActivityService->calculateComplianceScore();

        $requiringDPIA = $this->processingActivityService->findRequiringDPIA();
        $incomplete = $this->processingActivityService->findIncomplete();
        $dueForReview = $this->processingActivityService->findDueForReview();

        return $this->render('processing_activity/dashboard.html.twig', [
            'statistics' => $statistics,
            'compliance_score' => $complianceScore,
            'requiring_dpia' => $requiringDPIA,
            'incomplete' => $incomplete,
            'due_for_review' => $dueForReview,
        ]);
    }

    /**
     * Export VVT as PDF (Art. 30 documentation)
     */
    #[Route('/processing-activity/export/pdf', name: 'app_processing_activity_export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        $exportData = $this->processingActivityService->generateVVTExport();

        // Close session to prevent blocking
        $request->getSession()->save();

        // Generate version from export date (Format: Year.Month.Day)
        $version = $exportData['generated_at']->format('Y.m.d');

        $pdf = $this->pdfExportService->generatePdf('processing_activity/vvt_pdf.html.twig', [
            'export' => $exportData,
            'version' => $version,
        ]);

        $filename = sprintf(
            'VVT-Verzeichnis-Verarbeitungstaetigkeiten-%s.pdf',
            new DateTime()->format('Y-m-d')
        );

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => strlen((string) $pdf),
        ]);
    }

    /**
     * Export VVT as CSV
     */
    #[Route('/processing-activity/export/csv', name: 'app_processing_activity_export_csv', methods: ['GET'])]
    public function exportCsv(): Response
    {
        $exportData = $this->processingActivityService->generateVVTExport();

        $csv = [];
        $csv[] = [
            'ID',
            'Name',
            'Description',
            'Purposes',
            'Data Subjects',
            'Personal Data Categories',
            'Special Categories',
            'Recipients',
            'Third Country Transfer',
            'Retention Period',
            'Legal Basis',
            'TOMs',
            'Status',
            'Risk Level',
            'DPIA Required',
            'DPIA Completed',
            'Completeness %',
        ];

        foreach ($exportData['processing_activities'] as $pa) {
            $csv[] = [
                $pa['id'],
                $pa['name'],
                $pa['description'] ?? '',
                implode(', ', $pa['purposes']),
                implode(', ', $pa['data_subject_categories']),
                implode(', ', $pa['personal_data_categories']),
                $pa['processes_special_categories'] ? 'Yes' : 'No',
                implode(', ', $pa['recipient_categories'] ?? []),
                $pa['has_third_country_transfer'] ? 'Yes' : 'No',
                $pa['retention_period'] ?? '',
                $pa['legal_basis'] ?? '',
                substr($pa['technical_organizational_measures'] ?? '', 0, 100),
                $pa['status'],
                $pa['risk_level'] ?? '',
                $pa['requires_dpia'] ? 'Yes' : 'No',
                $pa['dpia_completed'] ? 'Yes' : 'No',
                $pa['completeness_percentage'] . '%',
            ];
        }

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="VVT-Export-%s.csv"',
            new DateTime()->format('Y-m-d')
        ));

        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row, escape: '\\');
        }
        rewind($output);
        $response->setContent(stream_get_contents($output));
        fclose($output);

        return $response;
    }

    /**
     * Search processing activities (AJAX endpoint)
     */
    #[Route('/processing-activity/search', name: 'app_processing_activity_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return $this->json(['results' => []]);
        }

        $results = $this->processingActivityService->search($query);

        $formattedResults = array_map(fn(ProcessingActivity $processingActivity): array => [
            'id' => $processingActivity->getId(),
            'name' => $processingActivity->getName(),
            'status' => $processingActivity->getStatus(),
            'legal_basis' => $processingActivity->getLegalBasis(),
            'completeness' => $processingActivity->getCompletenessPercentage(),
        ], $results);

        return $this->json(['results' => $formattedResults]);
    }

    /**
     * Bulk delete processing activities
     */
    #[Route('/processing-activity/bulk-delete', name: 'app_processing_activity_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDelete(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $pa = $this->entityManager->getRepository(ProcessingActivity::class)->find($id);

                if (!$pa instanceof ProcessingActivity) {
                    $errors[] = "Processing Activity ID $id not found";
                    continue;
                }

                $this->processingActivityService->delete($pa);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting ID $id: " . $e->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->json([
                'success' => $deleted > 0,
                'deleted' => $deleted,
                'errors' => $errors,
            ], $deleted > 0 ? 200 : 400);
        }

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => "$deleted processing activities deleted successfully",
        ]);
    }

    /**
     * Show processing activity details
     */
    #[Route('/processing-activity/{id}', name: 'app_processing_activity_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(ProcessingActivity $processingActivity): Response
    {
        $complianceReport = $this->processingActivityService->generateComplianceReport($processingActivity);

        return $this->render('processing_activity/show.html.twig', [
            'processing_activity' => $processingActivity,
            'compliance_report' => $complianceReport,
        ]);
    }

    /**
     * Compliance report for a single processing activity (JSON API)
     */
    #[Route('/processing-activity/{id}/compliance-report', name: 'app_processing_activity_compliance_report', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function complianceReport(ProcessingActivity $processingActivity): Response
    {
        $report = $this->processingActivityService->generateComplianceReport($processingActivity);

        return $this->json($report);
    }

    /**
     * Validate processing activity (AJAX endpoint)
     */
    #[Route('/processing-activity/{id}/validate', name: 'app_processing_activity_validate', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function validate(ProcessingActivity $processingActivity): Response
    {
        $errors = $this->processingActivityService->validate($processingActivity);
        $isCompliant = empty($errors);

        return $this->json([
            'is_compliant' => $isCompliant,
            'errors' => $errors,
            'completeness_percentage' => $processingActivity->getCompletenessPercentage(),
        ]);
    }
}
