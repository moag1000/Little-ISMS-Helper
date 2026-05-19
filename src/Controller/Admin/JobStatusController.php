<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Job\JobStatusService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JSON polling endpoint for async admin jobs.
 *
 * Used by the `_async_job_progress.html.twig` partial and any frontend
 * code that needs to track job status without a full page reload.
 */
#[IsGranted('ROLE_ADMIN')]
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
}
