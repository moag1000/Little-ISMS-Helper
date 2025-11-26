<?php

namespace App\Controller;

use App\Entity\BCExercise;
use App\Form\BCExerciseType;
use App\Repository\BCExerciseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/bc-exercise')]
class BCExerciseController extends AbstractController
{
    public function __construct(
        private BCExerciseRepository $bcExerciseRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_bc_exercise_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $bcExercises = $this->bcExerciseRepository->findAll();
        $statistics = $this->bcExerciseRepository->getStatistics();
        $upcoming = $this->bcExerciseRepository->findUpcoming();
        $incompleteReports = $this->bcExerciseRepository->findIncompleteReports();

        return $this->render('bc_exercise/index.html.twig', [
            'bc_exercises' => $bcExercises,
            'statistics' => $statistics,
            'upcoming' => $upcoming,
            'incomplete_reports' => $incompleteReports,
        ]);
    }

    #[Route('/new', name: 'app_bc_exercise_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $bcExercise = new BCExercise();
        $form = $this->createForm(BCExerciseType::class, $bcExercise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($bcExercise);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('bc_exercise.success.created'));
            return $this->redirectToRoute('app_bc_exercise_show', ['id' => $bcExercise->getId()]);
        }

        return $this->render('bc_exercise/new.html.twig', [
            'bc_exercise' => $bcExercise,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_bc_exercise_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(BCExercise $bcExercise): Response
    {
        return $this->render('bc_exercise/show.html.twig', [
            'bc_exercise' => $bcExercise,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_bc_exercise_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, BCExercise $bcExercise): Response
    {
        $form = $this->createForm(BCExerciseType::class, $bcExercise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $bcExercise->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('bc_exercise.success.updated'));
            return $this->redirectToRoute('app_bc_exercise_show', ['id' => $bcExercise->getId()]);
        }

        return $this->render('bc_exercise/edit.html.twig', [
            'bc_exercise' => $bcExercise,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_bc_exercise_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, BCExercise $bcExercise): Response
    {
        if ($this->isCsrfTokenValid('delete'.$bcExercise->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($bcExercise);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('bc_exercise.success.deleted'));
        }

        return $this->redirectToRoute('app_bc_exercise_index');
    }
}
