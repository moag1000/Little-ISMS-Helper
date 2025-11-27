<?php

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use DateTimeImmutable;
use App\Entity\CrisisTeam;
use App\Form\CrisisTeamType;
use App\Repository\CrisisTeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class CrisisTeamController extends AbstractController
{
    public function __construct(
        private readonly CrisisTeamRepository $crisisTeamRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security
    ) {}

    #[Route('/crisis-team/', name: 'app_crisis_team_index')]
    public function index(): Response
    {
        $crisisTeams = $this->crisisTeamRepository->findAll();

        // Statistics
        $activeTeams = array_filter($crisisTeams, fn(CrisisTeam $crisisTeam): bool => $crisisTeam->isActive());
        $operationalTeams = array_filter($crisisTeams, fn(CrisisTeam $crisisTeam): bool => $crisisTeam->getTeamType() === 'operational');
        $strategicTeams = array_filter($crisisTeams, fn(CrisisTeam $crisisTeam): bool => $crisisTeam->getTeamType() === 'strategic');

        return $this->render('crisis_team/index.html.twig', [
            'crisis_teams' => $crisisTeams,
            'active_count' => count($activeTeams),
            'operational_count' => count($operationalTeams),
            'strategic_count' => count($strategicTeams),
        ]);
    }

    #[Route('/crisis-team/new', name: 'app_crisis_team_new')]
    public function new(Request $request): Response
    {
        $crisisTeam = new CrisisTeam();

        // Set tenant from current user
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant()) {
            $crisisTeam->setTenant($user->getTenant());
        }

        $form = $this->createForm(CrisisTeamType::class, $crisisTeam);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($crisisTeam);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('crisis_team.success.created'));
            return $this->redirectToRoute('app_crisis_team_show', ['id' => $crisisTeam->getId()]);
        }

        return $this->render('crisis_team/new.html.twig', [
            'crisis_team' => $crisisTeam,
            'form' => $form,
        ]);
    }

    #[Route('/crisis-team/{id}', name: 'app_crisis_team_show', requirements: ['id' => '\d+'])]
    public function show(CrisisTeam $crisisTeam): Response
    {
        return $this->render('crisis_team/show.html.twig', [
            'crisis_team' => $crisisTeam,
        ]);
    }

    #[Route('/crisis-team/{id}/edit', name: 'app_crisis_team_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, CrisisTeam $crisisTeam): Response
    {
        $form = $this->createForm(CrisisTeamType::class, $crisisTeam);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('crisis_team.success.updated'));
            return $this->redirectToRoute('app_crisis_team_show', ['id' => $crisisTeam->getId()]);
        }

        return $this->render('crisis_team/edit.html.twig', [
            'crisis_team' => $crisisTeam,
            'form' => $form,
        ]);
    }

    #[Route('/crisis-team/{id}/delete', name: 'app_crisis_team_delete', methods: ['POST'])]
    public function delete(Request $request, CrisisTeam $crisisTeam): Response
    {
        if ($this->isCsrfTokenValid('delete'.$crisisTeam->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($crisisTeam);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('crisis_team.success.deleted'));
        }

        return $this->redirectToRoute('app_crisis_team_index');
    }

    #[Route('/crisis-team/{id}/activate', name: 'app_crisis_team_activate', methods: ['POST'])]
    public function activate(Request $request, CrisisTeam $crisisTeam): Response
    {
        if ($this->isCsrfTokenValid('activate'.$crisisTeam->getId(), $request->request->get('_token'))) {
            $crisisTeam->setLastActivatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('crisis_team.success.activated'));
        }

        return $this->redirectToRoute('app_crisis_team_show', ['id' => $crisisTeam->getId()]);
    }
}
