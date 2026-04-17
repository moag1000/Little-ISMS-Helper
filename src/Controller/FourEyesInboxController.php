<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/four-eyes/inbox', name: 'app_four_eyes_inbox')]
#[IsGranted('ROLE_MANAGER')]
final class FourEyesInboxController extends AbstractController
{
    public function __construct(
        private readonly FourEyesApprovalRequestRepository $repository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function __invoke(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException();
        }
        /** @var User $user */
        $user = $this->getUser();

        $pending = $this->repository->findPendingFor($user, $tenant);

        return $this->render('four_eyes/inbox.html.twig', [
            'requests' => $pending,
        ]);
    }
}
