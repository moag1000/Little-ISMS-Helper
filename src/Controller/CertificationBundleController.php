<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\PdfLocaleTrait;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\CertBundleReadinessService;
use App\Service\Export\CertificationBundleExporter;
use App\Service\TenantContext;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Certification Bundle Controller
 *
 * Provides a one-click export of all ISMS documentation required for
 * ISO 27001 certification audits. The bundle includes SoA, risk treatment
 * plans, asset register, evidence documents, and gap analysis.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/certification-bundle', name: 'app_certification_bundle_')]
#[IsGranted('ROLE_MANAGER')]
class CertificationBundleController extends AbstractController
{
    use PdfLocaleTrait;

    public function __construct(
        private readonly CertificationBundleExporter $exporter,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly CertBundleReadinessService $readinessService,
    ) {
    }

    /**
     * Preview page showing bundle contents and counts before generation.
     *
     * Audit-V3 EF-3: framework-aware. `?framework=BSI_GRUNDSCHUTZ` selects
     * a non-default framework for the bundle metadata. Only active
     * frameworks are accepted; unknown codes fall back to ISO27001.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $counts = $this->exporter->getPreviewCounts($tenant);
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $selectedCode = $this->resolveFrameworkCode($request, $frameworks);

        return $this->render('certification_bundle/index.html.twig', [
            'tenant' => $tenant,
            'counts' => $counts,
            'frameworks' => $frameworks,
            'selected_framework_code' => $selectedCode,
        ]);
    }

    /**
     * V4-EF-8: Pre-export preflight check.
     *
     * Shows readiness score + blocker list before the user can export the bundle.
     * The form on this page posts to /export (with bypass flag) or redirects
     * back to the index to fix blockers.
     *
     * GET  → render preflight card
     * POST (bypass=1) → redirect to export
     */
    #[Route('/preflight', name: 'preflight', methods: ['GET'])]
    public function preflight(Request $request): Response
    {
        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $selectedCode = $this->resolveFrameworkCode($request, $frameworks);

        $readiness = $this->readinessService->check($tenant, $selectedCode);

        return $this->render('certification_bundle/preflight.html.twig', [
            'tenant'                 => $tenant,
            'frameworks'             => $frameworks,
            'selected_framework_code' => $selectedCode,
            'readiness'              => $readiness,
        ]);
    }

    /**
     * Generate and download the certification bundle ZIP.
     *
     * Task #122: accepts `frameworks[]` (multi-checkbox form) in addition
     * to the legacy single `framework` field. When `frameworks[]` is
     * present, every selected code is added via {@see CertificationBundleExporter::addFrameworkBundle()}
     * so the resulting ZIP carries `02_FRAMEWORK_MAPPING/<code>_coverage.csv`
     * for each one. The legacy single-`framework` field still works
     * unchanged for back-compat.
     */
    #[Route('/export', name: 'export', methods: ['POST'])]
    public function export(Request $request): BinaryFileResponse
    {
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('certification_bundle_export', $submittedToken)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $activeFrameworks = $this->frameworkRepository->findActiveFrameworks();
        $selectedCodes = $this->resolveFrameworkCodes($request, $activeFrameworks);

        // Task #128 — optional point-in-time freeze. When the form
        // posts an `as_of_date` (YYYY-MM-DD), the bundle includes the
        // SoA snapshot section (00_SOA_SNAPSHOT/) instead of the live
        // SoA only. Future dates and bad inputs fall back to live.
        $asOfDate = null;
        $rawAsOf = trim((string) $request->request->get('as_of_date', ''));
        if ($rawAsOf !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $rawAsOf);
            if ($parsed instanceof \DateTimeImmutable && $parsed <= new \DateTimeImmutable()) {
                $asOfDate = $parsed->setTime(23, 59, 59);
            }
        }

        $locale = $this->resolvePdfLocale($request);
        $result = $this->localeSwitcher->runWithLocale(
            $locale,
            fn() => $this->exporter->export($tenant, $selectedCodes, $asOfDate)
        );

        $response = new BinaryFileResponse($result['path']);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $result['filename']
        );
        $response->deleteFileAfterSend(true);

        $this->addFlash('success', 'certification_bundle.success');

        return $response;
    }

    /**
     * Async wrapper around {@see self::export()}: dispatches an
     * {@see \App\Job\ExportCertificationBundleJob} that builds the bundle
     * ZIP under var/exports/<jobId>.zip and renders a polling progress
     * page with a Download CTA once the worker reports succeeded.
     *
     * The legacy sync POST route is kept for back-compat (existing
     * automation + the integrationless certification_bundle/preflight
     * green-path); new UI traffic should use this dispatch endpoint to
     * avoid PHP-FPM timeouts on big document trees.
     *
     * Phase 3 of the async admin-jobs rollout.
     */
    #[Route('/export/dispatch', name: 'export_dispatch', methods: ['POST'])]
    public function exportDispatch(
        Request $request,
        \App\Service\Job\JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
    ): Response {
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('certification_bundle_export_dispatch', $submittedToken)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $activeFrameworks = $this->frameworkRepository->findActiveFrameworks();
        $selectedCodes = $this->resolveFrameworkCodes($request, $activeFrameworks);

        $asOfDate = null;
        $rawAsOf = trim((string) $request->request->get('as_of_date', ''));
        if ($rawAsOf !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $rawAsOf);
            if ($parsed instanceof \DateTimeImmutable && $parsed <= new \DateTimeImmutable()) {
                $asOfDate = $parsed;
            }
        }

        $args = [
            'tenantId' => $tenant->getId(),
            'frameworks' => $selectedCodes,
            'asOfDate' => $asOfDate?->format('Y-m-d'),
            'locale' => $this->resolvePdfLocale($request),
        ];

        $jobId = $jobStatusService->create('certification_bundle.export', $args + [
            '_label' => $translator->trans('certification_bundle.export.progress_title', [], 'certification_bundle'),
            '_subtitle' => $translator->trans('certification_bundle.export.progress_subtitle', [], 'certification_bundle'),
            '_download_label' => $translator->trans('certification_bundle.export.download_button', [], 'certification_bundle'),
        ]);
        $jobStatusService->updatePayload($jobId, [
            '_download_url' => $this->generateUrl('app_certification_bundle_export_download', ['id' => $jobId]),
        ]);

        $messageBus->dispatch(new \App\Message\Job\ExecuteJobMessage(
            jobClass: \App\Job\ExportCertificationBundleJob::class,
            args: $args,
            jobId: $jobId,
        ));

        // PRG: 303 redirect — see DataRepairController::runIntegrityCheck() for rationale.
        return $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('app_certification_bundle_index'),
        ], Response::HTTP_SEE_OTHER);
    }

    /**
     * Streams the file produced by {@see \App\Job\ExportCertificationBundleJob}
     * (or its Konzern sibling) and removes it from disk afterwards. The
     * job ID UUID-v4 is the canonical filename stem.
     */
    #[Route('/export/download/{id}', name: 'export_download', methods: ['GET'])]
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
                $translator->trans('certification_bundle.export.file_not_found', [], 'certification_bundle'),
            );
        }
        $record = $jobStatusService->read($id);
        if (($record['status'] ?? '') !== 'succeeded') {
            throw $this->createNotFoundException(
                $translator->trans('certification_bundle.export.file_not_found', [], 'certification_bundle'),
            );
        }

        $path = $kernel->getProjectDir() . '/var/exports/' . $id . '.zip';
        if (!is_file($path)) {
            throw $this->createNotFoundException(
                $translator->trans('certification_bundle.export.file_not_found', [], 'certification_bundle'),
            );
        }

        $filename = sprintf('ISMS_Certification_Bundle_%s.zip', date('Y-m-d'));

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * Resolve the framework code from the request (`framework` form/query
     * param). Falls back to ISO27001 when missing or invalid. We compare
     * against active frameworks so an inactive code can never leak into
     * the audit-log description.
     *
     * @param iterable<\App\Entity\ComplianceFramework> $frameworks
     */
    private function resolveFrameworkCode(Request $request, iterable $frameworks): string
    {
        $requested = (string) ($request->request->get('framework') ?? $request->query->get('framework') ?? '');
        if ($requested === '') {
            return 'ISO27001';
        }

        foreach ($frameworks as $fw) {
            if ((string) $fw->getCode() === $requested) {
                return $requested;
            }
        }

        return 'ISO27001';
    }

    /**
     * Resolve the list of framework codes for a multi-framework bundle.
     *
     * Priority:
     *   1) `frameworks[]` POST array — preferred multi-framework selection
     *      from the new checkbox UI.
     *   2) `framework` (single) — legacy single-framework form, kept for
     *      back-compat.
     *   3) ISO27001 fallback.
     *
     * Every code must match an active framework; unknown codes are
     * silently dropped so an attacker cannot inject bogus codes into the
     * audit-log payload.
     *
     * @param iterable<\App\Entity\ComplianceFramework> $frameworks
     * @return list<string>
     */
    private function resolveFrameworkCodes(Request $request, iterable $frameworks): array
    {
        $activeCodes = [];
        foreach ($frameworks as $fw) {
            $activeCodes[(string) $fw->getCode()] = true;
        }
        // ISO27001 is always offered in the UI even if the loader hasn't
        // populated it yet, so accept it as a safe always-allowed code.
        $activeCodes['ISO27001'] = true;

        $multi = $request->request->all('frameworks');
        if (is_array($multi) && $multi !== []) {
            $codes = [];
            foreach ($multi as $code) {
                $code = (string) $code;
                if (isset($activeCodes[$code]) && !in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
            if ($codes !== []) {
                return $codes;
            }
        }

        return [$this->resolveFrameworkCode($request, $frameworks)];
    }

    /**
     * Generate and download a Konzern-scoped certification bundle ZIP.
     *
     * Task #129: aggregates per-subsidiary bundles (root + all
     * descendants) into one holding-level archive with an aggregated
     * INDEX.csv (`tenant_*` columns prepended), a 00_KONZERN_OVERVIEW.csv
     * showing per-subsidiary coverage %, and a 00_KONZERN_RACI.md
     * placeholder. Restricted to ROLE_GROUP_CISO / ROLE_KONZERN_AUDITOR
     * and only available when the current tenant has subsidiaries.
     */
    #[Route('/konzern-export', name: 'konzern_export', methods: ['POST'])]
    #[IsGranted(new \Symfony\Component\ExpressionLanguage\Expression(
        "is_granted('ROLE_GROUP_CISO') or is_granted('ROLE_KONZERN_AUDITOR')"
    ))]
    public function konzernExport(Request $request): Response
    {
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('certification_bundle_konzern_export', $submittedToken)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenant = $this->security->getUser()?->getTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        if ($tenant->getSubsidiaries()->count() === 0) {
            $this->addFlash('warning', 'certification_bundle.konzern_export.error.not_a_holding');
            return $this->redirectToRoute('app_certification_bundle_index');
        }

        $activeFrameworks = $this->frameworkRepository->findActiveFrameworks();
        $selectedCodes = $this->resolveFrameworkCodes($request, $activeFrameworks);

        $asOfDate = null;
        $asOfRaw = (string) $request->request->get('as_of_date', '');
        if ($asOfRaw !== '') {
            try {
                $asOfDate = new DateTimeImmutable($asOfRaw);
            } catch (\Throwable) {
                $asOfDate = null;
            }
        }

        $includeOnly = null;
        $rawSubsidiaryIds = $request->request->all('subsidiary_ids');
        if (is_array($rawSubsidiaryIds) && $rawSubsidiaryIds !== []) {
            $includeOnly = [];
            foreach ($rawSubsidiaryIds as $id) {
                $intId = (int) $id;
                if ($intId > 0) {
                    $includeOnly[] = $intId;
                }
            }
            if ($includeOnly === []) {
                $includeOnly = null;
            }
        }

        $locale = $this->resolvePdfLocale($request);
        try {
            $result = $this->localeSwitcher->runWithLocale(
                $locale,
                fn() => $this->exporter->exportKonzern($tenant, $selectedCodes, $asOfDate, $includeOnly),
            );
        } catch (\InvalidArgumentException $e) {
            // The exporter raises the i18n key `not_a_holding` so the
            // controller can surface a clean flash without leaking the
            // exception message to the user.
            if ($e->getMessage() === 'not_a_holding') {
                $this->addFlash('warning', 'certification_bundle.konzern_export.error.not_a_holding');
                return $this->redirectToRoute('app_certification_bundle_index');
            }
            throw $e;
        }

        $response = new BinaryFileResponse($result['path']);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $result['filename'],
        );
        $response->deleteFileAfterSend(true);

        $this->addFlash('success', 'certification_bundle.konzern_export.success');

        return $response;
    }

    /**
     * Async wrapper around {@see self::konzernExport()}: dispatches an
     * {@see \App\Job\ExportKonzernCertificationBundleJob}.
     *
     * Holding-level bundles aggregate per-subsidiary ZIPs and are the
     * largest export the application produces — most likely to trip
     * PHP-FPM 30s timeout in the sync path.
     *
     * Phase 3 of the async admin-jobs rollout.
     */
    #[Route('/konzern-export/dispatch', name: 'konzern_export_dispatch', methods: ['POST'])]
    #[IsGranted(new \Symfony\Component\ExpressionLanguage\Expression(
        "is_granted('ROLE_GROUP_CISO') or is_granted('ROLE_KONZERN_AUDITOR')"
    ))]
    public function konzernExportDispatch(
        Request $request,
        \App\Service\Job\JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
    ): Response {
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('certification_bundle_konzern_export_dispatch', $submittedToken)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenant = $this->security->getUser()?->getTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        if ($tenant->getSubsidiaries()->count() === 0) {
            $this->addFlash('warning', 'certification_bundle.konzern_export.error.not_a_holding');
            return $this->redirectToRoute('app_certification_bundle_index');
        }

        $activeFrameworks = $this->frameworkRepository->findActiveFrameworks();
        $selectedCodes = $this->resolveFrameworkCodes($request, $activeFrameworks);

        $asOfRaw = (string) $request->request->get('as_of_date', '');
        $asOfDate = null;
        if ($asOfRaw !== '') {
            try {
                $asOfDate = (new DateTimeImmutable($asOfRaw))->format('Y-m-d');
            } catch (\Throwable) {
                $asOfDate = null;
            }
        }

        $subsidiaryIds = null;
        $rawSubsidiaryIds = $request->request->all('subsidiary_ids');
        if (is_array($rawSubsidiaryIds) && $rawSubsidiaryIds !== []) {
            $subsidiaryIds = [];
            foreach ($rawSubsidiaryIds as $id) {
                $intId = (int) $id;
                if ($intId > 0) {
                    $subsidiaryIds[] = $intId;
                }
            }
            if ($subsidiaryIds === []) {
                $subsidiaryIds = null;
            }
        }

        $args = [
            'tenantId' => $tenant->getId(),
            'frameworks' => $selectedCodes,
            'asOfDate' => $asOfDate,
            'subsidiaryIds' => $subsidiaryIds,
            'locale' => $this->resolvePdfLocale($request),
        ];

        $jobId = $jobStatusService->create('certification_bundle.konzern_export', $args + [
            '_label' => $translator->trans('certification_bundle.export.progress_title', [], 'certification_bundle'),
            '_subtitle' => $translator->trans('certification_bundle.export.progress_subtitle', [], 'certification_bundle'),
            '_download_label' => $translator->trans('certification_bundle.export.download_button', [], 'certification_bundle'),
        ]);
        $jobStatusService->updatePayload($jobId, [
            '_download_url' => $this->generateUrl('app_certification_bundle_export_download', ['id' => $jobId]),
        ]);

        $messageBus->dispatch(new \App\Message\Job\ExecuteJobMessage(
            jobClass: \App\Job\ExportKonzernCertificationBundleJob::class,
            args: $args,
            jobId: $jobId,
        ));

        // PRG: 303 redirect — see DataRepairController::runIntegrityCheck() for rationale.
        return $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('app_certification_bundle_index'),
        ], Response::HTTP_SEE_OTHER);
    }
}
