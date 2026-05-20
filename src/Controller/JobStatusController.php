<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Job\JobStatusService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Polling endpoint + shared progress page for async admin jobs.
 *
 * Two endpoints:
 *  - GET /admin/jobs/{id}/status   → JSON, polled every 3 s by
 *                                    `_async_job_progress.html.twig`.
 *  - GET /admin/jobs/{id}/progress → HTML, the shared progress page that
 *                                    every async-dispatch controller
 *                                    redirects to after POST (PRG pattern).
 *
 * Per-job metadata (human label, sub-line, optional download link) is
 * stored in the JobStatusService payload under reserved keys (`_label`,
 * `_subtitle`, `_download_url`, `_download_label`) at create-time, so the
 * shared progress page can render every async-job dispatch without a
 * controller-specific template.
 *
 * Authorisation model: ROLE_USER is sufficient because job IDs are
 * UUID v4 (unguessable, 122 bits of entropy) and the dispatching
 * controller has already enforced its own RBAC at POST time. The status
 * payload contains nothing more sensitive than the dispatcher already
 * gave the user access to — surfacing it back on the progress page
 * adds no privilege. Non-admin async dispatchers (e.g. risk export
 * under ROLE_USER) need this so the PRG redirect doesn't 403.
 */
#[IsGranted('ROLE_USER')]
class JobStatusController extends AbstractController
{
    public function __construct(
        private readonly JobStatusService $jobStatusService,
    ) {
    }

    /**
     * Returns JSON job status for the given UUID.
     *
     * Response shape:
     * {
     *   "id": "...",
     *   "name": "admin.data_repair.fix_all_orphans",
     *   "status": "running" | "pending" | "succeeded" | "failed" | "unknown",
     *   "message": null | "...",
     *   "progress_current": 5,
     *   "progress_total": 47,
     *   "started_at": 1716000000 | null,
     *   "updated_at": 1716000060 | null,
     *   "payload": {...},
     *   "error_trace": null | "..."
     * }
     */
    #[Route('/admin/jobs/{id}/status', name: 'admin_job_status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        // Basic UUID v4 validation to prevent path-traversal / directory read
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            return new JsonResponse(['error' => 'Invalid job ID'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->jobStatusService->exists($id)) {
            return new JsonResponse(['error' => 'Job not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->jobStatusService->read($id));
    }

    /**
     * Shared progress page for async admin jobs.
     *
     * Reached via 303 redirect from any POST handler that creates a job
     * via {@see JobStatusService::create()} and dispatches it via
     * {@see \App\Service\Job\JobDispatcher::dispatch()}. The PRG (Post/
     * Redirect/Get) pattern is mandatory because Hotwire Turbo refuses
     * to render an HTML body in response to a form submission — it
     * throws "Form responses must redirect to another location".
     *
     * The label / subtitle / cancel / download metadata is pulled from
     * the payload that the dispatching controller stored under reserved
     * keys (`_label`, `_subtitle`, `_download_url`, `_download_label`).
     * The optional `return` query parameter overrides the cancel URL —
     * dispatching controllers pass `return=<index-route>` so the "Back"
     * button takes the user back to where they started.
     */
    #[Route('/admin/jobs/{id}/progress', name: 'admin_job_progress_page', methods: ['GET'])]
    public function progressPage(string $id, Request $request): Response
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw $this->createNotFoundException('Invalid job ID');
        }

        if (!$this->jobStatusService->exists($id)) {
            throw $this->createNotFoundException('Job not found');
        }

        $job = $this->jobStatusService->read($id);
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        $label = is_string($payload['_label'] ?? null) && $payload['_label'] !== ''
            ? $payload['_label']
            : ($job['name'] !== '' ? $job['name'] : 'Async job');

        $subtitle = is_string($payload['_subtitle'] ?? null) && $payload['_subtitle'] !== ''
            ? $payload['_subtitle']
            : null;

        $downloadUrl = is_string($payload['_download_url'] ?? null) && $payload['_download_url'] !== ''
            ? $payload['_download_url']
            : null;

        $downloadLabel = is_string($payload['_download_label'] ?? null) && $payload['_download_label'] !== ''
            ? $payload['_download_label']
            : null;

        // `return` is the URL the dispatching controller wants the "Back"
        // button to go to. Open-redirect-safe: we only accept relative
        // paths (starting with `/`) so an attacker can't redirect the
        // operator to an external host via the query string.
        $returnRaw = (string) $request->query->get('return', '');
        $cancelUrl = (str_starts_with($returnRaw, '/') && !str_starts_with($returnRaw, '//'))
            ? $returnRaw
            : $this->generateUrl('admin_dashboard');

        return $this->render('admin/jobs/_progress_page.html.twig', [
            'jobId'          => $id,
            'job_name'       => $job['name'] ?: 'admin.job',
            'job_label'      => $label,
            'job_subtitle'   => $subtitle,
            'cancel_url'     => $cancelUrl,
            'download_url'   => $downloadUrl,
            'download_label' => $downloadLabel,
        ]);
    }
}
