<?php

namespace App\Controller;

use DateTime;
use DateTimeImmutable;
use App\Entity\Control;
use App\Entity\User;
use App\Entity\Tenant;
use App\Form\ControlType;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Service\AnnexAControlsBootstrapService;
use App\Service\AuditLogger;
use App\Service\MappingSuggestionService;
use App\Service\ModuleConfigurationService;
use App\Service\SoAReportService;
use App\Service\TagFilterService;
use App\Service\WorkflowAutoProgressionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class StatementOfApplicabilityController extends AbstractController
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly SoAReportService $soaReportService,
        private readonly Security $security,
        private readonly WorkflowAutoProgressionService $workflowAutoProgressionService,
        private readonly TagFilterService $tagFilterService,
        private readonly MappingSuggestionService $mappingSuggestionService,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly AnnexAControlsBootstrapService $annexBootstrap,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}
    #[Route('/soa/', name: 'app_soa_index')]
    public function index(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get filter parameters — URL-persisted so SoA views are shareable/bookmarkable (UXC-11)
        $view = $request->query->get('view', 'inherited'); // Default: inherited
        $q = trim((string) $request->query->get('q', ''));
        $category = $request->query->get('category');
        $status = $request->query->get('status');
        // MRIS-Mythos-Klassifikation gem. Peddi (2026) MRIS v1.5 Anhang A.
        // Werte: standfest|degradiert|reibung|nicht_betroffen.
        $mris = $request->query->get('mris');

        // Get controls based on view filter
        if ($tenant) {
            $controls = match ($view) {
                'own' => $this->controlRepository->findByTenant($tenant),
                'subsidiaries' => $this->controlRepository->findByTenantIncludingSubsidiaries($tenant),
                default => $this->controlRepository->findByTenantIncludingParent($tenant),
            };
            // Sort by ISO order using natural sort for proper numeric ordering (A.5.2 before A.5.10)
            usort($controls, function($a, $b): int {
                $aRef = $a->getIsoReference() ?? $a->getControlId() ?? '';
                $bRef = $b->getIsoReference() ?? $b->getControlId() ?? '';
                return strnatcmp($aRef, $bRef);
            });
            $inheritanceInfo = [
                'hasParent' => $tenant->getParent() !== null,
                'hasSubsidiaries' => $tenant->getSubsidiaries()->count() > 0,
                'currentView' => $view
            ];
        } else {
            $controls = [];
            $inheritanceInfo = [
                'hasParent' => false,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        // WS-5: framework-tag filter via ?tag=NIS2
        $tagFilter = $request->query->get('tag');
        if (is_string($tagFilter) && $tagFilter !== '') {
            $controls = $this->tagFilterService->filterByTagName($controls, Control::class, $tagFilter);
        }

        if ($category) {
            $controls = array_filter($controls, fn(Control $c): bool => $c->getCategory() === $category);
        }
        if ($status) {
            $controls = array_filter($controls, fn(Control $c): bool => $c->getImplementationStatus() === $status);
        }
        if (is_string($mris) && $mris !== '') {
            $controls = array_filter($controls, fn(Control $c): bool => $c->getMythosResilience() === $mris);
        }
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $controls = array_filter($controls, function (Control $c) use ($needle): bool {
                $haystack = mb_strtolower(
                    ($c->getName() ?? '')
                    . ' ' . ($c->getDescription() ?? '')
                    . ' ' . ($c->getIsoReference() ?? '')
                    . ' ' . ($c->getControlId() ?? '')
                );
                return str_contains($haystack, $needle);
            });
        }
        $controls = array_values($controls);

        $stats = $tenant
            ? $this->controlRepository->getImplementationStats($tenant)
            : ['total' => 0, 'implemented' => 0, 'in_progress' => 0, 'not_started' => 0, 'not_applicable' => 0];
        $categoryStats = $tenant
            ? $this->controlRepository->countByCategory($tenant)
            : [];

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($controls, $tenant);
        } else {
            $detailedStats = ['own' => count($controls), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($controls)];
        }

        // MRIS-Verteilung pro Kategorie (alle Controls, nicht gefiltert) — für UI-Filter-Counts
        $mrisStats = ['standfest' => 0, 'degradiert' => 0, 'reibung' => 0, 'nicht_betroffen' => 0];
        if ($tenant) {
            $allControls = match ($view) {
                'own' => $this->controlRepository->findByTenant($tenant),
                'subsidiaries' => $this->controlRepository->findByTenantIncludingSubsidiaries($tenant),
                default => $this->controlRepository->findByTenantIncludingParent($tenant),
            };
            foreach ($allControls as $c) {
                $cat = $c->getMythosResilience();
                if ($cat !== null && isset($mrisStats[$cat])) {
                    $mrisStats[$cat]++;
                }
            }
        }

        return $this->render('soa/index.html.twig', [
            'controls' => $controls,
            'stats' => $stats,
            'categoryStats' => $categoryStats,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
            'mrisStats' => $mrisStats,
            'mrisFilter' => $mris,
        ]);
    }
    #[Route('/soa/category/{category}', name: 'app_soa_by_category')]
    public function byCategory(string $category): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Auto-bootstrap: if the controls module is active but this tenant
        // has zero Annex-A controls, seed them. Rationale: a tenant with
        // ISO 27001 active should never see an empty SoA — that's the
        // first-impression failure the junior flagged.
        $autoSeeded = 0;
        $controlsModuleActive = $this->moduleConfiguration->isModuleActive('controls');
        if ($tenant instanceof Tenant && $controlsModuleActive && !$this->annexBootstrap->isTenantSeeded($tenant)) {
            $autoSeeded = $this->annexBootstrap->ensureLoadedForTenant($tenant);
        }

        $controls = $this->controlRepository->findByCategoryInIsoOrder($category, $tenant);
        $totalForTenant = $tenant instanceof Tenant
            ? $this->controlRepository->count(['tenant' => $tenant])
            : 0;

        return $this->render('soa/category.html.twig', [
            'category' => $category,
            'controls' => $controls,
            'tenant' => $tenant,
            'total_for_tenant' => $totalForTenant,
            'auto_seeded' => $autoSeeded,
            'iso_27001_active' => $controlsModuleActive,
        ]);
    }
    #[Route('/soa/report/export', name: 'app_soa_export')]
    public function export(Request $request): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();
        $controls = $tenant
            ? $this->controlRepository->findAllInIsoOrder($tenant)
            : [];

        // Close session to prevent blocking other requests
        $request->getSession()->save();

        return $this->render('soa/export.html.twig', [
            'controls' => $controls,
            'generatedAt' => new DateTime(),
        ]);
    }
    #[Route('/soa/report/pdf', name: 'app_soa_export_pdf')]
    public function exportPdf(Request $request): Response
    {
        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        return $this->soaReportService->downloadSoAReport();
    }
    #[Route('/soa/report/pdf/preview', name: 'app_soa_preview_pdf')]
    public function previewPdf(): Response
    {
        return $this->soaReportService->streamSoAReport();
    }
    #[Route('/soa/{id}', name: 'app_soa_show', requirements: ['id' => '\d+'])]
    public function show(Control $control): Response
    {
        $suggestions = $this->mappingSuggestionService->suggestForControl($control);
        return $this->render('soa/show.html.twig', [
            'control' => $control,
            'mapping_suggestions' => $suggestions,
            'mapping_suggestions_total' => $this->mappingSuggestionService->totalCount($suggestions),
        ]);
    }

    /**
     * Admin bootstrap: seed the 93 Annex-A controls for the current tenant.
     * Hooked from the SoA empty-state banner and the admin dashboard fix
     * CTA. Idempotent — re-running is a no-op after initial load.
     */
    #[Route('/soa/bootstrap/annex-a', name: 'app_soa_bootstrap_annex_a', methods: ['POST'])]
    public function bootstrapAnnexA(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('bootstrap_annex_a', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_soa_index');
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();
        if (!$tenant instanceof Tenant) {
            $this->addFlash('warning', $this->translator->trans('soa.empty.no_tenant', [], 'soa'));
            return $this->redirectToRoute('app_soa_index');
        }

        $created = $this->annexBootstrap->ensureLoadedForTenant($tenant);
        if ($created > 0) {
            $this->addFlash('success', $this->translator->trans('soa.empty.loaded_flash', ['%count%' => $created], 'soa'));
        } else {
            $this->addFlash('info', $this->translator->trans('soa.empty.already_loaded_flash', [], 'soa'));
        }

        $referer = $request->headers->get('referer');
        if ($referer !== null && str_contains($referer, '/soa/')) {
            return $this->redirect($referer);
        }
        return $this->redirectToRoute('app_soa_index');
    }

    /**
     * Bulk update applicability for a set of Controls (Sprint 3 / C3).
     * Accepts selected control IDs + new applicable status + reason.
     * Every row change is recorded in the audit log with actor + reason.
     */
    #[Route('/soa/bulk/applicability', name: 'app_soa_bulk_applicability', methods: ['POST'])]
    public function bulkApplicability(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('soa_bulk_applicability', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ids = $request->request->all('control_ids');
        if (!is_array($ids) || $ids === []) {
            $this->addFlash('warning', $this->translator->trans('control.bulk.flash.no_selection', [], 'control'));
            return $this->redirectToRoute('app_soa_index');
        }

        $applicable = $request->request->getBoolean('applicable');
        $reason = trim((string) $request->request->get('reason', ''));
        if (!$applicable && $reason === '') {
            $this->addFlash('warning', $this->translator->trans('control.bulk.flash.reason_required', [], 'control'));
            return $this->redirectToRoute('app_soa_index');
        }

        $tenant = $this->security->getUser()?->getTenant();
        $updated = 0;
        foreach (array_filter(array_map('intval', $ids)) as $controlId) {
            $control = $this->controlRepository->find($controlId);
            if (!$control instanceof Control) {
                continue;
            }
            if ($tenant !== null && $control->getTenant()?->getId() !== $tenant->getId()) {
                continue; // tenant isolation
            }
            $previous = $control->isApplicable();
            if ($previous === $applicable) {
                continue;
            }
            $control->setApplicable($applicable);
            if (!$applicable && $reason !== '') {
                $control->setJustification($reason);
            }
            $control->setUpdatedAt(new DateTimeImmutable());
            $updated++;

            if ($this->auditLogger !== null) {
                try {
                    $this->auditLogger->logCustom(
                        action: 'control.bulk_applicability',
                        entityType: 'Control',
                        entityId: $controlId,
                        oldValues: ['applicable' => $previous],
                        newValues: ['applicable' => $applicable, 'reason' => $reason],
                        description: sprintf(
                            'Bulk-Applicability: control %s → %s',
                            (string) $control->getControlId(),
                            $applicable ? 'applicable' : 'N/A'
                        ),
                    );
                } catch (\Throwable) {
                    // audit log failure must not block the bulk operation
                }
            }
        }

        $this->entityManager->flush();

        $this->addFlash(
            'success',
            $this->translator->trans(
                'control.bulk.flash.updated',
                ['%count%' => $updated, '%status%' => $applicable ? 'applicable' : 'N/A'],
                'control'
            )
        );
        return $this->redirectToRoute('app_soa_index');
    }

    /**
     * Accept an A2 auto-mapping suggestion — creates the M:M link between
     * the Control and the suggested ComplianceRequirement and records the
     * acceptance in the audit log so the decision is reviewable later.
     */
    #[Route(
        '/soa/{id}/accept-suggestion/{requirementId}',
        name: 'app_soa_accept_suggestion',
        requirements: ['id' => '\d+', 'requirementId' => '\d+'],
        methods: ['POST']
    )]
    public function acceptSuggestion(Request $request, Control $control, int $requirementId): Response
    {
        if (!$this->isCsrfTokenValid('accept_suggestion_' . $control->getId() . '_' . $requirementId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $requirement = $this->requirementRepository->find($requirementId);
        if ($requirement === null) {
            throw $this->createNotFoundException('Requirement not found.');
        }

        $confidence = $request->request->get('confidence');
        $confidenceFloat = is_numeric($confidence) ? round((float) $confidence, 4) : null;

        if (!$requirement->getMappedControls()->contains($control)) {
            $requirement->addMappedControl($control);
            $this->entityManager->flush();
        }

        if ($this->auditLogger !== null) {
            try {
                $this->auditLogger->logCustom(
                    action: 'mapping_suggestion_accepted',
                    entityType: 'Control',
                    entityId: $control->getId(),
                    oldValues: null,
                    newValues: [
                        'control_id' => $control->getControlId(),
                        'requirement_id' => $requirement->getRequirementId(),
                        'framework' => $requirement->getFramework()?->getCode(),
                        'confidence' => $confidenceFloat,
                        'source' => 'A2_auto_mapping_suggestion',
                    ],
                    description: sprintf(
                        'A2 Auto-Mapping accepted: control %s ↔ %s (confidence %.2f)',
                        (string) $control->getControlId(),
                        (string) $requirement->getRequirementId(),
                        $confidenceFloat ?? 0.0
                    ),
                );
            } catch (\Throwable) {
                // Audit log failure must not block the mapping acceptance.
            }
        }

        $this->addFlash(
            'success',
            $this->translator->trans('control.flash.mapping_accepted', [
                '%requirement%' => (string) $requirement->getRequirementId(),
            ], 'control')
        );

        return $this->redirectToRoute('app_soa_show', ['id' => $control->getId()]);
    }
    #[Route('/soa/{id}/edit', name: 'app_soa_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Control $control): Response
    {
        $form = $this->createForm(ControlType::class, $control, [
            'allow_control_id_edit' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $control->setUpdatedAt(new DateTimeImmutable());

            $this->entityManager->flush();

            // Check and auto-progress workflow if conditions are met
            $currentUser = $this->security->getUser();
            if ($currentUser instanceof User) {
                $this->workflowAutoProgressionService->checkAndProgressWorkflow($control, $currentUser);
            }

            $this->addFlash('success', $this->translator->trans('control.success.updated', [], 'control'));

            return $this->redirectToRoute('app_soa_show', ['id' => $control->getId()]);
        }

        return $this->render('soa/edit.html.twig', [
            'control' => $control,
            'form' => $form,
        ]);
    }
    /**
     * Calculate detailed statistics showing breakdown by origin
     */
    private function calculateDetailedStats(array $items, $currentTenant): array
    {
        $ownCount = 0;
        $inheritedCount = 0;
        $subsidiariesCount = 0;

        // Get ancestors and subsidiaries for comparison
        $ancestors = $currentTenant->getAllAncestors();
        $ancestorIds = array_map(fn($t) => $t->getId(), $ancestors);

        $subsidiaries = $currentTenant->getAllSubsidiaries();
        $subsidiaryIds = array_map(fn($t) => $t->getId(), $subsidiaries);

        foreach ($items as $item) {
            $itemTenant = $item->getTenant();
            if (!$itemTenant) {
                continue;
            }

            $itemTenantId = $itemTenant->getId();
            $currentTenantId = $currentTenant->getId();

            if ($itemTenantId === $currentTenantId) {
                $ownCount++;
            } elseif (in_array($itemTenantId, $ancestorIds)) {
                $inheritedCount++;
            } elseif (in_array($itemTenantId, $subsidiaryIds)) {
                $subsidiariesCount++;
            }
        }

        return [
            'own' => $ownCount,
            'inherited' => $inheritedCount,
            'subsidiaries' => $subsidiariesCount,
            'total' => $ownCount + $inheritedCount + $subsidiariesCount
        ];
    }
}
