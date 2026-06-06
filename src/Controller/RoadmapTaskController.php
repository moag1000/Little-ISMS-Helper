<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\RoadmapTask;
use App\Form\RoadmapTaskType;
use App\Repository\RoadmapTaskRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class RoadmapTaskController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly RoadmapTaskRepository $roadmapTaskRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {}

    #[Route('/planning/tasks', name: 'app_planning_task_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $tenant = $this->security->getUser()?->getTenant();
        $tasks = $tenant ? $this->roadmapTaskRepository->findActiveByTenant($tenant) : [];

        return $this->render('planning/task/index.html.twig', [
            'tasks' => $tasks,
        ]);
    }

    #[Route('/planning/tasks/new', name: 'app_planning_task_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $task = new RoadmapTask();
        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant !== null) {
            $task->setTenant($tenant);
        }

        $form = $this->createForm(RoadmapTaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($task);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('planning.success.created', [], 'planning'));
            return $this->redirectToRoute('app_planning_task_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/task/new.html.twig', [
            'task' => $task,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/planning/tasks/{id}/edit', name: 'app_planning_task_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'roadmapTask')]
    public function edit(Request $request, RoadmapTask $roadmapTask): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $form = $this->createForm(RoadmapTaskType::class, $roadmapTask);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('planning.success.updated', [], 'planning'));
            return $this->redirectToRoute('app_planning_task_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/task/edit.html.twig', [
            'task' => $roadmapTask,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/planning/tasks/{id}/delete', name: 'app_planning_task_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('delete', 'roadmapTask')]
    public function delete(Request $request, RoadmapTask $roadmapTask): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        if ($this->isCsrfTokenValid('delete'.$roadmapTask->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($roadmapTask);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('planning.success.deleted', [], 'planning'));
        }

        return $this->redirectToRoute('app_planning_task_index');
    }
}
