<?php

namespace App\Controller;

use App\Entity\ProcessingActivity;
use App\Form\ProcessingActivityType;
use App\Service\ProcessingActivityService;
use App\Service\PdfExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/processing-activity')]
#[IsGranted('ROLE_USER')]
class ProcessingActivityController extends AbstractController
{
    public function __construct(
        private ProcessingActivityService $service,
        private PdfExportService $pdfService,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator
    ) {}

    /**
     * List all processing activities (VVT index)
     */
    #[Route('/', name: 'app_processing_activity_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $filter = $request->query->get('filter', 'all');

        $processingActivities = match ($filter) {
            'active' => $this->service->findActive(),
            'incomplete' => $this->service->findIncomplete(),
            'requiring_dpia' => $this->service->findRequiringDPIA(),
            'due_for_review' => $this->service->findDueForReview(),
            'special_categories' => $this->service->findProcessingSpecialCategories(),
            'third_country_transfers' => $this->service->findWithThirdCountryTransfers(),
            default => $this->service->findAll(),
        };

        // Get statistics for dashboard
        $statistics = $this->service->getDashboardStatistics();
        $complianceScore = $this->service->calculateComplianceScore();

        return $this->render('processing_activity/index.html.twig', [
            'processing_activities' => $processingActivities,
            'statistics' => $statistics,
            'compliance_score' => $complianceScore,
            'current_filter' => $filter,
        ]);
    }

    /**
     * Show processing activity details
     */
    #[Route('/{id}', name: 'app_processing_activity_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(ProcessingActivity $processingActivity): Response
    {
        $complianceReport = $this->service->generateComplianceReport($processingActivity);

        return $this->render('processing_activity/show.html.twig', [
            'processing_activity' => $processingActivity,
            'compliance_report' => $complianceReport,
        ]);
    }

    /**
     * Create new processing activity
     */
    #[Route('/new', name: 'app_processing_activity_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $processingActivity = new ProcessingActivity();
        $form = $this->createForm(ProcessingActivityType::class, $processingActivity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->create($processingActivity);

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
    #[Route('/{id}/edit', name: 'app_processing_activity_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ProcessingActivity $processingActivity): Response
    {
        $form = $this->createForm(ProcessingActivityType::class, $processingActivity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->update($processingActivity);

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
    #[Route('/{id}/delete', name: 'app_processing_activity_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($this->isCsrfTokenValid('delete' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->service->delete($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.deleted'));
        }

        return $this->redirectToRoute('app_processing_activity_index');
    }

    /**
     * Activate a draft processing activity
     */
    #[Route('/{id}/activate', name: 'app_processing_activity_activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(Request $request, ProcessingActivity $processingActivity): Response
    {
        if (!$this->isCsrfTokenValid('activate' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
        }

        try {
            $this->service->activate($processingActivity);
            $this->addFlash('success', $this->translator->trans('processing_activity.activated'));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
    }

    /**
     * Archive a processing activity
     */
    #[Route('/{id}/archive', name: 'app_processing_activity_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($this->isCsrfTokenValid('archive' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->service->archive($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.archived'));
        }

        return $this->redirectToRoute('app_processing_activity_index');
    }

    /**
     * Mark for review
     */
    #[Route('/{id}/mark-for-review', name: 'app_processing_activity_mark_for_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markForReview(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($this->isCsrfTokenValid('review' . $processingActivity->getId(), $request->request->get('_token'))) {
            $reviewDateStr = $request->request->get('review_date');
            $reviewDate = $reviewDateStr ? new \DateTime($reviewDateStr) : null;

            $this->service->markForReview($processingActivity, $reviewDate);

            $this->addFlash('success', $this->translator->trans('processing_activity.marked_for_review'));
        }

        return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
    }

    /**
     * Complete review
     */
    #[Route('/{id}/complete-review', name: 'app_processing_activity_complete_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function completeReview(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($this->isCsrfTokenValid('complete-review' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->service->completeReview($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.review_completed'));
        }

        return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
    }

    /**
     * Clone a processing activity
     */
    #[Route('/{id}/clone', name: 'app_processing_activity_clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function clone(Request $request, ProcessingActivity $processingActivity): Response
    {
        if (!$this->isCsrfTokenValid('clone' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
        }

        $newName = $processingActivity->getName() . ' (Copy)';
        $clone = $this->service->clone($processingActivity, $newName);

        $this->addFlash('success', $this->translator->trans('processing_activity.cloned'));
        return $this->redirectToRoute('app_processing_activity_edit', ['id' => $clone->getId()]);
    }

    /**
     * Dashboard (statistics and compliance overview)
     */
    #[Route('/dashboard', name: 'app_processing_activity_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $statistics = $this->service->getDashboardStatistics();
        $complianceScore = $this->service->calculateComplianceScore();

        $requiringDPIA = $this->service->findRequiringDPIA();
        $incomplete = $this->service->findIncomplete();
        $dueForReview = $this->service->findDueForReview();

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
    #[Route('/export/pdf', name: 'app_processing_activity_export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        $exportData = $this->service->generateVVTExport();

        // Close session to prevent blocking
        $request->getSession()->save();

        $html = $this->renderView('processing_activity/vvt_pdf.html.twig', [
            'export' => $exportData,
        ]);

        $filename = sprintf(
            'VVT-Verzeichnis-Verarbeitungstaetigkeiten-%s.pdf',
            (new \DateTime())->format('Y-m-d')
        );

        return $this->pdfService->generatePdfResponse($html, $filename);
    }

    /**
     * Export VVT as CSV
     */
    #[Route('/export/csv', name: 'app_processing_activity_export_csv', methods: ['GET'])]
    public function exportCsv(): Response
    {
        $exportData = $this->service->generateVVTExport();

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
            (new \DateTime())->format('Y-m-d')
        ));

        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $response->setContent(stream_get_contents($output));
        fclose($output);

        return $response;
    }

    /**
     * Compliance report for a single processing activity (JSON API)
     */
    #[Route('/{id}/compliance-report', name: 'app_processing_activity_compliance_report', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function complianceReport(ProcessingActivity $processingActivity): Response
    {
        $report = $this->service->generateComplianceReport($processingActivity);

        return $this->json($report);
    }

    /**
     * Search processing activities (AJAX endpoint)
     */
    #[Route('/search', name: 'app_processing_activity_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return $this->json(['results' => []]);
        }

        $results = $this->service->search($query);

        $formattedResults = array_map(function (ProcessingActivity $pa) {
            return [
                'id' => $pa->getId(),
                'name' => $pa->getName(),
                'status' => $pa->getStatus(),
                'legal_basis' => $pa->getLegalBasis(),
                'completeness' => $pa->getCompletenessPercentage(),
            ];
        }, $results);

        return $this->json(['results' => $formattedResults]);
    }

    /**
     * Bulk delete processing activities
     */
    #[Route('/bulk-delete', name: 'app_processing_activity_bulk_delete', methods: ['POST'])]
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

                if (!$pa) {
                    $errors[] = "Processing Activity ID $id not found";
                    continue;
                }

                $this->service->delete($pa);
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = "Error deleting ID $id: " . $e->getMessage();
            }
        }

        if (!empty($errors)) {
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
     * Validate processing activity (AJAX endpoint)
     */
    #[Route('/{id}/validate', name: 'app_processing_activity_validate', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function validate(ProcessingActivity $processingActivity): Response
    {
        $errors = $this->service->validate($processingActivity);
        $isCompliant = empty($errors);

        return $this->json([
            'is_compliant' => $isCompliant,
            'errors' => $errors,
            'completeness_percentage' => $processingActivity->getCompletenessPercentage(),
        ]);
    }
}
