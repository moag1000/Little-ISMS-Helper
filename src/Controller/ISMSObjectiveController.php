<?php

namespace App\Controller;

use App\Entity\ISMSObjective;
use App\Form\ISMSObjectiveType;
use App\Repository\ISMSObjectiveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/objective')]
class ISMSObjectiveController extends AbstractController
{
    public function __construct(
        private ISMSObjectiveRepository $objectiveRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_objective_index')]
    public function index(): Response
    {
        $objectives = $this->objectiveRepository->findAll();
        $active = $this->objectiveRepository->findActive();

        $statistics = [
            'total' => count($objectives),
            'active' => count($active),
            'achieved' => count($this->objectiveRepository->findBy(['status' => 'achieved'])),
            'delayed' => count(array_filter($objectives, function($obj) {
                return $obj->getStatus() === 'in_progress' &&
                       $obj->getTargetDate() < new \DateTime() &&
                       !$obj->getAchievedDate();
            })),
        ];

        return $this->render('objective/index.html.twig', [
            'objectives' => $objectives,
            'statistics' => $statistics,
        ]);
    }

    #[Route('/new', name: 'app_objective_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $objective = new ISMSObjective();
        $form = $this->createForm(ISMSObjectiveType::class, $objective);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $objective->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($objective);
            $this->entityManager->flush();

            $this->addFlash('success', 'ISMS-Ziel erfolgreich erstellt.');
            return $this->redirectToRoute('app_objective_show', ['id' => $objective->getId()]);
        }

        return $this->render('objective/new.html.twig', [
            'objective' => $objective,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_objective_show', requirements: ['id' => '\d+'])]
    public function show(ISMSObjective $objective): Response
    {
        return $this->render('objective/show.html.twig', [
            'objective' => $objective,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_objective_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, ISMSObjective $objective): Response
    {
        $form = $this->createForm(ISMSObjectiveType::class, $objective);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $objective->setUpdatedAt(new \DateTime());

            // Automatically set achieved date when status changes to achieved
            if ($objective->getStatus() === 'achieved' && !$objective->getAchievedDate()) {
                $objective->setAchievedDate(new \DateTime());
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'ISMS-Ziel erfolgreich aktualisiert.');
            return $this->redirectToRoute('app_objective_show', ['id' => $objective->getId()]);
        }

        return $this->render('objective/edit.html.twig', [
            'objective' => $objective,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_objective_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ISMSObjective $objective): Response
    {
        if ($this->isCsrfTokenValid('delete'.$objective->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($objective);
            $this->entityManager->flush();

            $this->addFlash('success', 'ISMS Objective deleted successfully.');
        }

        return $this->redirectToRoute('app_objective_index');
    }
}
