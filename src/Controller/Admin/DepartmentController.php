<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Department;
use App\Form\Admin\DepartmentType;
use App\Repository\DepartmentRepository;
use App\Security\Voter\DepartmentVoter;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin CRUD for Department master data (S18 B3).
 *
 * Routes: NOT prefixed with `/{_locale<...>}` because the global routing config
 * already handles locale prefixing (see TagController pattern).
 */
// @em-write-allowed: simple Admin-CRUD on tenant-scoped Department entity; no service-layer business logic needed
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/departments', name: 'admin_department_')]
#[IsGranted('ROLE_ADMIN')]
final class DepartmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DepartmentRepository $departmentRepository,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $departments = $tenant !== null ? $this->departmentRepository->findByTenant($tenant) : [];

        return $this->render('admin/department/index.html.twig', [
            'departments' => $departments,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $department = new Department();
        $department->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(DepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($department);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('department.flash.created', [], 'department'));
            return $this->redirectToRoute('admin_department_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/department/new.html.twig', [
            'form' => $form->createView(),
        ], new Response(status: $status));
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Department $department, Request $request): Response
    {
        $this->denyAccessUnlessGranted(DepartmentVoter::EDIT, $department);

        $form = $this->createForm(DepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('department.flash.updated', [], 'department'));
            return $this->redirectToRoute('admin_department_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/department/edit.html.twig', [
            'form' => $form->createView(),
            'department' => $department,
        ], new Response(status: $status));
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Department $department, Request $request): Response
    {
        $this->denyAccessUnlessGranted(DepartmentVoter::DELETE, $department);

        if (!$this->isCsrfTokenValid('department_delete_' . $department->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('department.flash.csrf_invalid', [], 'department'));
            return $this->redirectToRoute('admin_department_index');
        }

        $this->entityManager->remove($department);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('department.flash.deleted', [], 'department'));
        return $this->redirectToRoute('admin_department_index');
    }
}
