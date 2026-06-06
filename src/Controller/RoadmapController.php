<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\RoadmapAllocation;
use App\Entity\Tenant;
use App\Repository\PersonRepository;
use App\Repository\PlanningSettingsRepository;
use App\Repository\RoadmapAllocationRepository;
use App\Repository\RoadmapTaskRepository;
use App\Service\ModuleConfigurationService;
use App\Service\Planning\CapacityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Capacity roadmap — plan PT per Roadmap-Task per ISO week, compared against the
 * tenant's available ISMS capacity (CapacityService). Modes: plan (editable),
 * capacity (read-only capacity view), reconcile (over/under colouring).
 */
final class RoadmapController extends AbstractController
{
    use ModuleGatedControllerTrait;

    private const int DEFAULT_WEEKS = 12;
    private const int MAX_WEEKS = 52;

    public function __construct(
        private readonly RoadmapTaskRepository $taskRepository,
        private readonly RoadmapAllocationRepository $allocationRepository,
        private readonly PersonRepository $personRepository,
        private readonly CapacityService $capacityService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
        private readonly PlanningSettingsRepository $settingsRepository,
    ) {
    }

    #[Route('/planning/roadmap', name: 'app_planning_roadmap', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $tenant = $this->security->getUser()?->getTenant();
        $settings = $tenant instanceof Tenant ? $this->settingsRepository->findForTenant($tenant) : null;
        $defaultWeeks = $settings?->getRoadmapHorizonWeeks() ?? self::DEFAULT_WEEKS;
        $overbookingPct = $settings?->getOverbookingThresholdPct() ?? 100;

        $mode = (string) $request->query->get('mode', 'plan');
        $weekCount = max(1, min(self::MAX_WEEKS, $request->query->getInt('weeks', $defaultWeeks)));

        $window = $this->buildWindow($weekCount);
        $tasks = $tenant instanceof Tenant ? $this->taskRepository->findActiveByTenant($tenant) : [];

        // Allocations keyed "taskId-year-week".
        $allocations = [];
        if ($tenant instanceof Tenant) {
            $byYear = [];
            foreach ($window as $w) {
                $byYear[$w['year']][] = $w['week'];
            }
            foreach ($byYear as $year => $weeks) {
                foreach ($this->allocationRepository->findForWindowKeyed($tenant, (int) $year, $weeks) as $alloc) {
                    $allocations[$alloc->getRoadmapTask()?->getId() . '-' . $year . '-' . $alloc->getIsoWeek()]
                        = $alloc->getPlannedPt();
                }
            }
        }

        // Capacity + planned totals per week column.
        $persons = $tenant instanceof Tenant
            ? $this->personRepository->findBy(['tenant' => $tenant, 'active' => true])
            : [];
        $capacityPerWeek = [];
        $plannedPerWeek = [];
        foreach ($window as $w) {
            $key = $w['year'] . '-' . $w['week'];
            $cap = 0.0;
            foreach ($persons as $person) {
                $cap += $this->capacityService->personAvailablePt($person, $tenant, $w['year'], $w['week']);
            }
            $capacityPerWeek[$key] = round($cap, 1);

            $planned = 0.0;
            foreach ($tasks as $task) {
                $planned += (float) ($allocations[$task->getId() . '-' . $key] ?? 0);
            }
            $plannedPerWeek[$key] = round($planned, 1);
        }

        return $this->render('planning/roadmap/index.html.twig', [
            'mode' => in_array($mode, ['plan', 'capacity', 'reconcile'], true) ? $mode : 'plan',
            'weeks' => $weekCount,
            'window' => $window,
            'tasks' => $tasks,
            'allocations' => $allocations,
            'capacityPerWeek' => $capacityPerWeek,
            'plannedPerWeek' => $plannedPerWeek,
            'overbookingPct' => $overbookingPct,
        ]);
    }

    /**
     * ISO 27001 Cl. 9.3 management-review input: Σ planned PT per ISMS domain
     * over the horizon vs total available capacity.
     */
    #[Route('/planning/roadmap/report', name: 'app_planning_roadmap_report', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function report(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) {
            return $redirect;
        }

        $tenant = $this->security->getUser()?->getTenant();
        $settings = $tenant instanceof Tenant ? $this->settingsRepository->findForTenant($tenant) : null;
        $weekCount = max(1, min(self::MAX_WEEKS, $settings?->getRoadmapHorizonWeeks() ?? self::DEFAULT_WEEKS));
        $window = $this->buildWindow($weekCount);

        $tasks = $tenant instanceof Tenant ? $this->taskRepository->findActiveByTenant($tenant) : [];
        $persons = $tenant instanceof Tenant
            ? $this->personRepository->findBy(['tenant' => $tenant, 'active' => true])
            : [];

        // Σ planned PT per ISMS domain across the whole window.
        $allocations = [];
        if ($tenant instanceof Tenant) {
            $byYear = [];
            foreach ($window as $w) {
                $byYear[$w['year']][] = $w['week'];
            }
            foreach ($byYear as $year => $weeks) {
                foreach ($this->allocationRepository->findForWindowKeyed($tenant, (int) $year, $weeks) as $alloc) {
                    $allocations[$alloc->getRoadmapTask()?->getId() . '-' . $year . '-' . $alloc->getIsoWeek()]
                        = $alloc->getPlannedPt();
                }
            }
        }

        $byDomain = [];
        foreach ($tasks as $task) {
            $domain = $task->getIsmsDomain() ?: '—';
            $sum = 0.0;
            foreach ($window as $w) {
                $sum += (float) ($allocations[$task->getId() . '-' . $w['year'] . '-' . $w['week']] ?? 0);
            }
            $byDomain[$domain] = ($byDomain[$domain] ?? 0.0) + $sum;
        }
        ksort($byDomain);

        $totalCapacity = 0.0;
        foreach ($window as $w) {
            foreach ($persons as $person) {
                $totalCapacity += $this->capacityService->personAvailablePt($person, $tenant, $w['year'], $w['week']);
            }
        }
        $totalPlanned = array_sum($byDomain);

        return $this->render('planning/roadmap/report.html.twig', [
            'weeks' => $weekCount,
            'byDomain' => $byDomain,
            'totalPlanned' => round($totalPlanned, 1),
            'totalCapacity' => round($totalCapacity, 1),
        ]);
    }

    #[Route('/planning/roadmap', name: 'app_planning_roadmap_save', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function save(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        if (!$this->isCsrfTokenValid('roadmap_save', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $tenant = $this->security->getUser()?->getTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException();
        }

        $weeks = max(1, min(self::MAX_WEEKS, $request->request->getInt('weeks', self::DEFAULT_WEEKS)));

        /** @var array<int, array<string, string>> $cells cell[taskId][year-week] = pt */
        $cells = $request->request->all('cell');
        $taskCache = [];

        foreach ($cells as $taskId => $byWeek) {
            $task = $taskCache[$taskId] ??= $this->taskRepository->findOneBy(['id' => (int) $taskId, 'tenant' => $tenant]);
            if ($task === null) {
                continue;
            }
            foreach ($byWeek as $yearWeek => $raw) {
                [$year, $week] = array_map('intval', explode('-', (string) $yearWeek) + [0, 0]);
                if ($year <= 0 || $week <= 0) {
                    continue;
                }
                $value = (float) str_replace(',', '.', (string) $raw);
                $alloc = $this->allocationRepository->findCell($tenant, $task, $year, $week);

                if ($value <= 0.0) {
                    if ($alloc !== null) {
                        $this->entityManager->remove($alloc);
                    }
                    continue;
                }
                if ($alloc === null) {
                    $alloc = new RoadmapAllocation();
                    $alloc->setTenant($tenant)->setRoadmapTask($task)->setIsoYear($year)->setIsoWeek($week);
                    $this->entityManager->persist($alloc);
                }
                $alloc->setPlannedPt(number_format($value, 1, '.', ''));
            }
        }

        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('planning.roadmap.success.saved', [], 'planning'));

        return $this->redirectToRoute('app_planning_roadmap', ['weeks' => $weeks]);
    }

    /**
     * Build the rolling window of ISO weeks starting from the current week.
     *
     * @return list<array{year:int, week:int, label:string}>
     */
    private function buildWindow(int $count): array
    {
        $cursor = new \DateTimeImmutable('monday this week');
        $window = [];
        for ($i = 0; $i < $count; $i++) {
            $year = (int) $cursor->format('o');   // ISO-8601 year
            $week = (int) $cursor->format('W');
            $window[] = ['year' => $year, 'week' => $week, 'label' => sprintf('KW%02d', $week)];
            $cursor = $cursor->modify('+1 week');
        }
        return $window;
    }
}
