<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Team;
use App\Form\TeamType;
use App\Repository\TeamRepository;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class TeamController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {}

    #[Route('/planning/teams', name: 'app_planning_team_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $tenant = $this->security->getUser()?->getTenant();
        $teams = $tenant ? $this->teamRepository->findByTenant($tenant) : [];

        return $this->render('planning/team/index.html.twig', [
            'teams' => $teams,
        ]);
    }

    #[Route('/planning/teams/new', name: 'app_planning_team_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $team = new Team();
        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant !== null) {
            $team->setTenant($tenant);
        }

        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($team);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('planning.success.created', [], 'planning'));
            return $this->redirectToRoute('app_planning_team_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/team/new.html.twig', [
            'team' => $team,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/planning/teams/{id}/edit', name: 'app_planning_team_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'team')]
    public function edit(Request $request, Team $team): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $form = $this->createForm(TeamType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $team->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('planning.success.updated', [], 'planning'));
            return $this->redirectToRoute('app_planning_team_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/team/edit.html.twig', [
            'team' => $team,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/planning/teams/{id}/delete', name: 'app_planning_team_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('delete', 'team')]
    public function delete(Request $request, Team $team): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        if ($this->isCsrfTokenValid('delete'.$team->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($team);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('planning.success.deleted', [], 'planning'));
        }

        return $this->redirectToRoute('app_planning_team_index');
    }
}
