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
    public function index(): Response
    {
        // Get current tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get suppliers: tenant-filtered if user has tenant, all if not
        if ($tenant) {
            $suppliers = $this->supplierService->getSuppliersForTenant($tenant);
            $statistics = $this->supplierRepository->getStatisticsByTenant($tenant);
            $criticalSuppliers = $this->supplierRepository->findCriticalSuppliersByTenant($tenant);
            $overdueAssessments = $this->supplierRepository->findOverdueAssessmentsByTenant($tenant);
            $nonCompliant = $this->supplierRepository->findNonCompliantByTenant($tenant);
            $inheritanceInfo = $this->supplierService->getSupplierInheritanceInfo($tenant);
        } else {
            $suppliers = $this->supplierRepository->findAll();
            $statistics = [];
            $criticalSuppliers = [];
            $overdueAssessments = [];
            $nonCompliant = [];
            $inheritanceInfo = ['hasParent' => false, 'canInherit' => false, 'governanceModel' => null];
        }

        return $this->render('supplier/index.html.twig', [
            'suppliers' => $suppliers,
            'statistics' => $statistics,
            'criticalSuppliers' => $criticalSuppliers,
            'overdueAssessments' => $overdueAssessments,
            'nonCompliant' => $nonCompliant,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
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
}
