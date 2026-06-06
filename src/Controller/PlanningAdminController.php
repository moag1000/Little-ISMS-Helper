<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Person;
use App\Entity\Team;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Repository\PersonRepository;
use App\Repository\RoadmapGroupRepository;
use App\Repository\RoadmapTaskRepository;
use App\Repository\TeamRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resource-Planning master-data hub (Stammdaten).
 *
 * Aggregates Teams / Roadmap-Groups / Roadmap-Tasks / Persons + a read-only
 * task×team visibility matrix. CRUD for the individual entities lives in the
 * dedicated controllers; this hub provides the overview tabs and the
 * person-side capacity + team-membership editor (bidirectional membership).
 *
 * Module-gated by `resource_planning`.
 */
final class PlanningAdminController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly RoadmapGroupRepository $groupRepository,
        private readonly RoadmapTaskRepository $taskRepository,
        private readonly PersonRepository $personRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {
    }

    #[Route('/planning', name: 'app_planning_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        return $this->redirectToRoute('app_planning_admin');
    }

    #[Route('/planning/admin', name: 'app_planning_admin', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function admin(): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $tenant = $this->security->getUser()?->getTenant();

        $teams = $tenant ? $this->teamRepository->findByTenant($tenant) : [];
        $groups = $tenant ? $this->groupRepository->findActiveByTenant($tenant) : [];
        $tasks = $tenant ? $this->taskRepository->findActiveByTenant($tenant) : [];
        $persons = $tenant ? $this->personRepository->findBy(['tenant' => $tenant], ['fullName' => 'ASC']) : [];

        // Read-only visibility matrix: which task is visible to which team.
        // A task with visibility 'all' is visible to every team.
        $matrix = [];
        foreach ($tasks as $task) {
            $visibleTeamIds = [];
            foreach ($task->getVisibleTeams() as $vt) {
                $visibleTeamIds[$vt->getId()] = true;
            }
            $matrix[$task->getId()] = [
                'all' => $task->getVisibility() === 'all',
                'teams' => $visibleTeamIds,
            ];
        }

        return $this->render('planning/admin/index.html.twig', [
            'teams' => $teams,
            'groups' => $groups,
            'tasks' => $tasks,
            'persons' => $persons,
            'matrix' => $matrix,
        ]);
    }

    /**
     * Person-side capacity + team-membership editor.
     *
     * Edits the net ISMS availability and (un)assigns the person to teams —
     * the spec's bidirectional membership: the same M:N join is editable from
     * the Team form (members) and here from the Person side.
     */
    #[Route('/planning/persons/{id}/edit', name: 'app_planning_person_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function personEdit(Request $request, Person $person): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant === null || $person->getTenant() !== $tenant) {
            throw $this->createAccessDeniedException();
        }

        // Teams this person currently belongs to (Team owns the M:N).
        $allTeams = $this->teamRepository->findActiveByTenant($tenant);
        $currentTeams = [];
        foreach ($allTeams as $team) {
            if ($team->getMembers()->contains($person)) {
                $currentTeams[] = $team;
            }
        }

        $form = $this->createFormBuilder([
                'ismsAvailabilityPct' => $person->getIsmsAvailabilityPct(),
                'teams' => $currentTeams,
            ], ['translation_domain' => 'planning'])
            ->add('ismsAvailabilityPct', NumberType::class, [
                'label' => 'planning.person.field.availability',
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'max' => 1, 'step' => 0.05],
                'help' => 'planning.person.help.availability',
            ])
            ->add('teams', EntityType::class, [
                'label' => 'planning.person.field.teams',
                'class' => Team::class,
                'choice_label' => 'name',
                'choices' => $allTeams,
                'multiple' => true,
                'mapped' => false,
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('save', SubmitType::class, ['label' => 'common.save'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $person->setIsmsAvailabilityPct((float) $form->get('ismsAvailabilityPct')->getData());

            /** @var list<Team> $selected */
            $selected = $form->get('teams')->getData();
            $selectedIds = array_map(static fn (Team $t): ?int => $t->getId(), $selected);

            foreach ($allTeams as $team) {
                $isSelected = in_array($team->getId(), $selectedIds, true);
                $isMember = $team->getMembers()->contains($person);
                if ($isSelected && !$isMember) {
                    $team->addMember($person);
                } elseif (!$isSelected && $isMember) {
                    $team->removeMember($person);
                }
            }

            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('planning.success.updated', [], 'planning'));

            return $this->redirectToRoute('app_planning_admin');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/admin/person_edit.html.twig', [
            'person' => $person,
            'form' => $form,
        ], new Response(status: $status));
    }
}
