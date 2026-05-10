<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\PdfLocaleTrait;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Export\CertificationBundleExporter;
use App\Service\TenantContext;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Certification Bundle Controller
 *
 * Provides a one-click export of all ISMS documentation required for
 * ISO 27001 certification audits. The bundle includes SoA, risk treatment
 * plans, asset register, evidence documents, and gap analysis.
 */
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
    ) {
    }

    /**
     * Preview page showing bundle contents and counts before generation.
     *
     * Audit-V3 EF-3: framework-aware. `?framework=BSI_GRUNDSCHUTZ` selects
     * a non-default framework for the bundle metadata. Only active
     * frameworks are accepted; unknown codes fall back to ISO27001.
     */
    #[Route('', name: 'index')]
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
}
