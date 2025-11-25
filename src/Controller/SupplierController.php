<?php

namespace App\Controller;

use App\Entity\Supplier;
use App\Form\SupplierType;
use App\Repository\SupplierRepository;
use App\Service\SupplierService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/supplier')]
class SupplierController extends AbstractController
{
    public function __construct(
        private SupplierRepository $supplierRepository,
        private SupplierService $supplierService,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private Security $security
    ) {}

    #[Route('/', name: 'app_supplier_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get current tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get view filter parameter
        $view = $request->query->get('view', 'inherited'); // Default: inherited

        // Get suppliers based on view filter
        if ($tenant) {
            // Determine which suppliers to load based on view parameter
            switch ($view) {
                case 'own':
                    // Only own suppliers
                    $suppliers = $this->supplierRepository->findByTenant($tenant);
                    break;
                case 'subsidiaries':
                    // Own + from all subsidiaries (for parent companies)
                    $suppliers = $this->supplierRepository->findByTenantIncludingSubsidiaries($tenant);
                    break;
                case 'inherited':
                default:
                    // Own + inherited from parents (default behavior)
                    $suppliers = $this->supplierService->getSuppliersForTenant($tenant);
                    break;
            }

            $statistics = $this->supplierRepository->getStatisticsByTenant($tenant);
            $criticalSuppliers = $this->supplierRepository->findCriticalSuppliersByTenant($tenant);
            $overdueAssessments = $this->supplierRepository->findOverdueAssessmentsByTenant($tenant);
            $nonCompliant = $this->supplierRepository->findNonCompliantByTenant($tenant);
            $inheritanceInfo = $this->supplierService->getSupplierInheritanceInfo($tenant);
            $inheritanceInfo['hasSubsidiaries'] = $tenant->getSubsidiaries()->count() > 0;
            $inheritanceInfo['currentView'] = $view;
        } else {
            $suppliers = $this->supplierRepository->findAll();
            $statistics = [];
            $criticalSuppliers = [];
            $overdueAssessments = [];
            $nonCompliant = [];
            $inheritanceInfo = [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($suppliers, $tenant);
        } else {
            $detailedStats = ['own' => count($suppliers), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($suppliers)];
        }

        return $this->render('supplier/index.html.twig', [
            'suppliers' => $suppliers,
            'statistics' => $statistics,
            'criticalSuppliers' => $criticalSuppliers,
            'overdueAssessments' => $overdueAssessments,
            'nonCompliant' => $nonCompliant,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }

    #[Route('/new', name: 'app_supplier_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $supplier = new Supplier();
        $form = $this->createForm(SupplierType::class, $supplier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($supplier);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('supplier.success.created'));
            return $this->redirectToRoute('app_supplier_show', ['id' => $supplier->getId()]);
        }

        return $this->render('supplier/new.html.twig', [
            'supplier' => $supplier,
            'form' => $form,
        ]);
    }

    #[Route('/bulk-delete', name: 'app_supplier_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $supplier = $this->supplierRepository->find($id);

                if (!$supplier) {
                    $errors[] = "Supplier ID $id not found";
                    continue;
                }

                // Security check: only allow deletion of own tenant's suppliers
                if ($tenant && $supplier->getTenant() !== $tenant) {
                    $errors[] = "Supplier ID $id does not belong to your organization";
                    continue;
                }

                $this->entityManager->remove($supplier);
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = "Error deleting supplier ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        if (!empty($errors)) {
            return $this->json([
                'success' => $deleted > 0,
                'deleted' => $deleted,
                'errors' => $errors
            ], $deleted > 0 ? 200 : 400);
        }

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => "$deleted suppliers deleted successfully"
        ]);
    }


    #[Route('/{id}', name: 'app_supplier_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Supplier $supplier): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if supplier is inherited and can be edited (only if user has tenant)
        if ($tenant) {
            $isInherited = $this->supplierService->isInheritedSupplier($supplier, $tenant);
            $canEdit = $this->supplierService->canEditSupplier($supplier, $tenant);
        } else {
            $isInherited = false;
            $canEdit = true;
        }

        return $this->render('supplier/show.html.twig', [
            'supplier' => $supplier,
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_supplier_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Supplier $supplier): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if supplier can be edited (not inherited) - only if user has tenant
        if ($tenant && !$this->supplierService->canEditSupplier($supplier, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_edit_inherited'));
            return $this->redirectToRoute('app_supplier_show', ['id' => $supplier->getId()]);
        }

        $form = $this->createForm(SupplierType::class, $supplier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $supplier->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('supplier.success.updated'));
            return $this->redirectToRoute('app_supplier_show', ['id' => $supplier->getId()]);
        }

        return $this->render('supplier/edit.html.twig', [
            'supplier' => $supplier,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_supplier_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Supplier $supplier): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if supplier can be deleted (not inherited) - only if user has tenant
        if ($tenant && !$this->supplierService->canEditSupplier($supplier, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_delete_inherited'));
            return $this->redirectToRoute('app_supplier_index');
        }

        if ($this->isCsrfTokenValid('delete'.$supplier->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($supplier);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('supplier.success.deleted'));
        }

        return $this->redirectToRoute('app_supplier_index');
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
