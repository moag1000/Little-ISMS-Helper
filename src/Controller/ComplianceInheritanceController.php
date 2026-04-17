<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\FulfillmentInheritanceLogRepository;
use App\Repository\UserRepository;
use App\Security\Voter\ComplianceInheritanceVoter;
use App\Service\ComplianceFrameworkActivationService;
use App\Service\ComplianceInheritanceService;
use App\Service\CompliancePolicyService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compliance/inheritance', name: 'app_compliance_inheritance_')]
#[IsGranted('ROLE_MANAGER')]
final class ComplianceInheritanceController extends AbstractController
{
    public function __construct(
        private readonly ComplianceInheritanceService $inheritanceService,
        private readonly ComplianceFrameworkActivationService $activationService,
        private readonly FulfillmentInheritanceLogRepository $logRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly UserRepository $userRepository,
        private readonly TenantContext $tenantContext,
        private readonly CompliancePolicyService $policy,
    ) {
    }

    private function loadManagers(User $currentUser): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $candidates = $tenant !== null
            ? $this->userRepository->findBy(['tenant' => $tenant])
            : [];

        return array_values(array_filter(
            $candidates,
            function (User $u) use ($currentUser): bool {
                if ($u->getId() === $currentUser->getId()) {
                    return false;
                }
                $roles = $u->getRoles();
                return in_array('ROLE_MANAGER', $roles, true)
                    || in_array('ROLE_ADMIN', $roles, true)
                    || in_array('ROLE_SUPER_ADMIN', $roles, true);
            },
        ));
    }

    #[Route('/queue/{frameworkCode}', name: 'queue', methods: ['GET'])]
    public function queue(string $frameworkCode, Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException();
        }

        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if (!$framework instanceof ComplianceFramework) {
            throw $this->createNotFoundException(sprintf('Framework %s not found.', $frameworkCode));
        }

        $statusFilter = $request->query->get('status', 'pending');
        $logs = $this->logRepository->findForQueue($tenant, $framework, $statusFilter);

        /** @var User $current */
        $current = $this->getUser();

        return $this->render('compliance_inheritance/queue.html.twig', [
            'framework' => $framework,
            'logs' => $logs,
            'status_filter' => $statusFilter,
            'pending_count' => $this->inheritanceService->getPendingReviewCount($tenant, $framework),
            'managers' => $this->loadManagers($current),
            'poll_interval_ms' => $this->policy->getInt(CompliancePolicyService::KEY_INHERITANCE_BADGE_POLL, 60) * 1000,
        ]);
    }

    #[Route('/activate/{frameworkCode}', name: 'activate', methods: ['POST'])]
    public function activate(string $frameworkCode): Response
    {
        $this->denyAccessUnlessGranted(ComplianceInheritanceVoter::CREATE_SUGGESTIONS);

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException();
        }

        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if (!$framework instanceof ComplianceFramework) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->activationService->activate($tenant, $framework, $user);

        $this->addFlash('success', 'compliance_inheritance.flash.framework_activated');

        return $this->redirectToRoute('app_compliance_inheritance_queue', [
            'frameworkCode' => $frameworkCode,
        ]);
    }

    #[Route('/bulk-confirm/{frameworkCode}', name: 'bulk_confirm', methods: ['POST'])]
    public function bulkConfirm(string $frameworkCode, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ComplianceInheritanceVoter::CONFIRM);

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException();
        }
        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if (!$framework instanceof ComplianceFramework) {
            throw $this->createNotFoundException();
        }

        $ids = array_map('intval', (array) $request->request->all('log_ids'));
        if ($ids === []) {
            $this->addFlash('warning', 'compliance_inheritance.flash.bulk_no_selection');
            return $this->redirectToRoute('app_compliance_inheritance_queue', ['frameworkCode' => $frameworkCode]);
        }

        $logs = $this->logRepository->findBy(['id' => $ids, 'tenant' => $tenant]);
        /** @var User $user */
        $user = $this->getUser();

        try {
            $result = $this->inheritanceService->bulkConfirm(
                $logs,
                $user,
                (string) $request->request->get('comment', ''),
            );
            $this->addFlash('success', sprintf(
                'Bulk bestätigt: %d übernommen, %d übersprungen.',
                $result['confirmed'],
                $result['skipped'],
            ));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_compliance_inheritance_queue', ['frameworkCode' => $frameworkCode]);
    }

    #[Route('/{id}/confirm', name: 'confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(FulfillmentInheritanceLog $log, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ComplianceInheritanceVoter::CONFIRM, $log);
        $this->assertSameTenant($log);

        /** @var User $user */
        $user = $this->getUser();

        $comment = (string) $request->request->get('comment', '');
        $requestImplement = $request->request->getBoolean('request_implemented');
        $approverId = $request->request->get('four_eyes_approver_id');
        $approver = null;
        if ($requestImplement && $approverId !== null) {
            $approver = $this->userRepository->find((int) $approverId);
        }

        try {
            $this->inheritanceService->confirmInheritance($log, $user, $comment, $requestImplement, $approver);
            $this->addFlash('success', $this->trans('compliance_inheritance.flash.confirmed'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToQueueOf($log);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(FulfillmentInheritanceLog $log, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ComplianceInheritanceVoter::REJECT, $log);
        $this->assertSameTenant($log);

        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->inheritanceService->rejectInheritance($log, $user, (string) $request->request->get('reason', ''));
            $this->addFlash('success', $this->trans('compliance_inheritance.flash.rejected'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToQueueOf($log);
    }

    #[Route('/{id}/override', name: 'override', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function override(FulfillmentInheritanceLog $log, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ComplianceInheritanceVoter::OVERRIDE, $log);
        $this->assertSameTenant($log);

        /** @var User $user */
        $user = $this->getUser();

        $approverId = $request->request->get('four_eyes_approver_id');
        $approver = $approverId !== null ? $this->userRepository->find((int) $approverId) : null;

        try {
            $this->inheritanceService->overrideInheritance(
                $log,
                $user,
                $request->request->getInt('value'),
                (string) $request->request->get('reason', ''),
                $approver,
            );
            $this->addFlash('success', $this->trans('compliance_inheritance.flash.overridden'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToQueueOf($log);
    }

    #[Route('/pending-count', name: 'pending_count', methods: ['GET'])]
    public function pendingCount(): JsonResponse
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return new JsonResponse(['count' => 0]);
        }

        return new JsonResponse([
            'count' => $this->inheritanceService->getPendingReviewCount($tenant),
        ]);
    }

    private function redirectToQueueOf(FulfillmentInheritanceLog $log): Response
    {
        $framework = $log->getFulfillment()?->getRequirement()?->getFramework();
        if ($framework === null) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->redirectToRoute('app_compliance_inheritance_queue', [
            'frameworkCode' => $framework->getCode(),
        ]);
    }

    private function assertSameTenant(FulfillmentInheritanceLog $log): void
    {
        $current = $this->tenantContext->getCurrentTenant();
        if ($current === null || $log->getTenant()?->getId() !== $current->getId()) {
            throw $this->createAccessDeniedException('Tenant mismatch.');
        }
    }

    private function trans(string $key): string
    {
        return $key;
    }
}
