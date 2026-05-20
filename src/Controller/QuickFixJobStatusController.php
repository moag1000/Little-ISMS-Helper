<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Job\JobStatusService;
use App\Service\QuickFixGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Polling endpoint + shared progress page for QuickFix async jobs.
 *
 * Intentionally separate from {@see \App\Controller\JobStatusController}
 * because QuickFix is an UN-authenticated emergency UI gated only by
 * {@see QuickFixGuard} (token / dev-only / IP allowlist). The admin polling
 * controller carries `#[IsGranted('ROLE_ADMIN')]` and would be unreachable
 * while the app is in a degraded boot state — the exact scenario QuickFix
 * exists to recover from.
 *
 * Threat model: callers that pass {@see QuickFixGuard::mayAccess()} have full
 * access to apply migrations, reconcile schema and reassign tenants on
 * this endpoint set — so reading their job-status JSON or rendering the
 * progress page adds no privilege. UUID-v4 validation still guards
 * against directory traversal on the status file path.
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

    /**
     * Shared progress page for QuickFix async jobs.
     *
     * Reached via 303 redirect from every {@see \App\Controller\QuickFixController}
     * POST handler that dispatches an async job. The PRG (Post/Redirect/Get)
     * pattern is mandatory because Hotwire Turbo refuses to render an HTML
     * body in response to a form submission — it throws "Form responses
     * must redirect to another location". The standalone QuickFix progress
     * template intentionally does NOT extend any layout (QuickFix exists
     * for the case where the main app layout itself is broken).
     *
     * Reuses the same payload-driven contract as
     * {@see \App\Controller\JobStatusController::progressPage()}:
     *  - `_label`, `_subtitle` set per-dispatch on the job payload,
     *  - `?return=` query parameter (open-redirect-safe) for the back link.
     */
    public function progressPage(
        Request $request,
        QuickFixGuard $guard,
        TranslatorInterface $translator,
        string $id,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

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
            : $translator->trans('quick_fix.job.generic_label', [], 'quick_fix');

        $subtitle = is_string($payload['_subtitle'] ?? null) && $payload['_subtitle'] !== ''
            ? $payload['_subtitle']
            : null;

        // Open-redirect-safe: only accept relative paths (starting with /).
        $returnRaw = (string) $request->query->get('return', '');
        $cancelUrl = (str_starts_with($returnRaw, '/') && !str_starts_with($returnRaw, '//'))
            ? $returnRaw
            : $this->generateUrl('app_quick_fix_index');

        return $this->render('quick_fix/job_progress.html.twig', [
            'jobId'       => $id,
            'jobLabel'    => $label,
            'jobSubtitle' => $subtitle,
            'cancelUrl'   => $cancelUrl,
            'statusUrl'   => $this->generateUrl('app_quick_fix_job_status', ['id' => $id]),
        ]);
    }
}
