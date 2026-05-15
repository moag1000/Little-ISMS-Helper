<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\BCExercise;
use App\Entity\Bsi2004ExerciseLog;
use App\Form\Bsi2004ExerciseLogType;
use App\Repository\BCExerciseRepository;
use App\Repository\Bsi2004ExerciseLogRepository;
use App\Security\Voter\Bsi2004ExerciseLogVoter;
use App\Service\AuditLogger;
use App\Service\Bsi2004ExerciseLogService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/bcm/exercise-log', name: 'bcm_exercise_log_')]
class Bsi2004ExerciseLogController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly Bsi2004ExerciseLogRepository $logRepository,
        private readonly BCExerciseRepository $exerciseRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Bsi2004ExerciseLogService $logService,
        private readonly AuditLogger $auditLogger,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly ModuleConfigurationService $moduleService,
    ) {
    }

    // -------------------------------------------------------------------------
    // Index: list all logs for tenant
    // -------------------------------------------------------------------------

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $logs = $tenant !== null
            ? $this->logRepository->findByTenant($tenant)
            : [];

        $overdueCount = $tenant !== null
            ? count($this->logRepository->findImprovementActionsOverdue($tenant))
            : 0;

        return $this->render('bcm/exercise_log/index.html.twig', [
            'logs'         => $logs,
            'overdueCount' => $overdueCount,
        ]);
    }

    // -------------------------------------------------------------------------
    // New: create log for a given BCExercise
    // -------------------------------------------------------------------------

    #[Route('/new/{exerciseId}', name: 'new', requirements: ['exerciseId' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(int $exerciseId, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) {
            return $redirect;
        }

        /** @var BCExercise|null $exercise */
        $exercise = $this->exerciseRepository->find($exerciseId);
        if ($exercise === null) {
            throw $this->createNotFoundException('BCExercise not found.');
        }

        $this->denyAccessUnlessGranted('ROLE_MANAGER');

        if ($exercise->hasExerciseLog()) {
            $this->addFlash('warning', $this->translator->trans('error.log_already_exists', [], 'bsi_200_4_exercise'));
            return $this->redirectToRoute('bcm_exercise_log_show', ['id' => $exercise->getExerciseLog()?->getId()]);
        }

        $log = $this->logService->createFromExercise($exercise);

        $form = $this->createForm(Bsi2004ExerciseLogType::class, $log);

        // Pre-fill unmapped fields from entity
        $form->get('objectivesText')->setData(implode("\n", $log->getObjectives()));
        $form->get('participantsText')->setData(
            implode(', ', array_column($log->getParticipants(), 'name'))
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyUnmappedFields($form, $log);

            $this->entityManager->persist($log);
            $this->entityManager->flush();

            $this->auditLogger->logCustom(
                AuditLogger::ACTION_BSI_2004_LOG_CREATED,
                'Bsi2004ExerciseLog',
                $log->getId(),
                null,
                ['exercise_id' => $exercise->getId()],
                sprintf('BSI-200-4 Logbuch für Übung "%s" erstellt.', $exercise->getName() ?? '?')
            );

            $this->addFlash('success', $this->translator->trans('action.created', [], 'bsi_200_4_exercise'));
            return $this->redirectToRoute('bcm_exercise_log_show', ['id' => $log->getId()]);
        }

        return $this->render('bcm/exercise_log/new.html.twig', [
            'exercise' => $exercise,
            'log'      => $log,
            'form'     => $form,
        ]);
    }

    // -------------------------------------------------------------------------
    // Show: full log detail
    // -------------------------------------------------------------------------

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) {
            return $redirect;
        }

        $log = $this->findLogOr404($id);
        $this->denyAccessUnlessGranted(Bsi2004ExerciseLogVoter::VIEW, $log);

        return $this->render('bcm/exercise_log/show.html.twig', [
            'log' => $log,
        ]);
    }

    // -------------------------------------------------------------------------
    // Edit: edit log (only if not yet submitted)
    // -------------------------------------------------------------------------

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) {
            return $redirect;
        }

        $log = $this->findLogOr404($id);
        $this->denyAccessUnlessGranted(Bsi2004ExerciseLogVoter::EDIT, $log);

        $form = $this->createForm(Bsi2004ExerciseLogType::class, $log);

        // Pre-fill unmapped fields
        $form->get('objectivesText')->setData(implode("\n", $log->getObjectives()));
        $form->get('participantsText')->setData(
            implode(', ', array_column($log->getParticipants(), 'name'))
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyUnmappedFields($form, $log);

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('action.saved', [], 'bsi_200_4_exercise'));
            return $this->redirectToRoute('bcm_exercise_log_show', ['id' => $log->getId()]);
        }

        return $this->render('bcm/exercise_log/edit.html.twig', [
            'log'  => $log,
            'form' => $form,
        ]);
    }

    // -------------------------------------------------------------------------
    // Submit: finalize log
    // -------------------------------------------------------------------------

    #[Route('/{id}/submit', name: 'submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function submit(int $id, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) {
            return $redirect;
        }

        $log = $this->findLogOr404($id);
        $this->denyAccessUnlessGranted(Bsi2004ExerciseLogVoter::EDIT, $log);

        if (!$this->isCsrfTokenValid('bsi_log_submit_' . $log->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.invalid_token', [], 'bsi_200_4_exercise'));
            return $this->redirectToRoute('bcm_exercise_log_show', ['id' => $log->getId()]);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $this->logService->markComplete($log, $user);

        $this->addFlash('success', $this->translator->trans('action.submitted', [], 'bsi_200_4_exercise'));
        return $this->redirectToRoute('bcm_exercise_log_show', ['id' => $log->getId()]);
    }

    // -------------------------------------------------------------------------
    // Confirm: auditor confirms
    // -------------------------------------------------------------------------

    #[Route('/{id}/confirm', name: 'confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function confirm(int $id, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) {
            return $redirect;
        }

        $log = $this->findLogOr404($id);
        $this->denyAccessUnlessGranted(Bsi2004ExerciseLogVoter::CONFIRM, $log);

        if (!$this->isCsrfTokenValid('bsi_log_confirm_' . $log->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.invalid_token', [], 'bsi_200_4_exercise'));
            return $this->redirectToRoute('bcm_exercise_log_show', ['id' => $log->getId()]);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $this->logService->confirmByAuditor($log, $user);

        $this->addFlash('success', $this->translator->trans('action.confirmed', [], 'bsi_200_4_exercise'));
        return $this->redirectToRoute('bcm_exercise_log_show', ['id' => $log->getId()]);
    }

    // -------------------------------------------------------------------------
    // Calendar: 12-month forward view
    // -------------------------------------------------------------------------

    #[Route('/calendar', name: 'calendar', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function calendar(): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        // Load BCExercises scheduled within the next 12 months
        $now  = new \DateTime();
        $end  = (new \DateTime())->modify('+12 months');

        $allExercises = $tenant !== null
            ? $this->exerciseRepository->findUpcoming()
            : [];

        // Filter to max 12-month forward window
        $exercises = array_filter(
            $allExercises,
            static fn (BCExercise $ex): bool =>
                $ex->getExerciseDate() !== null
                && $ex->getExerciseDate() >= $now
                && $ex->getExerciseDate() <= $end
        );

        // Group by month (Y-m key)
        $byMonth = [];
        foreach ($exercises as $ex) {
            /** @var BCExercise $ex */
            $key = $ex->getExerciseDate()?->format('Y-m') ?? 'unknown';
            $byMonth[$key][] = $ex;
        }
        ksort($byMonth);

        return $this->render('bcm/exercise_log/calendar.html.twig', [
            'byMonth'   => $byMonth,
            'rangeFrom' => $now,
            'rangeTo'   => $end,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function findLogOr404(int $id): Bsi2004ExerciseLog
    {
        $log = $this->logRepository->find($id);
        if ($log === null) {
            throw $this->createNotFoundException('Bsi2004ExerciseLog not found.');
        }
        return $log;
    }

    private function applyUnmappedFields(\Symfony\Component\Form\FormInterface $form, Bsi2004ExerciseLog $log): void
    {
        // Objectives from textarea → JSON array
        $objText = (string) $form->get('objectivesText')->getData();
        $objectives = array_values(array_filter(
            array_map('trim', explode("\n", $objText)),
            static fn (string $l): bool => $l !== ''
        ));
        $log->setObjectives($objectives);

        // Participants from comma-separated textarea → JSON array
        $partText = (string) $form->get('participantsText')->getData();
        $participants = [];
        foreach (explode(',', $partText) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $participants[] = ['name' => $name];
            }
        }
        $log->setParticipants($participants);

        // ImprovementActions from collection sub-form
        $actionsData = [];
        /** @var \Symfony\Component\Form\FormInterface $child */
        foreach ($form->get('improvementActionsCollection') as $child) {
            $data = $child->getData();
            if (is_array($data) && !empty($data['description'])) {
                $actionsData[] = $data;
            }
        }
        $log->setImprovementActions($actionsData !== [] ? $actionsData : null);
    }
}
