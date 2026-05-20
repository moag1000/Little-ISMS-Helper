<?php

declare(strict_types=1);

namespace App\Controller;

use Exception;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Enum\TrainingStatus;
use App\Entity\User;
use App\Form\TrainingType;
use App\Repository\TrainingParticipationRepository;
use App\Repository\TrainingRepository;
use App\Repository\UserRepository;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class TrainingController extends AbstractController
{
    public function __construct(
        private readonly TrainingRepository $trainingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly ?TrainingParticipationRepository $participationRepository = null,
        private readonly ?UserRepository $userRepository = null,
    ) {}
    #[Route('/training', name: 'app_training_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();
        $view = $request->query->get('view', 'inherited');

        if ($tenant) {
            $trainings = match ($view) {
                'own' => $this->trainingRepository->findByTenant($tenant),
                'subsidiaries' => $this->trainingRepository->findByTenantIncludingSubsidiaries($tenant),
                default => $this->trainingRepository->findByTenantIncludingParent($tenant),
            };
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
            'completed' => count(array_filter($trainings, fn(Training $training): bool => $training->getStatus() === TrainingStatus::Completed->value)),
            'mandatory' => count(array_filter($trainings, fn(Training $training): bool => $training->isMandatory())),
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
    #[Route('/training/new', name: 'app_training_new', methods: ['GET', 'POST'])]
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

            // P-15 DataReuse: sync participantUsers → TrainingParticipation rows.
            $this->syncParticipantUsersToParticipationRows($training);

            $this->addFlash('success', $this->translator->trans('training.success.created')); // @todo H-06 flash-domain
            return $this->redirectToRoute('app_training_show', ['id' => $training->getId()]);
        }

        return $this->render('training/new.html.twig', [
            'training' => $training,
            'form' => $form,
        ]);
    }
    #[Route('/training/bulk-delete', name: 'app_training_bulk_delete', methods: ['POST'])]
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
            } catch (Exception $e) {
                $errors[] = "Error deleting training ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        if ($errors !== []) {
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
    #[Route('/training/{id}', name: 'app_training_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Training $training): Response
    {
        return $this->render('training/show.html.twig', [
            'training' => $training,
        ]);
    }
    #[Route('/training/{id}/edit', name: 'app_training_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Training $training): Response
    {
        $form = $this->createForm(TrainingType::class, $training);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            // P-15 DataReuse: sync participantUsers → TrainingParticipation rows.
            $this->syncParticipantUsersToParticipationRows($training);

            $this->addFlash('success', $this->translator->trans('training.success.updated')); // @todo H-06 flash-domain
            return $this->redirectToRoute('app_training_show', ['id' => $training->getId()]);
        }

        return $this->render('training/edit.html.twig', [
            'training' => $training,
            'form' => $form,
        ]);
    }

    /**
     * P-15 DataReuse — Form-Submit-Sync for Training.participantUsers.
     *
     * For every User selected in the typed multi-select, create a
     * TrainingParticipation row (idempotent on (training_id, user_id)). Cross-
     * tenant users are skipped silently. No row is removed when the user is
     * deselected — TrainingParticipation has audit-trail value (Art. 33 +
     * ISO 27001 §7.3) and must be soft-cancelled via status=waived elsewhere.
     */
    private function syncParticipantUsersToParticipationRows(Training $training): void
    {
        if ($this->participationRepository === null) {
            return;
        }
        $tenant = $training->getTenant();
        if ($tenant === null) {
            return;
        }
        $selected = $training->getParticipantUsers();
        if ($selected->isEmpty()) {
            return;
        }
        $created = 0;
        foreach ($selected as $user) {
            if (!$user instanceof User) {
                continue;
            }
            if ($user->getTenant() !== $tenant) {
                continue; // tenant isolation
            }
            $existing = $this->participationRepository->findOneBy([
                'training' => $training,
                'user' => $user,
            ]);
            if ($existing instanceof TrainingParticipation) {
                continue;
            }
            $row = new TrainingParticipation();
            $row->setTenant($tenant);
            $row->setTraining($training);
            $row->setUser($user);
            $row->setStatus(TrainingParticipation::STATUS_PENDING);
            $row->setAssignmentSource('manual:edit_form');
            $this->entityManager->persist($row);
            $created++;
        }
        if ($created > 0) {
            $this->entityManager->flush();
        }
    }
    #[Route('/training/{id}/delete', name: 'app_training_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Training $training): Response
    {
        if ($this->isCsrfTokenValid('delete'.$training->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($training);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('training.success.deleted')); // @todo H-06 flash-domain
        }

        return $this->redirectToRoute('app_training_index');
    }
    /**
     * Sprint-2 P-7 Wave-2 Trigger-3: Mandatory-Training audience picker.
     *
     * GET shows a User-multi-select pre-filled with the current tenant's
     * active users (optionally filtered by department / role hint); POST
     * persists TrainingParticipation rows (status=pending) for each
     * selected user (ISO 27001 A.6.3 Awareness).
     * Tenant-isolated; only Users in the same tenant as the Training are
     * offered.
     */
    #[Route('/training/{id}/audience-picker', name: 'app_training_audience_picker', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function audiencePicker(Request $request, Training $training): Response
    {
        $tenant = $training->getTenant();
        $currentTenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $tenant !== $currentTenant) {
            throw $this->createNotFoundException();
        }
        if (!$training->isMandatory()) {
            $this->addFlash('warning', $this->translator->trans('training.mandatory_audience.flash_not_mandatory', [], 'alva'));
            return $this->redirectToRoute('app_training_show', ['id' => $training->getId()]);
        }

        if ($request->isMethod('POST') && $this->participationRepository !== null && $this->userRepository !== null) {
            if (!$this->isCsrfTokenValid('audience_picker_' . $training->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->translator->trans('common.csrf_invalid', [], 'messages'));
                return $this->redirectToRoute('app_training_audience_picker', ['id' => $training->getId()]);
            }

            $userIds = array_filter(
                (array) $request->request->all('user_ids'),
                static fn($id): bool => is_string($id) && ctype_digit($id),
            );

            $created = 0;
            foreach ($userIds as $id) {
                $u = $this->userRepository->find((int) $id);
                if (!$u instanceof User || $u->getTenant() !== $tenant) {
                    continue;
                }
                // Idempotent: skip if a participation row already exists.
                $existing = $this->participationRepository->findOneBy([
                    'training' => $training,
                    'user' => $u,
                ]);
                if ($existing instanceof TrainingParticipation) {
                    continue;
                }
                $row = new TrainingParticipation();
                $row->setTenant($tenant);
                $row->setTraining($training);
                $row->setUser($u);
                $row->setStatus(TrainingParticipation::STATUS_PENDING);
                $row->setAssignmentSource('manual:audience_picker');
                $this->entityManager->persist($row);
                $created++;
            }
            if ($created > 0) {
                $this->entityManager->flush();
            }

            $this->addFlash('success', $this->translator->trans(
                'training.mandatory_audience.flash_saved',
                ['%count%' => $created],
                'alva',
            ));

            return $this->redirectToRoute('app_training_show', ['id' => $training->getId()]);
        }

        // Tenant-scoped active users
        $candidates = $this->userRepository !== null
            ? $this->userRepository->findBy(['tenant' => $tenant, 'isActive' => true], ['email' => 'ASC'])
            : [];
        $alreadyAssignedIds = [];
        if ($this->participationRepository !== null) {
            foreach ($this->participationRepository->findBy(['training' => $training]) as $p) {
                $u = $p->getUser();
                if ($u !== null && $u->getId() !== null) {
                    $alreadyAssignedIds[] = $u->getId();
                }
            }
        }

        return $this->render('training/audience_picker.html.twig', [
            'training' => $training,
            'candidates' => $candidates,
            'already_assigned_ids' => $alreadyAssignedIds,
        ]);
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
