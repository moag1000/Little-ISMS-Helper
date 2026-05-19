<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditLogger;
use App\Service\Export\DoraRegisterOfInformationExporter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * DORA Register of Information CSV export endpoint.
 *
 * Emits the EBA/EIOPA/ESMA Final Draft ITS on ROI (Art. 28 DORA) conformant
 * CSV for the current tenant. Guarded by ROLE_MANAGER — export contains
 * regulated third-party data. Every download is audit-logged via
 * AuditLogger::logExport() for supervisory traceability.
 *
 * Tracks MINOR-6 in docs/DATA_REUSE_PLAN_REVIEW_ISB.md.
 */
class DoraRegisterExportController extends AbstractController
{
    public function __construct(
        private readonly DoraRegisterOfInformationExporter $exporter,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[Route(
        path: '/dora-compliance/register-export.csv',
        name: 'app_dora_register_export_csv',
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_MANAGER')]
    public function export(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $csv = $this->exporter->export($tenant);

        $this->auditLogger->logExport(
            'DoraRegisterOfInformation',
            null,
            'DORA Register of Information CSV export',
        );

        $filename = sprintf('dora-register-of-information-%s.csv', (new \DateTimeImmutable())->format('Y-m-d'));

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * Async wrapper around {@see self::export()}: dispatches an
     * {@see \App\Job\ExportDoraRegisterJob} that writes the ROI CSV under
     * var/exports/<jobId>.csv and renders a polling progress page with a
     * Download CTA once the worker reports succeeded.
     *
     * The legacy sync GET route is kept for back-compat (audit-link emails,
     * bookmarks); new UI traffic should use this dispatch endpoint.
     *
     * Phase 3 of the async admin-jobs rollout.
     */
    #[Route(
        path: '/dora-compliance/register-export/dispatch',
        name: 'app_dora_register_export_csv_dispatch',
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('dora_register_export_dispatch')]
    public function exportDispatch(
        \App\Service\Job\JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
    ): Response {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $args = ['tenantId' => $tenant->getId()];
        $jobId = $jobStatusService->create('dora.register_export', $args);

        $messageBus->dispatch(new \App\Message\Job\ExecuteJobMessage(
            jobClass: \App\Job\ExportDoraRegisterJob::class,
            args: $args,
            jobId: $jobId,
        ));

        return $this->render('dora_compliance/export_progress.html.twig', [
            'jobId' => $jobId,
            'cancelUrl' => $this->generateUrl('app_dora_compliance_dashboard'),
            'downloadUrl' => $this->generateUrl('app_dora_register_export_csv_download', ['id' => $jobId]),
        ]);
    }

    /**
     * Streams the file produced by {@see \App\Job\ExportDoraRegisterJob} and
     * removes it from disk afterwards. The job ID UUID-v4 is the canonical
     * filename stem.
     */
    #[Route(
        path: '/dora-compliance/register-export/download/{id}',
        name: 'app_dora_register_export_csv_download',
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_MANAGER')]
    public function exportDownload(
        string $id,
        \App\Service\Job\JobStatusService $jobStatusService,
        KernelInterface $kernel,
        TranslatorInterface $translator,
    ): Response {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw $this->createNotFoundException('Invalid export ID.');
        }
        if (!$jobStatusService->exists($id)) {
            throw $this->createNotFoundException(
                $translator->trans('dora.register_export.file_not_found', [], 'dora'),
            );
        }
        $record = $jobStatusService->read($id);
        if (($record['status'] ?? '') !== 'succeeded') {
            throw $this->createNotFoundException(
                $translator->trans('dora.register_export.file_not_found', [], 'dora'),
            );
        }

        $path = $kernel->getProjectDir() . '/var/exports/' . $id . '.csv';
        if (!is_file($path)) {
            throw $this->createNotFoundException(
                $translator->trans('dora.register_export.file_not_found', [], 'dora'),
            );
        }

        $filename = sprintf('dora-register-of-information-%s.csv', (new \DateTimeImmutable())->format('Y-m-d'));

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
