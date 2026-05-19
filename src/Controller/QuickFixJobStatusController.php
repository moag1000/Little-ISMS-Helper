<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Job\JobStatusService;
use App\Service\QuickFixGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON polling endpoint for QuickFix async jobs.
 *
 * Intentionally separate from {@see \App\Controller\Admin\JobStatusController}
 * because QuickFix is an UN-authenticated emergency UI gated only by
 * {@see QuickFixGuard} (token / dev-only / IP allowlist). The admin polling
 * controller carries `#[IsGranted('ROLE_ADMIN')]` and would be unreachable
 * while the app is in a degraded boot state — the exact scenario QuickFix
 * exists to recover from.
 *
 * Threat model: callers that pass `QuickFixGuard::mayAccess()` have full
 * access to apply migrations, reconcile schema and reassign tenants on
 * this endpoint set — so reading their job-status JSON adds no privilege.
 * UUID-v4 validation still guards against directory traversal on the
 * status file path.
 */
class QuickFixJobStatusController extends AbstractController
{
    public function __construct(
        private readonly JobStatusService $jobStatusService,
    ) {
    }

    public function status(Request $request, QuickFixGuard $guard, string $id): JsonResponse
    {
        if (!$guard->mayAccess($request)) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        // UUID v4 validation prevents path-traversal / directory enumeration
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            return new JsonResponse(['error' => 'Invalid job ID'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->jobStatusService->exists($id)) {
            return new JsonResponse(['error' => 'Job not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->jobStatusService->read($id));
    }
}
