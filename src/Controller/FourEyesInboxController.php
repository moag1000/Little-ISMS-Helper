<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/four-eyes/inbox', name: 'app_four_eyes_inbox')]
// ROLE_USER: a designated approver (e.g. top management) is not always a
// manager; they only see the requests they are responsible for.
#[IsGranted('ROLE_USER')]
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
        if (!$this->isGranted('ROLE_MANAGER')) {
            $pending = array_values(array_filter(
                $pending,
                fn (object $r): bool => $this->isGranted(RecordResponsibleVoter::RESPONSIBLE, $r),
            ));
        }

        return $this->render('four_eyes/inbox.html.twig', [
            'requests' => $pending,
        ]);
    }
}
