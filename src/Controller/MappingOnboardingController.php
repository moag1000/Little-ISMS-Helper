<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MappingOnboardingService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compliance/mapping-onboarding')]
#[IsGranted('ROLE_USER')]
class MappingOnboardingController extends AbstractController
{
    public function __construct(
        private readonly MappingOnboardingService $onboarding,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('', name: 'app_mapping_onboarding', methods: ['GET'])]
    public function hub(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', 'Kein aktiver Mandant-Kontext.');

            return $this->redirectToRoute('app_dashboard');
        }
        $state = $this->onboarding->state($user, $tenant);
        $stepIndex = min((int) ($state['step'] ?? 0), count(MappingOnboardingService::STEP_IDS) - 1);

        return $this->render('compliance/mapping_onboarding/hub.html.twig', [
            'state' => $state,
            'stepIndex' => $stepIndex,
            'stepIds' => MappingOnboardingService::STEP_IDS,
            'isManager' => $this->isGranted('ROLE_MANAGER'),
        ]);
    }

    #[Route('/advance', name: 'app_mapping_onboarding_advance', methods: ['POST'])]
    #[IsCsrfTokenValid('mapping_onboarding')]
    public function advance(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['error' => 'no_tenant'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('warning', 'Kein aktiver Mandant-Kontext.');

            return $this->redirectToRoute('app_dashboard');
        }
        $state = $this->onboarding->advance($user, $tenant);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['step' => $state['step'], 'completed' => $state['completed']]);
        }

        // PRG for the no-JS path: back to the hub (now showing the next step / progress).
        return $this->redirectToRoute('app_mapping_onboarding');
    }

    #[Route('/reset', name: 'app_mapping_onboarding_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->onboarding->reset($user);

        return $this->json(['reset' => true]);
    }
}
