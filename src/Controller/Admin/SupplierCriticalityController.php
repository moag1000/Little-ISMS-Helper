<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SupplierCriticalityLevel;
use App\Entity\Tenant;
use App\Form\Admin\SupplierCriticalityLevelType;
use App\Repository\SupplierCriticalityLevelRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase 8QW-5 — Admin-UI für Supplier-Kritikalitätsstufen pro Tenant.
 */
#[Route('/admin/supplier-criticality')]
#[IsGranted('ROLE_ADMIN')]
class SupplierCriticalityController extends AbstractController
{
    public function __construct(
        private readonly SupplierCriticalityLevelRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'app_admin_supplier_criticality_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->requireTenant();
        $levels = $this->repository->findAllByTenant($tenant);

        return $this->render('admin/supplier_criticality/index.html.twig', [
            'levels' => $levels,
        ]);
    }

    #[Route('/new', name: 'app_admin_supplier_criticality_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tenant = $this->requireTenant();
        $level = new SupplierCriticalityLevel();
        $level->setTenant($tenant);

        $form = $this->createForm(SupplierCriticalityLevelType::class, $level, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($level);
            $this->entityManager->flush();

            $this->auditLogger->logCreate(
                'SupplierCriticalityLevel',
                $level->getId(),
                ['code' => $level->getCode(), 'label_de' => $level->getLabelDe()],
                'Neue Kritikalitätsstufe angelegt.'
            );

            $this->addFlash('success', 'supplier_criticality.flash.created');
            return $this->redirectToRoute('app_admin_supplier_criticality_index');
        }

        return $this->render('admin/supplier_criticality/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_supplier_criticality_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SupplierCriticalityLevel $level): Response
    {
        $this->requireTenantOwnership($level);

        $oldValues = [
            'label_de' => $level->getLabelDe(),
            'label_en' => $level->getLabelEn(),
            'sort_order' => $level->getSortOrder(),
            'color' => $level->getColor(),
            'is_default' => $level->isDefault(),
            'is_active' => $level->isActive(),
        ];

        $form = $this->createForm(SupplierCriticalityLevelType::class, $level, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $newValues = [
                'label_de' => $level->getLabelDe(),
                'label_en' => $level->getLabelEn(),
                'sort_order' => $level->getSortOrder(),
                'color' => $level->getColor(),
                'is_default' => $level->isDefault(),
                'is_active' => $level->isActive(),
            ];

            $this->auditLogger->logUpdate(
                'SupplierCriticalityLevel',
                $level->getId(),
                $oldValues,
                $newValues,
                'Kritikalitätsstufe aktualisiert.'
            );

            $this->addFlash('success', 'supplier_criticality.flash.updated');
            return $this->redirectToRoute('app_admin_supplier_criticality_index');
        }

        return $this->render('admin/supplier_criticality/edit.html.twig', [
            'form' => $form,
            'level' => $level,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_supplier_criticality_delete', methods: ['POST'])]
    public function delete(Request $request, SupplierCriticalityLevel $level): Response
    {
        $this->requireTenantOwnership($level);

        if (!$this->isCsrfTokenValid('delete_supplier_criticality_' . $level->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'common.invalid_csrf');
            return $this->redirectToRoute('app_admin_supplier_criticality_index');
        }

        $this->auditLogger->logDelete(
            'SupplierCriticalityLevel',
            $level->getId(),
            ['code' => $level->getCode()],
            'Kritikalitätsstufe gelöscht.'
        );

        $this->entityManager->remove($level);
        $this->entityManager->flush();

        $this->addFlash('success', 'supplier_criticality.flash.deleted');
        return $this->redirectToRoute('app_admin_supplier_criticality_index');
    }

    private function requireTenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }
        return $tenant;
    }

    private function requireTenantOwnership(SupplierCriticalityLevel $level): void
    {
        $tenant = $this->requireTenant();
        if ($level->getTenant() !== $tenant) {
            throw $this->createAccessDeniedException('Level does not belong to current tenant.');
        }
    }
}
