<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\RoadmapGroup;
use App\Form\RoadmapGroupType;
use App\Repository\RoadmapGroupRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class RoadmapGroupController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly RoadmapGroupRepository $roadmapGroupRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {}

    #[Route('/planning/groups', name: 'app_planning_group_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $tenant = $this->security->getUser()?->getTenant();
        $groups = $tenant ? $this->roadmapGroupRepository->findActiveByTenant($tenant) : [];

        return $this->render('planning/group/index.html.twig', [
            'groups' => $groups,
        ]);
    }

    #[Route('/planning/groups/new', name: 'app_planning_group_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $group = new RoadmapGroup();
        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant !== null) {
            $group->setTenant($tenant);
        }

        $form = $this->createForm(RoadmapGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($group);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('planning.success.created', [], 'planning'));
            return $this->redirectToRoute('app_planning_group_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/group/new.html.twig', [
            'group' => $group,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/planning/groups/{id}/edit', name: 'app_planning_group_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, RoadmapGroup $roadmapGroup): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $form = $this->createForm(RoadmapGroupType::class, $roadmapGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('planning.success.updated', [], 'planning'));
            return $this->redirectToRoute('app_planning_group_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/group/edit.html.twig', [
            'group' => $roadmapGroup,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/planning/groups/{id}/delete', name: 'app_planning_group_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, RoadmapGroup $roadmapGroup): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        if ($this->isCsrfTokenValid('delete'.$roadmapGroup->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($roadmapGroup);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('planning.success.deleted', [], 'planning'));
        }

        return $this->redirectToRoute('app_planning_group_index');
    }
}
