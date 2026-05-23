<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Job\AsyncJobInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Boilerplate-reduction facade over {@see JobStatusService} +
 * {@see JobDispatcher} for the canonical "create-status → render-progress →
 * dispatch" pattern used by ~30 async-admin endpoints.
 *
 * Junior-ISB-Audit-2026-05-22 P-16: Boilerplate-reduction facade.
 *
 * Replaces the 5-step ritual:
 *   1. $jobStatusService->create($name, $payload)
 *   2. (optional) $jobStatusService->updatePayload($id, [...])
 *   3. build progress-page redirect / response
 *   4. $jobDispatcher->dispatch($jobClass, $args, $id, $response, $session)
 *   5. return $response
 *
 * with a single call:
 *
 *   return $this->asyncJobDispatcher->dispatchWithProgress(
 *       request: $request,
 *       jobClass: RunFullIntegrityCheckJob::class,
 *       jobArgs: [],
 *       jobName: 'admin.data_repair.run_integrity_check',
 *       payload: ['_label' => ..., '_subtitle' => ...],
 *       returnUrl: $this->generateUrl('admin_data_repair_index'),
 *   );
 *
 * Public surface is intentionally narrow — the older {@see JobDispatcher} is
 * kept as the lower-level primitive for the small number of call-sites that
 * need to hand-craft the response (XHR JSON envelopes, payload-patching
 * with the freshly-minted job-UUID, BinaryFileResponse downloads, etc.).
 *
 * Backwards-compat: every existing {@see JobDispatcher::dispatch()} call-site
 * is left untouched. This facade only adds a new convenience entry-point.
 */
final readonly class AsyncJobDispatcher
{
    /**
     * Default Symfony route name of the shared progress page rendered after
     * the redirect. Defined by
     * {@see \App\Controller\JobStatusController::progressPage()}.
     */
    public const PROGRESS_ROUTE = 'admin_job_progress_page';

    /**
     * Default Twig template for the {@see dispatchWithProgressTemplate()}
     * variant. Renders the same progress card the PRG-redirect target uses.
     */
    public const PROGRESS_TEMPLATE = '_components/_async_job_progress.html.twig';

    public function __construct(
        private JobStatusService $jobStatusService,
        private JobDispatcher $jobDispatcher,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * Dispatch a job and respond with a 303 redirect to the shared
     * progress-page (PRG / Turbo-compatible). This is the dominant pattern in
     * the codebase — use this for any new admin endpoint that triggers a
     * long-running job from a regular form POST.
     *
     * `$payload` is stored alongside the status record. UI metadata keys
     * `_label`, `_subtitle`, `_download_url`, `_download_label` are picked up
     * by {@see \App\Controller\JobStatusController::progressPage()} to render
     * a meaningful progress card.
     *
     * `$jobArgs` is forwarded to {@see AsyncJobInterface::run()} via
     * {@see \App\Job\JobContext::args()}. It can — and usually does — differ
     * from `$payload` (payload includes UI strings, args are the job's data).
     *
     * @param class-string<AsyncJobInterface> $jobClass
     * @param array<string,mixed>             $jobArgs Forwarded to the job
     * @param array<string,mixed>             $payload Stored on status record
     * @param string                          $returnUrl URL the progress
     *     page's "Back" button returns to (typically the index route).
     */
    public function dispatchWithProgress(
        Request $request,
        string $jobClass,
        array $jobArgs,
        string $jobName,
        array $payload = [],
        string $returnUrl = '',
    ): Response {
        $jobId = $this->jobStatusService->create($jobName, $payload);

        $params = ['id' => $jobId];
        if ($returnUrl !== '') {
            $params['return'] = $returnUrl;
        }
        $progressUrl = $this->urlGenerator->generate(self::PROGRESS_ROUTE, $params);

        // 303 See Other — Turbo requires a redirect for form-POST responses;
        // InRequestJobRunner flushes this before the job starts running, so
        // the polling page is already loaded when the worker hits its first
        // progress() call.
        $response = new RedirectResponse($progressUrl, Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            $jobClass,
            $jobArgs,
            $jobId,
            $response,
            $request->hasSession() ? $request->getSession() : null,
        );
    }

    /**
     * Variant that renders the progress template directly instead of
     * issuing a 303 redirect.
     *
     * Use this only when the caller is NOT inside a Turbo-controlled form
     * submission (e.g. fully-custom JS that explicitly bypasses Turbo, or a
     * GET-driven workflow). For form POSTs always prefer
     * {@see self::dispatchWithProgress()}.
     *
     * @param class-string<AsyncJobInterface> $jobClass
     * @param array<string,mixed>             $jobArgs
     * @param array<string,mixed>             $payload
     * @param array<string,mixed>             $templateContext Extra Twig vars
     *     merged with `jobId` + `cancelUrl` before rendering.
     */
    public function dispatchWithProgressTemplate(
        Request $request,
        string $jobClass,
        array $jobArgs,
        string $jobName,
        string $cancelUrl,
        array $payload = [],
        string $template = self::PROGRESS_TEMPLATE,
        array $templateContext = [],
    ): Response {
        $jobId = $this->jobStatusService->create($jobName, $payload);

        $html = $this->twig->render($template, [
            'jobId'     => $jobId,
            'cancelUrl' => $cancelUrl,
        ] + $templateContext);
        $response = new Response($html);

        return $this->jobDispatcher->dispatch(
            $jobClass,
            $jobArgs,
            $jobId,
            $response,
            $request->hasSession() ? $request->getSession() : null,
        );
    }
}
