<?php

namespace App\Controller;

use DateTime;
use DateTimeImmutable;
use App\Entity\Control;
use App\Entity\User;
use App\Form\ControlType;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Service\AuditLogger;
use App\Service\MappingSuggestionService;
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

        return $this->render('soa/index.html.twig', [
            'controls' => $controls,
            'stats' => $stats,
            'categoryStats' => $categoryStats,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }
    #[Route('/soa/category/{category}', name: 'app_soa_by_category')]
    public function byCategory(string $category): Response
    {
        $controls = $this->controlRepository->findByCategoryInIsoOrder($category);

        return $this->render('soa/category.html.twig', [
            'category' => $category,
            'controls' => $controls,
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
