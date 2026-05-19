<?php

declare(strict_types=1);

namespace App\Controller;

use App\Job\ExportDataJob;
use App\Message\Job\ExecuteJobMessage;
use App\Service\Job\JobStatusService;
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

/**
 * Async wrapper around the generic /admin/data/export/* flow.
 *
 * The original synchronous endpoint
 * ({@see \App\Controller\AdminBackupController::exportExecute()}) returned a
 * StreamedResponse that pegged PHP-FPM until the entire export finished
 * streaming to the browser. On large tenant trees this trips PHP-FPM's 30 s
 * timeout — and also blocks the admin user's session for the duration.
 *
 * This controller exposes:
 *   POST /admin/data/export/dispatch  → dispatches {@see ExportDataJob}
 *                                       and renders the polling progress page
 *   GET  /admin/exports/{id}/download → streams the var/exports/<id>.<ext>
 *                                       file the worker wrote, then deletes it
 *
 * The sync endpoint is intentionally left in place because external automation
 * (e.g. periodic cron exports) and tests still rely on its semantics. New UI
 * traffic SHOULD use this controller — see templates/data_management/export.html.twig.
 *
 * Phase 2.5 of the async admin-jobs rollout.
 */
#[IsGranted('ROLE_ADMIN')]
final class DataExportAsyncController extends AbstractController
{
    public function __construct(
        private readonly JobStatusService $jobStatusService,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly KernelInterface $kernel,
    ) {
    }

    #[Route('/admin/data/export/dispatch', name: 'data_export_dispatch', methods: ['POST'])]
    #[IsCsrfTokenValid('data_export')]
    public function dispatch(Request $request): Response
    {
        /** @var list<string> $selectedEntities */
        $selectedEntities = $request->request->all('entities');
        $format = (string) $request->request->get('format', 'json');

        if ($selectedEntities === []) {
            $this->addFlash(
                'error',
                $this->translator->trans('admin.data_export.error.no_entities', [], 'admin'),
            );
            return $this->redirectToRoute('data_export_index');
        }

        if (!in_array($format, ['json', 'csv'], true)) {
            $format = 'json';
        }

        // Whitelist defensively: anything not in the App\Entity namespace is
        // dropped here so the job never sees attacker-controlled FQCNs.
        $selectedEntities = array_values(array_filter(
            $selectedEntities,
            static fn(mixed $v): bool => is_string($v) && str_starts_with($v, 'App\\Entity\\'),
        ));

        if ($selectedEntities === []) {
            $this->addFlash(
                'error',
                $this->translator->trans('admin.data_export.error.no_entities', [], 'admin'),
            );
            return $this->redirectToRoute('data_export_index');
        }

        $jobId = $this->jobStatusService->create(
            'admin.data_export.dispatch',
            ['entities' => $selectedEntities, 'format' => $format],
        );

        $this->messageBus->dispatch(new ExecuteJobMessage(
            jobClass: ExportDataJob::class,
            args: ['entities' => $selectedEntities, 'format' => $format],
            jobId: $jobId,
        ));

        return $this->render('data_management/export_progress.html.twig', [
            'jobId' => $jobId,
            'format' => $format,
            'cancelUrl' => $this->generateUrl('data_export_index'),
            'downloadUrl' => $this->generateUrl('data_export_download', ['id' => $jobId]),
        ]);
    }

    /**
     * Streams the file produced by {@see ExportDataJob} to the browser and
     * removes it from disk afterwards (deleteFileAfterSend).
     *
     * The job ID doubles as the filename stem so we can safely derive a
     * canonical path without trusting any user input beyond the UUID v4 shape.
     */
    #[Route('/admin/exports/{id}/download', name: 'data_export_download', methods: ['GET'])]
    public function download(string $id): Response
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw $this->createNotFoundException('Invalid export ID.');
        }

        if (!$this->jobStatusService->exists($id)) {
            throw $this->createNotFoundException(
                $this->translator->trans('admin.job.export.file_not_found', [], 'admin'),
            );
        }

        $record = $this->jobStatusService->read($id);
        if (($record['status'] ?? '') !== 'succeeded') {
            throw $this->createNotFoundException(
                $this->translator->trans('admin.job.export.file_not_found', [], 'admin'),
            );
        }

        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];
        $format = is_string($payload['format'] ?? null) ? $payload['format'] : 'json';
        if (!in_array($format, ['json', 'csv'], true)) {
            $format = 'json';
        }

        $path = $this->kernel->getProjectDir() . '/var/exports/' . $id . '.' . $format;
        if (!is_file($path)) {
            throw $this->createNotFoundException(
                $this->translator->trans('admin.job.export.file_not_found', [], 'admin'),
            );
        }

        $contentType = $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json';
        $downloadName = sprintf('export_%s.%s', date('Y-m-d_H-i-s'), $format);

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $contentType);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $downloadName,
        );
        // Remove file after the response is sent so subsequent requests for
        // the same UUID hit 404 — exports are one-shot artefacts.
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
