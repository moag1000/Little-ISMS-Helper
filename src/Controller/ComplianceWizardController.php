<?php

namespace App\Controller;

use App\Entity\ComplianceRequirementFulfillment;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\AuditLogger;
use App\Service\ComplianceWizardService;
use App\Service\GapEffortCalculator;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
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
    ) {
    }

    /**
     * Wizard selection page - shows available wizards based on active modules
     */
    #[Route('', name: 'app_compliance_wizard_index')]
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
    #[Route('/{wizard}', name: 'app_compliance_wizard_start', requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr'])]
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

        return $this->render('compliance_wizard/start.html.twig', [
            'wizard' => $wizard,
            'config' => $config,
            'active_modules' => $activeModules,
            'missing_recommended' => $missingRecommended,
        ]);
    }

    /**
     * Run the assessment and show results
     */
    #[Route('/{wizard}/assess', name: 'app_compliance_wizard_assess', requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr'])]
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
    #[Route('/{wizard}/category/{category}', name: 'app_compliance_wizard_category')]
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
    #[Route('/{wizard}/api/assess', name: 'app_compliance_wizard_api_assess', methods: ['GET'])]
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
     * Export assessment as PDF
     */
    #[Route('/{wizard}/export/pdf', name: 'app_compliance_wizard_export_pdf')]
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

        // Render PDF template
        $html = $this->renderView('compliance_wizard/pdf/report.html.twig', [
            'wizard' => $wizard,
            'config' => $config,
            'result' => $result,
            'tenant' => $tenant,
            'generated_at' => new \DateTimeImmutable(),
        ]);

        // For now, return HTML preview (PDF generation can be added later with DomPDF/wkhtmltopdf)
        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html',
        ]);
    }

    /**
     * Compare multiple frameworks
     */
    #[Route('/compare', name: 'app_compliance_wizard_compare', priority: 10)]
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
                'icon' => 'bi-shield-check',
                'color' => 'primary',
                'required_modules' => ['controls'],
            ],
            'nis2' => [
                'code' => 'NIS2',
                'name' => 'NIS2 Compliance',
                'icon' => 'bi-shield-exclamation',
                'color' => 'warning',
                'required_modules' => ['incidents', 'controls'],
            ],
            'dora' => [
                'code' => 'DORA',
                'name' => 'DORA Readiness',
                'icon' => 'bi-bank',
                'color' => 'info',
                'required_modules' => ['bcm', 'incidents', 'controls'],
            ],
            'tisax' => [
                'code' => 'TISAX',
                'name' => 'TISAX Assessment',
                'icon' => 'bi-car-front',
                'color' => 'secondary',
                'required_modules' => ['controls', 'assets'],
            ],
            'gdpr' => [
                'code' => 'GDPR',
                'name' => 'GDPR/DSGVO Compliance',
                'icon' => 'bi-person-lock',
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
    #[Route('/{wizard}/gap-report', name: 'app_compliance_wizard_gap_report', requirements: ['wizard' => 'iso27001|nis2|dora|tisax|gdpr'])]
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
     * WS-7: Compare-PDF export — renders multi-framework comparison as HTML (PDF-ready).
     */
    #[Route('/compare/export/pdf', name: 'app_compliance_wizard_compare_export_pdf', priority: 10)]
    #[IsGranted('ROLE_MANAGER')]
    public function compareExportPdf(Request $request): Response
    {
        $allowed = ['iso27001', 'nis2', 'dora', 'tisax', 'gdpr'];
        $selected = array_values(array_intersect(
            (array) $request->query->all('wizards'),
            $allowed,
        ));
        if ($selected === []) {
            $selected = ['iso27001', 'nis2', 'dora'];
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $results = [];
        foreach ($selected as $wizard) {
            if ($this->wizardService->isWizardAvailable($wizard)) {
                $results[$wizard] = $this->wizardService->runAssessment($wizard, $tenant);
            }
        }

        $html = $this->renderView('compliance_wizard/pdf/compare.html.twig', [
            'wizards' => $selected,
            'results' => $results,
            'tenant' => $tenant,
            'generated_at' => new \DateTimeImmutable(),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html']);
    }
}
