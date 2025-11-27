<?php

namespace App\Controller;

use App\Entity\Training;
use App\Form\TrainingType;
use App\Repository\TrainingRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/training')]
class TrainingController extends AbstractController
{
    public function __construct(
        private TrainingRepository $trainingRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private Security $security,
        private TenantContext $tenantContext
    ) {}

    #[Route('/', name: 'app_training_index')]
    public function index(Request $request): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();
        $view = $request->query->get('view', 'inherited');

        if ($tenant) {
            switch ($view) {
                case 'own':
                    $trainings = $this->trainingRepository->findByTenant($tenant);
                    break;
                case 'subsidiaries':
                    $trainings = $this->trainingRepository->findByTenantIncludingSubsidiaries($tenant);
                    break;
                case 'inherited':
                default:
                    $trainings = $this->trainingRepository->findByTenantIncludingParent($tenant);
                    break;
            }
            $inheritanceInfo = [
                'hasParent' => $tenant->getParent() !== null,
                'hasSubsidiaries' => $tenant->getSubsidiaries()->count() > 0,
                'currentView' => $view
            ];
        } else {
            $trainings = $this->trainingRepository->findAll();
            $inheritanceInfo = ['hasParent' => false, 'hasSubsidiaries' => false, 'currentView' => 'own'];
        }

        $upcoming = $this->trainingRepository->findUpcoming();
        $statistics = [
            'total' => count($trainings),
            'upcoming' => count($upcoming),
            'completed' => count(array_filter($trainings, fn($t) => $t->getStatus() === 'completed')),
            'mandatory' => count(array_filter($trainings, fn($t) => $t->isMandatory())),
        ];

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($trainings, $tenant);
        } else {
            $detailedStats = ['own' => count($trainings), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($trainings)];
        }

        return $this->render('training/index.html.twig', [
            'trainings' => $trainings,
            'upcoming' => $upcoming,
            'statistics' => $statistics,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }

    #[Route('/new', name: 'app_training_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $training = new Training();
        $training->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(TrainingType::class, $training);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($training);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('training.success.created'));
            return $this->redirectToRoute('app_training_show', ['id' => $training->getId()]);
        }

        return $this->render('training/new.html.twig', [
            'training' => $training,
            'form' => $form,
        ]);
    }

    #[Route('/bulk-delete', name: 'app_training_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $training = $this->trainingRepository->find($id);

                if (!$training) {
                    $errors[] = "Training ID $id not found";
                    continue;
                }

                // Security check: only allow deletion of own tenant's trainings
                if ($tenant && $training->getTenant() !== $tenant) {
                    $errors[] = "Training ID $id does not belong to your organization";
                    continue;
                }

                $this->entityManager->remove($training);
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = "Error deleting training ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        if (!empty($errors)) {
            return $this->json([
                'success' => $deleted > 0,
                'deleted' => $deleted,
                'errors' => $errors
            ], $deleted > 0 ? 200 : 400);
        }

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => "$deleted trainings deleted successfully"
        ]);
    }


    #[Route('/{id}', name: 'app_training_show', requirements: ['id' => '\d+'])]
    public function show(Training $training): Response
    {
        return $this->render('training/show.html.twig', [
            'training' => $training,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_training_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Training $training): Response
    {
        $form = $this->createForm(TrainingType::class, $training);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('training.success.updated'));
            return $this->redirectToRoute('app_training_show', ['id' => $training->getId()]);
        }

        return $this->render('training/edit.html.twig', [
            'training' => $training,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_training_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Training $training): Response
    {
        if ($this->isCsrfTokenValid('delete'.$training->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($training);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('training.success.deleted'));
        }

        return $this->redirectToRoute('app_training_index');
    }

    /**
     * Calculate detailed statistics showing breakdown by origin
     */
    private function calculateDetailedStats(array $items, $currentTenant): array
    {
        $ownCount = 0;
        $inheritedCount = 0;
        $subsidiariesCount = 0;

        // Get ancestors and subsidiaries for comparison
        $ancestors = $currentTenant->getAllAncestors();
        $ancestorIds = array_map(fn($t) => $t->getId(), $ancestors);

        $subsidiaries = $currentTenant->getAllSubsidiaries();
        $subsidiaryIds = array_map(fn($t) => $t->getId(), $subsidiaries);

        foreach ($items as $item) {
            $itemTenant = $item->getTenant();
            if (!$itemTenant) {
                continue;
            }

            $itemTenantId = $itemTenant->getId();
            $currentTenantId = $currentTenant->getId();

            if ($itemTenantId === $currentTenantId) {
                $ownCount++;
            } elseif (in_array($itemTenantId, $ancestorIds)) {
                $inheritedCount++;
            } elseif (in_array($itemTenantId, $subsidiaryIds)) {
                $subsidiariesCount++;
            }
        }

        return [
            'own' => $ownCount,
            'inherited' => $inheritedCount,
            'subsidiaries' => $subsidiariesCount,
            'total' => $ownCount + $inheritedCount + $subsidiariesCount
        ];
    }
}
