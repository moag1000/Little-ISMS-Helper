<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Assignee choices for the bulk "assign" action of the list pages (risk,
 * asset, training). The bulk bar loads them on demand and posts the chosen
 * id to the entity's /bulk-assign endpoint, which re-checks tenant and
 * permissions — this list only feeds the picker.
 */
final class BulkAssigneeController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/bulk/assignees', name: 'app_bulk_assignees', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function assignees(): JsonResponse
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $this->json(['assignees' => []]);
        }

        return $this->json([
            'assignees' => array_map(
                static fn (User $u): array => [
                    'id' => $u->getId(),
                    'label' => trim($u->getFullName()) !== '' ? $u->getFullName() . ' (' . $u->getEmail() . ')' : (string) $u->getEmail(),
                ],
                $this->userRepository->findActiveByTenant($tenant),
            ),
        ]);
    }
}
