<?php

declare(strict_types=1);

namespace App\Controller;

use RuntimeException;
use DateTime;
use Exception;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\ProcessingActivity;
use App\Entity\Supplier;
use App\Form\ProcessingActivityType;
use App\Service\ModuleConfigurationService;
use App\Service\PreFiller\AvvPickerPreFiller;
use App\Service\ProcessingActivityService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class ProcessingActivityController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly ProcessingActivityService $processingActivityService,
        private readonly PdfExportService $pdfExportService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly ?AvvPickerPreFiller $avvPickerPreFiller = null,
    ) {}

    /**
     * List all processing activities (VVT index)
     */
    #[Route('/processing-activity', name: 'app_processing_activity_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

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
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        $processingActivity = new ProcessingActivity();
        $processingActivity->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(ProcessingActivityType::class, $processingActivity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->processingActivityService->create($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.created', [], 'messages'));
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
    #[Route('/processing-activity/{id}/edit', name: 'app_processing_activity_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        $form = $this->createForm(ProcessingActivityType::class, $processingActivity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->processingActivityService->update($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.updated', [], 'messages'));
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
    #[Route('/processing-activity/{id}/delete', name: 'app_processing_activity_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        if ($this->isCsrfTokenValid('delete' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->processingActivityService->delete($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.deleted', [], 'messages'));
        }

        return $this->redirectToRoute('app_processing_activity_index');
    }

    /**
     * Activate a draft processing activity
     */
    #[Route('/processing-activity/{id}/activate', name: 'app_processing_activity_activate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function activate(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        if (!$this->isCsrfTokenValid('activate' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
        }

        try {
            $this->processingActivityService->activate($processingActivity);
            $this->addFlash('success', $this->translator->trans('processing_activity.activated', [], 'messages'));
        } catch (RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
    }

    /**
     * Archive a processing activity
     */
    #[Route('/processing-activity/{id}/archive', name: 'app_processing_activity_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        if ($this->isCsrfTokenValid('archive' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->processingActivityService->archive($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.archived', [], 'messages'));
        }

        return $this->redirectToRoute('app_processing_activity_index');
    }

    /**
     * Mark for review
     */
    #[Route('/processing-activity/{id}/mark-for-review', name: 'app_processing_activity_mark_for_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markForReview(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        if ($this->isCsrfTokenValid('review' . $processingActivity->getId(), $request->request->get('_token'))) {
            $reviewDateStr = $request->request->get('review_date');
            $reviewDate = $reviewDateStr ? new DateTime($reviewDateStr) : null;

            $this->processingActivityService->markForReview($processingActivity, $reviewDate);

            $this->addFlash('success', $this->translator->trans('processing_activity.marked_for_review', [], 'messages'));
        }

        return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
    }

    /**
     * Complete review
     */
    #[Route('/processing-activity/{id}/complete-review', name: 'app_processing_activity_complete_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function completeReview(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        if ($this->isCsrfTokenValid('complete-review' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->processingActivityService->completeReview($processingActivity);

            $this->addFlash('success', $this->translator->trans('processing_activity.review_completed', [], 'messages'));
        }

        return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
    }

    /**
     * Clone a processing activity
     */
    #[Route('/processing-activity/{id}/clone', name: 'app_processing_activity_clone', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function clone(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        if (!$this->isCsrfTokenValid('clone' . $processingActivity->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
        }

        $newName = $processingActivity->getName() . ' (Copy)';
        $clone = $this->processingActivityService->clone($processingActivity, $newName);

        $this->addFlash('success', $this->translator->trans('processing_activity.cloned', [], 'messages'));
        return $this->redirectToRoute('app_processing_activity_edit', ['id' => $clone->getId()]);
    }

    /**
     * Dashboard (statistics and compliance overview)
     */
    #[Route('/processing-activity/dashboard', name: 'app_processing_activity_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

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
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

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
            'Content-Length' => (string) strlen($pdf),
        ]);
    }

    /**
     * Async wrapper around {@see self::exportCsv()}: dispatches an
     * {@see \App\Job\ExportProcessingActivityVvtJob} that writes the VVT
     * CSV to var/exports/<jobId>.csv in the background and renders a
     * polling progress page.
     *
     * Phase 3 of the async admin-jobs rollout.
     */
    #[Route('/processing-activity/export/csv/dispatch', name: 'app_processing_activity_export_csv_dispatch', methods: ['POST'])]
    #[IsCsrfTokenValid('processing_activity_export_csv_dispatch')]
    public function exportCsvDispatch(
        \App\Service\Job\JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
    ): Response {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        $jobId = $jobStatusService->create('processing_activity.vvt_export', [
            '_label' => $this->translator->trans('processing_activity.export.progress_title', [], 'compliance'),
            '_subtitle' => $this->translator->trans('processing_activity.export.progress_subtitle', [], 'compliance'),
            '_download_label' => $this->translator->trans('processing_activity.export.download_button', [], 'compliance'),
        ]);
        $jobStatusService->updatePayload($jobId, [
            '_download_url' => $this->generateUrl('app_processing_activity_export_csv_download', ['id' => $jobId]),
        ]);

        $messageBus->dispatch(new \App\Message\Job\ExecuteJobMessage(
            jobClass: \App\Job\ExportProcessingActivityVvtJob::class,
            args: [],
            jobId: $jobId,
        ));

        // PRG: 303 redirect — see DataRepairController::runIntegrityCheck() for rationale.
        return $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('app_processing_activity_index'),
        ], Response::HTTP_SEE_OTHER);
    }

    /**
     * Streams the file produced by {@see \App\Job\ExportProcessingActivityVvtJob}
     * and removes it from disk afterwards.
     */
    #[Route('/processing-activity/export/csv/download/{id}', name: 'app_processing_activity_export_csv_download', methods: ['GET'])]
    public function exportCsvDownload(
        string $id,
        \App\Service\Job\JobStatusService $jobStatusService,
        KernelInterface $kernel,
    ): Response {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw $this->createNotFoundException('Invalid export ID.');
        }
        if (!$jobStatusService->exists($id)) {
            throw $this->createNotFoundException(
                $this->translator->trans('processing_activity.export.file_not_found', [], 'privacy'),
            );
        }
        $record = $jobStatusService->read($id);
        if (($record['status'] ?? '') !== 'succeeded') {
            throw $this->createNotFoundException(
                $this->translator->trans('processing_activity.export.file_not_found', [], 'privacy'),
            );
        }

        $path = $kernel->getProjectDir() . '/var/exports/' . $id . '.csv';
        if (!is_file($path)) {
            throw $this->createNotFoundException(
                $this->translator->trans('processing_activity.export.file_not_found', [], 'privacy'),
            );
        }

        $filename = sprintf('VVT-Export-%s.csv', date('Y-m-d'));

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * Export VVT as CSV
     */
    #[Route('/processing-activity/export/csv', name: 'app_processing_activity_export_csv', methods: ['GET'])]
    public function exportCsv(): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

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
            fputcsv($output, array_map([$this, 'sanitizeCsvValue'], $row), escape: '\\');
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
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

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
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

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
    #[Route('/processing-activity/{id}', name: 'app_processing_activity_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        $complianceReport = $this->processingActivityService->generateComplianceReport($processingActivity);

        return $this->render('processing_activity/show.html.twig', [
            'processing_activity' => $processingActivity,
            'compliance_report' => $complianceReport,
        ]);
    }

    /**
     * Compliance report for a single processing activity (JSON API)
     */
    #[Route('/processing-activity/{id}/compliance-report', name: 'app_processing_activity_compliance_report', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function complianceReport(ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        $report = $this->processingActivityService->generateComplianceReport($processingActivity);

        return $this->json($report);
    }

    /**
     * Validate processing activity (AJAX endpoint)
     */
    #[Route('/processing-activity/{id}/validate', name: 'app_processing_activity_validate', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function validate(ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;

        $errors = $this->processingActivityService->validate($processingActivity);
        $isCompliant = $errors === [];

        return $this->json([
            'is_compliant' => $isCompliant,
            'errors' => $errors,
            'completeness_percentage' => $processingActivity->getCompletenessPercentage(),
        ]);
    }

    /**
     * Sprint-2 P-7 Wave-2 Trigger-2: AVV (Auftragsverarbeitungs-Vertrag) supplier picker.
     *
     * GET shows a Supplier-multi-select pre-filled with legacy `processors`-JSON
     * name matches; POST persists the selected suppliers onto
     * ProcessingActivity::$processorSuppliers (GDPR Art. 28(1)/(3)).
     * Tenant-isolated; only Suppliers of the current tenant are offered.
     */
    #[Route('/processing-activity/{id}/avv-picker', name: 'app_processing_activity_avv_picker', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function avvPicker(Request $request, ProcessingActivity $processingActivity): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $processingActivity->getTenant() !== $tenant) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('avv_picker_' . $processingActivity->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->translator->trans('common.csrf_invalid', [], 'messages'));
                return $this->redirectToRoute('app_processing_activity_avv_picker', ['id' => $processingActivity->getId()]);
            }

            $supplierIds = array_filter(
                (array) $request->request->all('supplier_ids'),
                static fn($id): bool => is_string($id) && ctype_digit($id),
            );

            // Reset + re-attach (idempotent)
            foreach ($processingActivity->getProcessorSuppliers() as $existing) {
                $processingActivity->removeProcessorSupplier($existing);
            }
            foreach ($supplierIds as $id) {
                $supplier = $this->entityManager->getRepository(Supplier::class)->find((int) $id);
                if ($supplier instanceof Supplier && $supplier->getTenant() === $tenant) {
                    $processingActivity->addProcessorSupplier($supplier);
                }
            }
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('processing_activity.avv_required.flash_saved', [], 'alva'));

            return $this->redirectToRoute('app_processing_activity_show', ['id' => $processingActivity->getId()]);
        }

        $candidates = $this->avvPickerPreFiller !== null
            ? $this->avvPickerPreFiller->candidatesFor($processingActivity, $tenant)
            : [];

        return $this->render('processing_activity/avv_picker.html.twig', [
            'processing_activity' => $processingActivity,
            'candidates' => $candidates,
            'already_selected' => $processingActivity->getProcessorSuppliers(),
        ]);
    }

    /**
     * Sanitize a CSV cell value to prevent formula injection (OWASP - Injection).
     * Prefixes values starting with =, +, -, @, TAB or CR with a single quote.
     */
    private function sanitizeCsvValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
