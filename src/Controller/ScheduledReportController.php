<?php

namespace App\Controller;

use App\Entity\ScheduledReport;
use App\Form\ScheduledReportType;
use App\Repository\ScheduledReportRepository;
use App\Service\ScheduledReportService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Scheduled Report Controller
 *
 * Phase 7A: Manages scheduled reports - create, edit, activate/deactivate, and trigger.
 */
#[Route('/reports/scheduled')]
#[IsGranted('ROLE_MANAGER')]
class ScheduledReportController extends AbstractController
{
    public function __construct(
        private readonly ScheduledReportRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly ScheduledReportService $scheduledReportService,
    ) {
    }

    #[Route('/', name: 'app_scheduled_report_index')]
    public function index(): Response
    {
        $tenantId = $this->tenantContext->getTenantId();
        $reports = $this->repository->findByTenant($tenantId);
        $statistics = $this->repository->getStatistics($tenantId);
        $dueReports = $this->repository->findDueReports();

        return $this->render('scheduled_report/index.html.twig', [
            'reports' => $reports,
            'statistics' => $statistics,
            'due_reports' => array_filter($dueReports, fn($r) => $r->getTenantId() === $tenantId),
        ]);
    }

    #[Route('/new', name: 'app_scheduled_report_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $report = new ScheduledReport();
        $report->setTenantId($this->tenantContext->getTenantId());
        $report->setCreatedBy($this->getUser());
        $report->setLocale($request->getLocale());

        $form = $this->createForm(ScheduledReportType::class, $report);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Calculate initial next run time
            $report->calculateNextRunAt();

            $this->entityManager->persist($report);
            $this->entityManager->flush();

            $this->addFlash('success', 'scheduled_report.flash.created');

            return $this->redirectToRoute('app_scheduled_report_show', ['id' => $report->getId()]);
        }

        return $this->render('scheduled_report/new.html.twig', [
            'report' => $report,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_scheduled_report_show', methods: ['GET'])]
    public function show(ScheduledReport $report): Response
    {
        $this->checkAccess($report);

        return $this->render('scheduled_report/show.html.twig', [
            'report' => $report,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_scheduled_report_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ScheduledReport $report): Response
    {
        $this->checkAccess($report);

        $form = $this->createForm(ScheduledReportType::class, $report);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Recalculate next run time if schedule changed
            $report->calculateNextRunAt();

            $this->entityManager->flush();

            $this->addFlash('success', 'scheduled_report.flash.updated');

            return $this->redirectToRoute('app_scheduled_report_show', ['id' => $report->getId()]);
        }

        return $this->render('scheduled_report/edit.html.twig', [
            'report' => $report,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/toggle', name: 'app_scheduled_report_toggle', methods: ['POST'])]
    public function toggle(Request $request, ScheduledReport $report): Response
    {
        $this->checkAccess($report);

        if ($this->isCsrfTokenValid('toggle' . $report->getId(), $request->request->get('_token'))) {
            $report->setIsActive(!$report->isActive());

            if ($report->isActive()) {
                // Calculate next run time when activating
                $report->calculateNextRunAt();
            }

            $this->entityManager->flush();

            $flashKey = $report->isActive() ? 'scheduled_report.flash.activated' : 'scheduled_report.flash.deactivated';
            $this->addFlash('success', $flashKey);
        }

        return $this->redirectToRoute('app_scheduled_report_show', ['id' => $report->getId()]);
    }

    #[Route('/{id}/trigger', name: 'app_scheduled_report_trigger', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function trigger(Request $request, ScheduledReport $report): Response
    {
        $this->checkAccess($report);

        if ($this->isCsrfTokenValid('trigger' . $report->getId(), $request->request->get('_token'))) {
            try {
                $this->scheduledReportService->triggerReport($report);
                $this->addFlash('success', 'scheduled_report.flash.triggered');
            } catch (\Exception $e) {
                $this->addFlash('error', 'scheduled_report.flash.trigger_failed');
            }
        }

        return $this->redirectToRoute('app_scheduled_report_show', ['id' => $report->getId()]);
    }

    #[Route('/{id}/preview', name: 'app_scheduled_report_preview', methods: ['GET'])]
    public function preview(ScheduledReport $report): Response
    {
        $this->checkAccess($report);

        try {
            $content = $this->scheduledReportService->previewReport($report);
            $filename = $report->getReportType() . '_preview.' . ($report->getFormat() === 'pdf' ? 'pdf' : 'xlsx');
            $mimeType = $report->getFormat() === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

            return new Response($content, Response::HTTP_OK, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'scheduled_report.flash.preview_failed');
            return $this->redirectToRoute('app_scheduled_report_show', ['id' => $report->getId()]);
        }
    }

    #[Route('/{id}/delete', name: 'app_scheduled_report_delete', methods: ['POST'])]
    public function delete(Request $request, ScheduledReport $report): Response
    {
        $this->checkAccess($report);

        if ($this->isCsrfTokenValid('delete' . $report->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($report);
            $this->entityManager->flush();

            $this->addFlash('success', 'scheduled_report.flash.deleted');
        }

        return $this->redirectToRoute('app_scheduled_report_index');
    }

    /**
     * Check if user has access to this report (tenant check)
     */
    private function checkAccess(ScheduledReport $report): void
    {
        if ($report->getTenantId() !== $this->tenantContext->getTenantId()) {
            throw $this->createAccessDeniedException('Access denied to this scheduled report.');
        }
    }
}
