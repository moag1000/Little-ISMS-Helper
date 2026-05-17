<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\User;
use App\Entity\WizardSession;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\WizardSessionRepository;
use App\Service\AuditLogger;
use App\Service\ComplianceWizardService;
use App\Service\MappingLibraryLoader;
use App\Service\GapEffortCalculator;
use App\Service\ModuleConfigurationService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use App\Service\WizardSessionDiffService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Compliance Wizard Controller
 *
 * Provides guided compliance assessment through existing ISMS modules.
 * Wizards analyze existing data and calculate coverage for specific frameworks.
 *
 * Features:
 * - Framework selection (ISO 27001, NIS2, DORA, TISAX, GDPR)
 * - Step-by-step guided assessment
 * - Automatic data analysis from existing modules
 * - Gap identification with actionable recommendations
 * - PDF export for management reports
 */
#[Route('/compliance-wizard')]
#[IsGranted('ROLE_AUDITOR')]
class ComplianceWizardController extends AbstractController
{
    public function __construct(
        private readonly ComplianceWizardService $wizardService,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly ?GapEffortCalculator $gapEffortCalculator = null,
        private readonly ?ComplianceFrameworkRepository $frameworkRepository = null,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?ComplianceMappingRepository $mappingRepository = null,
        private readonly ?MappingLibraryLoader $mappingLibraryLoader = null,
        private readonly ?WizardSessionRepository $wizardSessionRepository = null,
        private readonly ?PdfExportService $pdfExportService = null,
        private readonly ?WizardSessionDiffService $wizardSessionDiffService = null,
    ) {
    }

    /**
     * Wizard selection page - shows available wizards based on active modules
     */
    #[Route('', name: 'app_compliance_wizard_index', methods: ['GET'])]
    public function index(): Response
    {
        $availableWizards = $this->wizardService->getAvailableWizards();
        $activeModules = $this->moduleConfigurationService->getActiveModules();
        $allModules = $this->moduleConfigurationService->getAllModules();

        // Calculate which wizards are unavailable and why
        $unavailableWizards = $this->getUnavailableWizards($activeModules);

        return $this->render('compliance_wizard/index.html.twig', [
            'available_wizards' => $availableWizards,
            'unavailable_wizards' => $unavailableWizards,
            'active_modules' => $activeModules,
            'all_modules' => $allModules,
        ]);
    }

    /**
     * Start a specific wizard
     */
    #[Route('/{wizard}', name: 'app_compliance_wizard_start', requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra'], methods: ['GET'])]
    public function start(string $wizard): Response
    {
        if (!$this->wizardService->isWizardAvailable($wizard)) {
            $this->addFlash('warning', $this->translator->trans(
                'wizard.error.not_available',
                [],
                'wizard'
            ));
            return $this->redirectToRoute('app_compliance_wizard_index');
        }

        $config = $this->wizardService->getWizardConfig($wizard);
        $activeModules = $this->moduleConfigurationService->getActiveModules();

        // Get missing recommended modules
        $missingRecommended = array_diff(
            $config['recommended_modules'] ?? [],
            $activeModules
        );

        // On-demand data takeover hint: list other frameworks whose answers
        // can be inherited via existing mappings. The actual inheritance pass
        // runs only when the user clicks "Datenübernahme" on the start page.
        $sourceFrameworks = [];
        $availableLibraries = [];
        if ($this->frameworkRepository !== null && $this->mappingRepository !== null) {
            $targetFramework = $this->frameworkRepository->findOneBy(['code' => $config['code']]);
            if ($targetFramework !== null) {
                $sourceFrameworks = $this->mappingRepository->findSourceFrameworksMappingTo($targetFramework);
            }
        }

        // If no DB mappings exist yet, but YAML libraries are shipped for
        // this target, surface them so the user can import them in one
        // click (Alva-fairy hint: "Hier kannst du es dir einfacher machen").
        // Restricted to ROLE_MANAGER+ since import touches shared mappings.
        if ($sourceFrameworks === [] && $this->mappingLibraryLoader !== null && $this->isGranted('ROLE_MANAGER')) {
            $availableLibraries = $this->mappingLibraryLoader->discoverFixturesForTarget($config['code']);
        }

        return $this->render('compliance_wizard/start.html.twig', [
            'wizard' => $wizard,
            'config' => $config,
            'active_modules' => $activeModules,
            'missing_recommended' => $missingRecommended,
            'inheritance_source_frameworks' => $sourceFrameworks,
            'available_mapping_libraries' => $availableLibraries,
        ]);
    }

    /**
     * Run the assessment and show results
     */
    #[Route('/{wizard}/assess', name: 'app_compliance_wizard_assess', requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra'], methods: ['GET'])]
    public function assess(string $wizard): Response
    {
        if (!$this->wizardService->isWizardAvailable($wizard)) {
            $this->addFlash('warning', $this->translator->trans(
                'wizard.error.not_available',
                [],
                'wizard'
            ));
            return $this->redirectToRoute('app_compliance_wizard_index');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $result = $this->wizardService->runAssessment($wizard, $tenant);

        if (!$result['success']) {
            $this->addFlash('error', $result['error'] ?? 'Assessment failed');
            return $this->redirectToRoute('app_compliance_wizard_start', ['wizard' => $wizard]);
        }

        return $this->render('compliance_wizard/results.html.twig', [
            'wizard' => $wizard,
            'result' => $result,
        ]);
    }

    /**
     * Show detailed category results
     */
    #[Route('/{wizard}/category/{category}', name: 'app_compliance_wizard_category', requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra'], methods: ['GET'])]
    public function category(string $wizard, string $category): Response
    {
        if (!$this->wizardService->isWizardAvailable($wizard)) {
            return $this->redirectToRoute('app_compliance_wizard_index');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $result = $this->wizardService->runAssessment($wizard, $tenant);

        if (!$result['success'] || !isset($result['categories'][$category])) {
            return $this->redirectToRoute('app_compliance_wizard_assess', ['wizard' => $wizard]);
        }

        $config = $this->wizardService->getWizardConfig($wizard);

        return $this->render('compliance_wizard/category.html.twig', [
            'wizard' => $wizard,
            'config' => $config,
            'category_key' => $category,
            'category' => $result['categories'][$category],
            'overall_result' => $result,
        ]);
    }

    /**
     * API endpoint for real-time assessment (AJAX)
     */
    #[Route('/{wizard}/api/assess', name: 'app_compliance_wizard_api_assess', methods: ['GET'], requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra'])]
    public function apiAssess(string $wizard): JsonResponse
    {
        if (!$this->wizardService->isWizardAvailable($wizard)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Wizard not available',
            ], Response::HTTP_BAD_REQUEST);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $result = $this->wizardService->runAssessment($wizard, $tenant);

        return new JsonResponse($result);
    }

    /**
     * Export assessment as PDF — V3 W2-M6: real PDF via PdfExportService (DomPDF).
     */
    #[Route('/{wizard}/export/pdf', name: 'app_compliance_wizard_export_pdf', requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra'], methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function exportPdf(string $wizard): Response
    {
        if (!$this->wizardService->isWizardAvailable($wizard)) {
            return $this->redirectToRoute('app_compliance_wizard_index');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $result = $this->wizardService->runAssessment($wizard, $tenant);

        if (!$result['success']) {
            $this->addFlash('error', 'Assessment failed');
            return $this->redirectToRoute('app_compliance_wizard_assess', ['wizard' => $wizard]);
        }

        $config = $this->wizardService->getWizardConfig($wizard);

        $payload = [
            'wizard' => $wizard,
            'config' => $config,
            'result' => $result,
            'tenant' => $tenant,
            'generated_at' => new \DateTimeImmutable(),
        ];

        // V3 W2-M6: Real PDF via DomPDF if service available; HTML fallback
        // for environments where PdfExportService isn't wired (test bench).
        if ($this->pdfExportService !== null) {
            $pdf = $this->pdfExportService->generatePdf('compliance_wizard/pdf/report.html.twig', $payload);
            $filename = sprintf(
                'compliance-%s-%s.pdf',
                $wizard,
                (new \DateTimeImmutable())->format('Y-m-d'),
            );
            return new Response($pdf, Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
                'Content-Length' => (string) strlen($pdf),
            ]);
        }

        $html = $this->renderView('compliance_wizard/pdf/report.html.twig', $payload);
        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html']);
    }

    /**
     * Compare multiple frameworks
     */
    #[Route('/compare', name: 'app_compliance_wizard_compare', priority: 10, methods: ['GET'])]
    public function compare(Request $request): Response
    {
        $selectedWizards = $request->query->all('wizards') ?: ['iso27001', 'nis2', 'dora'];

        $tenant = $this->tenantContext->getCurrentTenant();
        $results = [];

        foreach ($selectedWizards as $selectedWizard) {
            if ($this->wizardService->isWizardAvailable($selectedWizard)) {
                $results[$selectedWizard] = $this->wizardService->runAssessment($selectedWizard, $tenant);
            }
        }

        $availableWizards = $this->wizardService->getAvailableWizards();

        return $this->render('compliance_wizard/compare.html.twig', [
            'results' => $results,
            'selected_wizards' => $selectedWizards,
            'available_wizards' => $availableWizards,
        ]);
    }

    /**
     * Get unavailable wizards with reasons
     */
    private function getUnavailableWizards(array $activeModules): array
    {
        $allWizards = [
            'iso27001' => [
                'code' => 'ISO27001',
                'name' => 'ISO 27001:2022 Readiness',
                'icon' => 'shield-check',
                'color' => 'primary',
                'required_modules' => ['controls'],
            ],
            'nis2' => [
                'code' => 'NIS2',
                'name' => 'NIS2 Compliance',
                'icon' => 'nav-shield-alert',
                'color' => 'warning',
                'required_modules' => ['incidents', 'controls'],
            ],
            'dora' => [
                'code' => 'DORA',
                'name' => 'DORA Readiness',
                'icon' => 'nav-building',
                'color' => 'info',
                'required_modules' => ['bcm', 'incidents', 'controls'],
            ],
            'tisax' => [
                'code' => 'TISAX',
                'name' => 'TISAX Assessment',
                'icon' => 'nav-truck',
                'color' => 'secondary',
                'required_modules' => ['controls', 'assets'],
            ],
            'gdpr' => [
                'code' => 'GDPR',
                'name' => 'GDPR/DSGVO Compliance',
                'icon' => 'nav-people',
                'color' => 'success',
                'required_modules' => ['controls'],
            ],
        ];

        $unavailable = [];

        foreach ($allWizards as $key => $allWizard) {
            $missingModules = [];
            foreach ($allWizard['required_modules'] as $requiredModule) {
                if (!in_array($requiredModule, $activeModules)) {
                    $missingModules[] = $requiredModule;
                }
            }

            if (!empty($missingModules)) {
                $unavailable[$key] = array_merge($allWizard, [
                    'missing_modules' => $missingModules,
                ]);
            }
        }

        return $unavailable;
    }

    /**
     * WS-6: Gap-Report with FTE estimates (`sort=effort|quick-wins`).
     */
    #[Route('/{wizard}/gap-report', name: 'app_compliance_wizard_gap_report', requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra'], methods: ['GET'])]
    public function gapReport(string $wizard, Request $request): Response
    {
        if ($this->gapEffortCalculator === null || $this->frameworkRepository === null) {
            throw $this->createNotFoundException('Gap report service not available.');
        }
        if (!$this->wizardService->isWizardAvailable($wizard)) {
            return $this->redirectToRoute('app_compliance_wizard_index');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $config = $this->wizardService->getWizardConfig($wizard);
        $framework = $this->frameworkRepository->findOneBy(['code' => strtoupper((string) ($config['framework_code'] ?? $wizard))])
            ?? $this->frameworkRepository->findOneBy(['code' => strtoupper($wizard)]);
        if ($framework === null || $tenant === null) {
            throw $this->createNotFoundException();
        }

        $sort = (string) $request->query->get('sort', GapEffortCalculator::SORT_REMAINING_EFFORT);
        if (!in_array($sort, [GapEffortCalculator::SORT_REMAINING_EFFORT, GapEffortCalculator::SORT_QUICK_WINS], true)) {
            $sort = GapEffortCalculator::SORT_REMAINING_EFFORT;
        }

        $rows = $this->gapEffortCalculator->calculate($tenant, $framework, $sort);
        $summary = $this->gapEffortCalculator->calculateTotalEffort($tenant, $framework);

        return $this->render('compliance_wizard/gap_report.html.twig', [
            'wizard' => $wizard,
            'config' => $config,
            'framework' => $framework,
            'rows' => $rows,
            'summary' => $summary,
            'sort' => $sort,
        ]);
    }

    /**
     * WS-6: Tenant-specific FTE override on a single fulfillment.
     * Requires CSRF, min. 20-char reason, audit-log entry (ISO 27001 A.5.36).
     */
    #[Route('/fulfillment/{id}/override-effort', name: 'app_compliance_wizard_override_effort', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function overrideEffort(ComplianceRequirementFulfillment $fulfillment, Request $request): Response
    {
        if ($this->auditLogger === null || $this->entityManager === null) {
            throw $this->createNotFoundException('Override service not available.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $fulfillment->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Tenant mismatch.');
        }

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('override_effort_' . $fulfillment->getId(), $token)) {
            $this->addFlash('danger', 'gap_report.flash.invalid_csrf');
            return $this->redirect($request->headers->get('referer', $this->generateUrl('app_compliance_wizard_index')));
        }

        $days = (int) $request->request->get('days', 0);
        $reason = trim((string) $request->request->get('reason', ''));
        if ($days < 0 || $days > 999) {
            $this->addFlash('danger', 'gap_report.flash.invalid_days');
            return $this->redirect($request->headers->get('referer', $this->generateUrl('app_compliance_wizard_index')));
        }
        if (mb_strlen($reason) < 20) {
            $this->addFlash('danger', 'gap_report.flash.reason_too_short');
            return $this->redirect($request->headers->get('referer', $this->generateUrl('app_compliance_wizard_index')));
        }

        $oldDays = method_exists($fulfillment, 'getAdjustedEffortDays') ? $fulfillment->getAdjustedEffortDays() : null;
        if (method_exists($fulfillment, 'setAdjustedEffortDays')) {
            $fulfillment->setAdjustedEffortDays($days);
        }
        if (method_exists($fulfillment, 'setAdjustedEffortReason')) {
            $fulfillment->setAdjustedEffortReason($reason);
        }
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'compliance.fulfillment.effort_override',
            'ComplianceRequirementFulfillment',
            $fulfillment->getId(),
            ['adjusted_effort_days' => $oldDays],
            ['adjusted_effort_days' => $days, 'reason' => $reason],
            sprintf('Effort override on fulfillment #%d: %s → %s days', $fulfillment->getId(), $oldDays ?? 'null', $days),
        );

        $this->addFlash('success', 'gap_report.flash.override_saved');
        return $this->redirect($request->headers->get('referer', $this->generateUrl('app_compliance_wizard_index')));
    }

    /**
     * WS-7: Compare-PDF export — renders multi-framework comparison.
     *
     * V3 W2-M7: dynamic allow-list = all currently available wizards
     * (capped at 10 frameworks for PDF readability), no longer hardcoded
     * to 5. V3 W2-M6: real PDF via DomPDF when PdfExportService present.
     */
    #[Route('/compare/export/pdf', name: 'app_compliance_wizard_compare_export_pdf', priority: 10, methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function compareExportPdf(Request $request): Response
    {
        // V3 W2-M7: derive allow-list from available wizards rather than
        // hardcoded 5. ComplianceWizardService::getAvailableWizards() returns
        // an associative array keyed by wizard code.
        $availableKeys = array_keys($this->wizardService->getAvailableWizards());
        $requested = array_filter(
            (array) $request->query->all('wizards'),
            static fn($v) => is_string($v) && $v !== '',
        );
        $selected = array_values(array_intersect($requested, $availableKeys));
        if ($selected === []) {
            $selected = array_slice($availableKeys, 0, 3);
        }
        // Cap at 10 frameworks for PDF readability
        $selected = array_slice($selected, 0, 10);

        $tenant = $this->tenantContext->getCurrentTenant();
        $results = [];
        foreach ($selected as $wizard) {
            if ($this->wizardService->isWizardAvailable($wizard)) {
                $results[$wizard] = $this->wizardService->runAssessment($wizard, $tenant);
            }
        }

        $payload = [
            'wizards' => $selected,
            'results' => $results,
            'tenant' => $tenant,
            'generated_at' => new \DateTimeImmutable(),
        ];

        if ($this->pdfExportService !== null) {
            $pdf = $this->pdfExportService->generatePdf('compliance_wizard/pdf/compare.html.twig', $payload);
            $filename = sprintf('compliance-compare-%s.pdf', (new \DateTimeImmutable())->format('Y-m-d'));
            return new Response($pdf, Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
                'Content-Length' => (string) strlen($pdf),
            ]);
        }

        $html = $this->renderView('compliance_wizard/pdf/compare.html.twig', $payload);
        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html']);
    }

    /**
     * Import a shipped YAML mapping library into the DB so the wizard's
     * "Datenübernahme" banner appears on subsequent visits. Tied to the
     * fixture discovery in start(): the user must select one of the
     * fixtures the loader surfaced; the controller verifies the chosen
     * file lives below fixtures/library/mappings/.
     */
    #[Route('/{wizard}/import-mapping-library', name: 'app_compliance_wizard_import_mapping_library', methods: ['POST'], requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra'])]
    #[IsGranted('ROLE_MANAGER')]
    public function importMappingLibrary(string $wizard, Request $request): Response
    {
        if ($this->mappingLibraryLoader === null) {
            $this->addFlash('error', $this->translator->trans('wizard.mapping_library.unavailable', [], 'wizard'));
            return $this->redirectToRoute('app_compliance_wizard_start', ['wizard' => $wizard]);
        }

        $config = $this->wizardService->getWizardConfig($wizard);
        if ($config === null) {
            return $this->redirectToRoute('app_compliance_wizard_index');
        }

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('mapping-lib-' . $config['code'], $token)) {
            $this->addFlash('error', $this->translator->trans('wizard.mapping_library.csrf_invalid', [], 'wizard'));
            return $this->redirectToRoute('app_compliance_wizard_start', ['wizard' => $wizard]);
        }

        $requestedPath = (string) $request->request->get('path');
        $available = $this->mappingLibraryLoader->discoverFixturesForTarget($config['code']);
        $allowedPaths = array_column($available, 'path');
        if (!in_array($requestedPath, $allowedPaths, true)) {
            $this->addFlash('error', $this->translator->trans('wizard.mapping_library.invalid_path', [], 'wizard'));
            return $this->redirectToRoute('app_compliance_wizard_start', ['wizard' => $wizard]);
        }

        $result = $this->mappingLibraryLoader->load($requestedPath);

        if (!$result['success']) {
            $this->addFlash('error', $this->translator->trans('wizard.mapping_library.load_failed', [
                '%errors%' => implode('; ', $result['errors']),
            ], 'wizard'));
            return $this->redirectToRoute('app_compliance_wizard_start', ['wizard' => $wizard]);
        }

        if ($this->auditLogger !== null) {
            $this->auditLogger->logCustom(
                'compliance.mapping_library.imported',
                'ComplianceMapping',
                null,
                null,
                [
                    'fixture' => basename($requestedPath),
                    'target_framework' => $config['code'],
                    'imported' => $result['imported'],
                    'updated' => $result['updated'],
                    'skipped' => $result['skipped'],
                ],
                sprintf('Imported mapping library %s into %s', basename($requestedPath), $config['code']),
            );
        }

        $this->addFlash('success', $this->translator->trans('wizard.mapping_library.imported', [
            '%imported%' => $result['imported'],
            '%updated%' => $result['updated'],
            '%skipped%' => $result['skipped'],
        ], 'wizard'));

        return $this->redirectToRoute('app_compliance_wizard_start', ['wizard' => $wizard]);
    }

    /**
     * V3 B5 / EF-2: Persist a snapshot of the current assessment.
     *
     * Compliance-Wizard rechnet bisher jeden Aufruf neu. Ein Snapshot speichert
     * Coverage + Gaps + KPIs als WizardSession, sodass Trend-Charts und
     * Stichtag-Vergleich möglich werden.
     */
    #[Route('/{wizard}/snapshot', name: 'app_compliance_wizard_snapshot', methods: ['POST'], requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra'])]
    public function snapshot(string $wizard, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wizard_snapshot_' . $wizard, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_compliance_wizard_assess', ['wizard' => $wizard]);
        }

        if (!$this->wizardService->isWizardAvailable($wizard)) {
            return $this->redirectToRoute('app_compliance_wizard_index');
        }

        if ($this->entityManager === null || $this->wizardSessionRepository === null) {
            $this->addFlash('warning', 'Snapshot not available — wizard infrastructure missing.');
            return $this->redirectToRoute('app_compliance_wizard_assess', ['wizard' => $wizard]);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $user = $this->getUser();
        if (!$user instanceof User || $tenant === null) {
            $this->addFlash('error', 'Snapshot requires authenticated user with tenant.');
            return $this->redirectToRoute('app_compliance_wizard_assess', ['wizard' => $wizard]);
        }

        $result = $this->wizardService->runAssessment($wizard, $tenant);
        if (!($result['success'] ?? false)) {
            $this->addFlash('error', $result['error'] ?? 'Assessment failed.');
            return $this->redirectToRoute('app_compliance_wizard_assess', ['wizard' => $wizard]);
        }

        $session = new WizardSession();
        $session->setTenant($tenant);
        $session->setUser($user);
        $session->setWizardType($this->mapWizardCodeToSessionType($wizard));
        $session->setStatus(WizardSession::STATUS_COMPLETED);
        $session->setCurrentStep(count($result['categories'] ?? []));
        $session->setTotalSteps(max(1, count($result['categories'] ?? [])));
        $session->setOverallScore((int) ($result['overall_score'] ?? 0));
        $session->setAssessmentResults($result['categories'] ?? []);
        $session->setRecommendations($result['recommendations'] ?? []);
        $session->setCriticalGaps($result['critical_gaps'] ?? []);
        $session->setCompletedCategories(array_keys($result['categories'] ?? []));
        $session->complete();

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        if ($this->auditLogger !== null) {
            $this->auditLogger->logCreate(
                'WizardSession',
                $session->getId(),
                [
                    'wizard' => $wizard,
                    'overallScore' => $session->getOverallScore(),
                    'snapshot' => true,
                ],
                'Compliance-wizard snapshot saved'
            );
        }

        $this->addFlash('success', $this->translator->trans('wizard.snapshot.saved', [], 'wizard'));
        return $this->redirectToRoute('app_compliance_wizard_history', ['wizard' => $wizard]);
    }

    /**
     * V3 B5 / EF-2: Snapshot history with trend chart.
     */
    #[Route('/{wizard}/history', name: 'app_compliance_wizard_history', requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra'], methods: ['GET'])]
    public function history(string $wizard): Response
    {
        if (!$this->wizardService->isWizardAvailable($wizard)) {
            return $this->redirectToRoute('app_compliance_wizard_index');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $sessions = [];
        if ($this->wizardSessionRepository !== null && $tenant !== null) {
            $sessionType = $this->mapWizardCodeToSessionType($wizard);
            $sessions = $this->wizardSessionRepository->findBy(
                ['tenant' => $tenant, 'wizardType' => $sessionType, 'status' => WizardSession::STATUS_COMPLETED],
                ['completedAt' => 'DESC']
            );
        }

        $config = $this->wizardService->getWizardConfig($wizard);
        $trendPoints = [];
        foreach (array_reverse($sessions) as $session) {
            $trendPoints[] = [
                'date' => $session->getCompletedAt()?->format('Y-m-d') ?? $session->getCreatedAt()?->format('Y-m-d'),
                'score' => $session->getOverallScore(),
            ];
        }

        return $this->render('compliance_wizard/history.html.twig', [
            'wizard' => $wizard,
            'config' => $config,
            'sessions' => $sessions,
            'trend_points' => $trendPoints,
        ]);
    }

    /**
     * V4-EF-3: Diff view — compare two WizardSession snapshots side-by-side.
     *
     * Route: GET /compliance-wizard/{wizard}/diff/{fromId}/{toId}
     * Access: ROLE_AUDITOR (same as other wizard routes)
     * Tenant isolation: both sessions must belong to the current tenant.
     */
    #[Route(
        '/{wizard}/diff/{fromId}/{toId}',
        name: 'app_compliance_wizard_diff',
        requirements: [
            'wizard' => 'iso27001|nis2|dora|tisax|gdpr|iso22301|iso27701|iso27017|iso27018|iso42001|bsi_grundschutz|bsi_c5|bsi_c5_2026|bsi_grundschutz_standard|bsi_grundschutz_kern|nist_csf|kritis|pci_dss|soc2|eu_ai_act|eucs|cra',
            'fromId' => '\d+',
            'toId'   => '\d+',
        ],
        methods: ['GET'],
    )]
    public function diff(string $wizard, int $fromId, int $toId): Response
    {
        if ($this->wizardSessionRepository === null || $this->wizardSessionDiffService === null) {
            $this->addFlash('warning', $this->translator->trans('diff.unavailable', [], 'compliance_wizard'));
            return $this->redirectToRoute('app_compliance_wizard_history', ['wizard' => $wizard]);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context.');
        }

        /** @var WizardSession|null $fromSession */
        $fromSession = $this->wizardSessionRepository->find($fromId);
        /** @var WizardSession|null $toSession */
        $toSession   = $this->wizardSessionRepository->find($toId);

        if ($fromSession === null || $toSession === null) {
            throw $this->createNotFoundException('One or both snapshots not found.');
        }

        // Tenant isolation
        if (
            $fromSession->getTenant()?->getId() !== $tenant->getId()
            || $toSession->getTenant()?->getId() !== $tenant->getId()
        ) {
            throw $this->createAccessDeniedException('Snapshot tenant mismatch.');
        }

        // Wizard type must match
        $sessionType = $this->mapWizardCodeToSessionType($wizard);
        if (
            $fromSession->getWizardType() !== $sessionType
            || $toSession->getWizardType() !== $sessionType
        ) {
            $this->addFlash('warning', $this->translator->trans('diff.wizard_mismatch', [], 'compliance_wizard'));
            return $this->redirectToRoute('app_compliance_wizard_history', ['wizard' => $wizard]);
        }

        $config  = $this->wizardService->getWizardConfig($wizard);
        $diffData = $this->wizardSessionDiffService->diff($fromSession, $toSession);

        return $this->render('compliance_wizard/diff.html.twig', [
            'wizard'       => $wizard,
            'config'       => $config,
            'from_session' => $fromSession,
            'to_session'   => $toSession,
            'diff'         => $diffData,
        ]);
    }

    /**
     * Map wizard codes 1:1 to WizardSession slot. V3 W2-M1: Each of the 22
     * supported wizards now has its own slot — History/Trend no longer
     * collapse 16 wizards into the ISO27001 bucket. Falls back to ISO27001
     * for unknown codes (defensive default).
     */
    private function mapWizardCodeToSessionType(string $wizard): string
    {
        return in_array($wizard, WizardSession::ALL_WIZARDS, true)
            ? $wizard
            : WizardSession::WIZARD_ISO27001;
    }
}
