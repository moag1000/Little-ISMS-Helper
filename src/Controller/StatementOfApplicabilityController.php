<?php

namespace App\Controller;

use DateTime;
use DateTimeImmutable;
use App\Entity\Control;
use App\Entity\User;
use App\Form\ControlType;
use App\Repository\ControlRepository;
use App\Service\SoAReportService;
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
        private readonly WorkflowAutoProgressionService $workflowAutoProgressionService
    ) {}
    #[Route('/soa/', name: 'app_soa_index')]
    public function index(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get view filter parameter
        $view = $request->query->get('view', 'inherited'); // Default: inherited

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
            $controls = $this->controlRepository->findAllInIsoOrder();
            $inheritanceInfo = [
                'hasParent' => false,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        $stats = $this->controlRepository->getImplementationStats();
        $categoryStats = $this->controlRepository->countByCategory();

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
        $controls = $this->controlRepository->findAllInIsoOrder();

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
    #[Route('/soa/{id}', name: 'app_soa_show')]
    public function show(Control $control): Response
    {
        return $this->render('soa/show.html.twig', [
            'control' => $control,
        ]);
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
