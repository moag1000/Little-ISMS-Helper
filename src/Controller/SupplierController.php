<?php

namespace App\Controller;

use App\Entity\Supplier;
use App\Form\SupplierType;
use App\Repository\SupplierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/supplier')]
class SupplierController extends AbstractController
{
    public function __construct(
        private SupplierRepository $supplierRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_supplier_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $suppliers = $this->supplierRepository->findAll();
        $statistics = $this->supplierRepository->getStatistics();
        $overdueAssessments = $this->supplierRepository->findOverdueAssessments();
        $criticalSuppliers = $this->supplierRepository->findCriticalSuppliers();
        $nonCompliant = $this->supplierRepository->findNonCompliant();

        return $this->render('supplier/index.html.twig', [
            'suppliers' => $suppliers,
            'statistics' => $statistics,
            'overdueAssessments' => $overdueAssessments,
            'criticalSuppliers' => $criticalSuppliers,
            'nonCompliant' => $nonCompliant,
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

            $this->addFlash('success', 'Supplier created successfully.');
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
        return $this->render('supplier/show.html.twig', [
            'supplier' => $supplier,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_supplier_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Supplier $supplier): Response
    {
        $form = $this->createForm(SupplierType::class, $supplier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $supplier->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();

            $this->addFlash('success', 'Supplier updated successfully.');
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
        if ($this->isCsrfTokenValid('delete'.$supplier->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($supplier);
            $this->entityManager->flush();

            $this->addFlash('success', 'Supplier deleted successfully.');
        }

        return $this->redirectToRoute('app_supplier_index');
    }
}
