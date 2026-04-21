<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\GuidedTourService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sprint 13 / P3 — Admin-Report: Tour-Completion pro User.
 *
 * ISO 27001 A.6.3 Awareness-Training Audit-Nachweis: welche User haben
 * welche Tour durchlaufen? Plus Bulk-Reset-Action für Admins.
 */
#[IsGranted('ROLE_ADMIN')]
class AdminTourCompletionController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly GuidedTourService $tourService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/tours/completion', name: 'admin_tour_completion_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $roleFilter = $request->query->get('role');
        $onlyIncomplete = $request->query->getBoolean('only_incomplete');
        $search = trim((string) $request->query->get('q', ''));

        $users = $this->userRepository->findBy([], ['email' => 'ASC']);
        $tours = GuidedTourService::ALL_TOURS;

        $rows = [];
        foreach ($users as $user) {
            if ($search !== '' && !$this->matchesSearch($user, $search)) {
                continue;
            }

            $autoRole = $this->autoRoleFor($user);
            if ($roleFilter !== null && $roleFilter !== '' && $autoRole !== $roleFilter) {
                continue;
            }

            $isComplete = $user->hasCompletedTour($autoRole);
            if ($onlyIncomplete && $isComplete) {
                continue;
            }

            $rows[] = [
                'user' => $user,
                'auto_role' => $autoRole,
                'completed_tours' => $user->getCompletedTours(),
                'auto_tour_complete' => $isComplete,
            ];
        }

        // Stats for header strip
        $totalUsers = count($users);
        $completionCount = 0;
        foreach ($users as $u) {
            $autoRole = $this->autoRoleFor($u);
            if ($u->hasCompletedTour($autoRole)) {
                $completionCount++;
            }
        }

        return $this->render('admin/tour_completion/index.html.twig', [
            'rows' => $rows,
            'tours' => $tours,
            'role_filter' => $roleFilter,
            'only_incomplete' => $onlyIncomplete,
            'search' => $search,
            'total_users' => $totalUsers,
            'completion_count' => $completionCount,
        ]);
    }

    #[Route('/admin/tours/completion/export.csv', name: 'admin_tour_completion_export', methods: ['GET'])]
    public function export(): StreamedResponse
    {
        $users = $this->userRepository->findBy([], ['email' => 'ASC']);
        $tours = GuidedTourService::ALL_TOURS;

        $response = new StreamedResponse(function () use ($users, $tours): void {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility
            fwrite($out, "\xEF\xBB\xBF");

            $header = ['email', 'full_name', 'auto_role'];
            foreach ($tours as $tourId) {
                $header[] = "tour_{$tourId}";
            }
            fputcsv($out, $header, ',', '"', '\\');

            foreach ($users as $user) {
                $autoRole = $this->autoRoleFor($user);
                $row = [$user->getEmail(), $user->getFullName() ?? '', $autoRole];
                foreach ($tours as $tourId) {
                    $row[] = $user->hasCompletedTour($tourId) ? '1' : '';
                }
                fputcsv($out, $row, ',', '"', '\\');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="tour-completion.csv"');
        return $response;
    }

    #[Route('/admin/tours/completion/reset/{id}/{role}', name: 'admin_tour_completion_reset', methods: ['POST'], requirements: ['id' => '\d+', 'role' => '[a-z_]+'])]
    public function reset(int $id, string $role, Request $request): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_tour_reset_' . $id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        if ($role === 'all') {
            $user->resetAllTours();
        } elseif (in_array($role, GuidedTourService::ALL_TOURS, true)) {
            $user->resetTour($role);
        } else {
            throw $this->createNotFoundException();
        }

        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.tour_completion.reset_success', [
            '%user%' => $user->getEmail(),
        ], 'admin'));

        return $this->redirectToRoute('admin_tour_completion_index');
    }

    private function matchesSearch(User $user, string $q): bool
    {
        $needle = mb_strtolower($q);
        if (str_contains(mb_strtolower((string) $user->getEmail()), $needle)) {
            return true;
        }
        if (str_contains(mb_strtolower((string) $user->getFullName()), $needle)) {
            return true;
        }
        return false;
    }

    /**
     * Auto-Role-Detection für einen beliebigen User — ohne AuthorizationChecker-
     * Kontext (der wäre auf den eingeloggten Admin bezogen, nicht auf den
     * User in der Zeile). Fällt zurück auf Analyse der User.customRoles.
     */
    private function autoRoleFor(User $user): string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_AUDITOR', $roles, true)) {
            return GuidedTourService::TOUR_AUDITOR;
        }
        if (in_array('ROLE_RISK_OWNER', $roles, true)) {
            return GuidedTourService::TOUR_RISK_OWNER;
        }
        if (in_array('ROLE_COMPLIANCE_MANAGER', $roles, true)) {
            return GuidedTourService::TOUR_CM;
        }
        if (in_array('ROLE_ISB', $roles, true) || in_array('ROLE_MANAGER', $roles, true)) {
            return GuidedTourService::TOUR_ISB;
        }
        if (in_array('ROLE_GROUP_CISO', $roles, true) || in_array('ROLE_ADMIN', $roles, true)) {
            return GuidedTourService::TOUR_CISO;
        }
        return GuidedTourService::TOUR_JUNIOR;
    }
}
