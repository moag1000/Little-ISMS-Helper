<?php

declare(strict_types=1);

namespace App\Controller;

use DateTimeImmutable;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\BCExercise;
use App\Form\BCExerciseType;
use App\Repository\BCExerciseRepository;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class BCExerciseController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly BCExerciseRepository $bcExerciseRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly Security $security,
    ) {}
    #[Route('/bc-exercise/', name: 'app_bc_exercise_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        $currentTenant = $this->tenantContext->getCurrentTenant();
        $bcExercises = $currentTenant !== null
            ? $this->bcExerciseRepository->findBy(['tenant' => $currentTenant])
            : $this->bcExerciseRepository->findAll();
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
    #[Route('/bc-exercise/new', name: 'app_bc_exercise_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        $bcExercise = new BCExercise();
        $bcExercise->setTenant($this->tenantContext->getCurrentTenant());

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
    #[Route('/bc-exercise/{id}', name: 'app_bc_exercise_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(BCExercise $bcExercise): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        return $this->render('bc_exercise/show.html.twig', [
            'bc_exercise' => $bcExercise,
        ]);
    }
    #[Route('/bc-exercise/{id}/edit', name: 'app_bc_exercise_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, BCExercise $bcExercise): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        $form = $this->createForm(BCExerciseType::class, $bcExercise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $bcExercise->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('bc_exercise.success.updated'));
            return $this->redirectToRoute('app_bc_exercise_show', ['id' => $bcExercise->getId()]);
        }

        return $this->render('bc_exercise/edit.html.twig', [
            'bc_exercise' => $bcExercise,
            'form' => $form,
        ]);
    }
    #[Route('/bc-exercise/{id}/delete', name: 'app_bc_exercise_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, BCExercise $bcExercise): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        if ($this->isCsrfTokenValid('delete'.$bcExercise->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($bcExercise);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('bc_exercise.success.deleted'));
        }

        return $this->redirectToRoute('app_bc_exercise_index');
    }

    #[Route('/bc-exercise/bulk-delete', name: 'app_bc_exercise_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDelete(Request $request): JsonResponse
    {
        if ($this->checkModuleActive('bcm') instanceof Response) {
            return $this->json(['error' => 'BCM module not active'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->security->getUser()?->getTenant();
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $exercise = $this->bcExerciseRepository->find($id);
                if (!$exercise) {
                    $errors[] = "BCExercise ID $id not found";
                    continue;
                }
                if ($tenant && $exercise->getTenant() !== $tenant) {
                    $errors[] = "BCExercise ID $id does not belong to your organization";
                    continue;
                }
                $this->entityManager->remove($exercise);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting BCExercise ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => "$deleted BC exercises deleted successfully",
        ]);
    }
}
