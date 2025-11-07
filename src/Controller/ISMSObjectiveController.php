<?php

namespace App\Controller;

use App\Entity\ISMSObjective;
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

    #[Route('/new', name: 'app_objective_new')]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $objective = new ISMSObjective();

        if ($request->isMethod('POST')) {
            $objective->setTitle($request->request->get('title'));
            $objective->setDescription($request->request->get('description'));
            $objective->setCategory($request->request->get('category'));
            $objective->setMeasurableIndicators($request->request->get('measurable_indicators'));
            $objective->setTargetValue($request->request->get('target_value'));
            $objective->setCurrentValue($request->request->get('current_value'));
            $objective->setUnit($request->request->get('unit'));
            $objective->setResponsiblePerson($request->request->get('responsible_person'));
            $objective->setTargetDate(new \DateTime($request->request->get('target_date')));
            $objective->setStatus($request->request->get('status') ?? 'in_progress');
            $objective->setProgressNotes($request->request->get('progress_notes'));
            $objective->setUpdatedAt(new \DateTime());

            $this->entityManager->persist($objective);
            $this->entityManager->flush();

            $this->addFlash('success', 'ISMS Objective created successfully.');
            return $this->redirectToRoute('app_objective_show', ['id' => $objective->getId()]);
        }

        return $this->render('objective/new.html.twig', [
            'objective' => $objective,
        ]);
    }

    #[Route('/{id}', name: 'app_objective_show', requirements: ['id' => '\d+'])]
    public function show(ISMSObjective $objective): Response
    {
        return $this->render('objective/show.html.twig', [
            'objective' => $objective,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_objective_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, ISMSObjective $objective): Response
    {
        if ($request->isMethod('POST')) {
            $objective->setTitle($request->request->get('title'));
            $objective->setDescription($request->request->get('description'));
            $objective->setCategory($request->request->get('category'));
            $objective->setMeasurableIndicators($request->request->get('measurable_indicators'));
            $objective->setTargetValue($request->request->get('target_value'));
            $objective->setCurrentValue($request->request->get('current_value'));
            $objective->setUnit($request->request->get('unit'));
            $objective->setResponsiblePerson($request->request->get('responsible_person'));
            $objective->setTargetDate(new \DateTime($request->request->get('target_date')));
            $objective->setStatus($request->request->get('status'));
            $objective->setProgressNotes($request->request->get('progress_notes'));
            $objective->setUpdatedAt(new \DateTime());

            if ($request->request->get('status') === 'achieved' && !$objective->getAchievedDate()) {
                $objective->setAchievedDate(new \DateTime());
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'ISMS Objective updated successfully.');
            return $this->redirectToRoute('app_objective_show', ['id' => $objective->getId()]);
        }

        return $this->render('objective/edit.html.twig', [
            'objective' => $objective,
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
