<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IdentityProvider;
use App\Entity\SsoUserApproval;
use App\Form\IdentityProviderType;
use App\Repository\IdentityProviderRepository;
use App\Repository\SsoUserApprovalRepository;
use App\Service\AuditLogger;
use App\Service\Sso\OidcAuthenticationFlow;
use App\Service\Sso\OidcDiscoveryService;
use App\Service\Sso\SsoSecretEncryption;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/sso', name: 'admin_sso_')]
#[IsGranted('ROLE_ADMIN')]
final class SsoProviderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdentityProviderRepository $repo,
        private readonly SsoUserApprovalRepository $approvalRepo,
        private readonly SsoSecretEncryption $secrets,
        private readonly OidcDiscoveryService $discovery,
        private readonly OidcAuthenticationFlow $flow,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $providers = $this->isGranted('ROLE_SUPER_ADMIN')
            ? $this->repo->findBy([], ['tenant' => 'ASC', 'name' => 'ASC'])
            : $this->repo->findEnabledForTenant($this->tenantContext->getCurrentTenant());

        $pendingCount = count($this->approvalRepo->findPendingForTenant($this->tenantContext->getCurrentTenant()));

        return $this->render('admin/sso/index.html.twig', [
            'providers' => $providers,
            'pending_count' => $pendingCount,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $provider = new IdentityProvider();
        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            $provider->setTenant($this->tenantContext->getCurrentTenant());
        }

        return $this->handleForm($request, $provider, isNew: true);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(IdentityProvider $provider, Request $request): Response
    {
        $this->assertCanModify($provider);
        return $this->handleForm($request, $provider, isNew: false);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    #[IsCsrfTokenValid('sso_provider_delete')]
    public function delete(IdentityProvider $provider): Response
    {
        $this->assertCanModify($provider);
        $slug = (string) $provider->getSlug();
        $providerId = $provider->getId();
        $this->em->remove($provider);
        $this->em->flush();
        $this->audit->logCustom('sso.provider.delete', 'IdentityProvider', $providerId, null, ['slug' => $slug]);
        $this->addFlash('success', 'SSO provider deleted.');

        return $this->redirectToRoute('admin_sso_index');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    #[IsCsrfTokenValid('sso_provider_toggle')]
    public function toggle(IdentityProvider $provider): Response
    {
        $this->assertCanModify($provider);
        $provider->setEnabled(!$provider->isEnabled());
        $this->em->flush();
        $this->audit->logCustom('sso.provider.toggle', 'IdentityProvider', $provider->getId(), null, [
            'slug' => $provider->getSlug(),
            'enabled' => $provider->isEnabled(),
        ]);

        return $this->redirectToRoute('admin_sso_index');
    }

    #[Route('/{id}/test', name: 'test', methods: ['POST'])]
    #[IsCsrfTokenValid('sso_provider_test')]
    public function test(IdentityProvider $provider): Response
    {
        $this->assertCanModify($provider);
        try {
            $this->discovery->applyDiscoveryToProvider($provider);
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'Discovery OK for "%s". Endpoints synced.',
                $provider->getName()
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Discovery failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_sso_edit', ['id' => $provider->getId()]);
    }

    private function handleForm(Request $request, IdentityProvider $provider, bool $isNew): Response
    {
        $allowGlobal = $this->isGranted('ROLE_SUPER_ADMIN');
        $form = $this->createForm(IdentityProviderType::class, $provider, [
            'allow_global' => $allowGlobal,
            'scopes_initial' => $provider->getScopes(),
            'attribute_map_json' => json_encode($provider->getAttributeMap(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            'domain_bindings_initial' => $provider->getDomainBindings(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $scopesCsv = (string) $form->get('scopesCsv')->getData();
            $scopes = $this->parseList($scopesCsv);
            if ($scopes === []) {
                $scopes = ['openid', 'profile', 'email'];
            }
            $provider->setScopes($scopes);

            $attrJson = (string) $form->get('attributeMapJson')->getData();
            if ($attrJson !== '') {
                try {
                    $decoded = json_decode($attrJson, true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $provider->setAttributeMap($decoded);
                    }
                } catch (\JsonException) {
                    $form->get('attributeMapJson')->addError(new \Symfony\Component\Form\FormError('Invalid JSON.'));
                    return $this->render('admin/sso/form.html.twig', [
                        'form' => $form->createView(),
                        'provider' => $provider,
                        'is_new' => $isNew,
                    ]);
                }
            }

            $bindingsCsv = (string) $form->get('domainBindingsCsv')->getData();
            $provider->setDomainBindings($this->parseList($bindingsCsv));

            $secretPlain = $form->get('clientSecretPlain')->getData();
            if (is_string($secretPlain) && $secretPlain !== '') {
                $provider->setClientSecretEncrypted($this->secrets->encrypt($secretPlain));
            }

            $this->em->persist($provider);
            $this->em->flush();
            $this->audit->logCustom(
                $isNew ? 'sso.provider.create' : 'sso.provider.update',
                'IdentityProvider',
                $provider->getId(),
                null,
                [
                    'slug' => $provider->getSlug(),
                    'tenant_id' => $provider->getTenant()?->getId(),
                ]
            );
            $this->addFlash('success', $isNew ? 'SSO provider created.' : 'SSO provider updated.');

            return $this->redirectToRoute('admin_sso_edit', ['id' => $provider->getId()]);
        }

        return $this->render('admin/sso/form.html.twig', [
            'form' => $form->createView(),
            'provider' => $provider,
            'is_new' => $isNew,
        ]);
    }

    /** @return list<string> */
    private function parseList(string $csv): array
    {
        $parts = preg_split('/[\s,;]+/', $csv) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    private function assertCanModify(IdentityProvider $provider): void
    {
        if ($provider->isGlobal()) {
            $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
            return;
        }
        $current = $this->tenantContext->getCurrentTenant();
        $providerTenantId = $provider->getTenant()?->getId();
        $currentTenantId = $current?->getId();
        if (!$this->isGranted('ROLE_SUPER_ADMIN') && $providerTenantId !== $currentTenantId) {
            throw $this->createAccessDeniedException('Cannot modify provider of another tenant.');
        }
    }

    // ----------------------------- Approval Queue --------------------------------

    #[Route('/approvals', name: 'approvals', methods: ['GET'])]
    public function approvals(): Response
    {
        $tenant = $this->isGranted('ROLE_SUPER_ADMIN') ? null : $this->tenantContext->getCurrentTenant();
        $approvals = $tenant === null && $this->isGranted('ROLE_SUPER_ADMIN')
            ? $this->approvalRepo->findBy(['status' => SsoUserApproval::STATUS_PENDING], ['requestedAt' => 'DESC'])
            : $this->approvalRepo->findPendingForTenant($tenant);

        return $this->render('admin/sso/approvals.html.twig', [
            'approvals' => $approvals,
        ]);
    }

    #[Route('/approvals/{id}/approve', name: 'approval_approve', methods: ['POST'])]
    #[IsCsrfTokenValid('sso_approval')]
    public function approve(SsoUserApproval $approval): Response
    {
        $this->assertCanReview($approval);
        $user = $this->flow->provisionFromApproval($approval);
        $approval->setStatus(SsoUserApproval::STATUS_APPROVED);
        $approval->setReviewedBy($this->getUser() instanceof \App\Entity\User ? $this->getUser() : null);
        $approval->setReviewedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->audit->logCustom('sso.approval.approve', 'SsoUserApproval', $approval->getId(), null, [
            'email' => $approval->getEmail(),
            'user_id' => $user->getId(),
        ]);
        $this->addFlash('success', sprintf('Approved: %s', $approval->getEmail()));

        return $this->redirectToRoute('admin_sso_approvals');
    }

    #[Route('/approvals/{id}/reject', name: 'approval_reject', methods: ['POST'])]
    #[IsCsrfTokenValid('sso_approval')]
    public function reject(SsoUserApproval $approval, Request $request): Response
    {
        $this->assertCanReview($approval);
        $reason = trim((string) $request->request->get('reason', ''));
        $approval->setStatus(SsoUserApproval::STATUS_REJECTED);
        $approval->setRejectReason($reason !== '' ? $reason : null);
        $approval->setReviewedBy($this->getUser() instanceof \App\Entity\User ? $this->getUser() : null);
        $approval->setReviewedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->audit->logCustom('sso.approval.reject', 'SsoUserApproval', $approval->getId(), null, [
            'email' => $approval->getEmail(),
        ]);
        $this->addFlash('success', sprintf('Rejected: %s', $approval->getEmail()));

        return $this->redirectToRoute('admin_sso_approvals');
    }

    private function assertCanReview(SsoUserApproval $approval): void
    {
        $tenant = $approval->getTenant();
        if ($tenant === null) {
            $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
            return;
        }
        $current = $this->tenantContext->getCurrentTenant();
        if (!$this->isGranted('ROLE_SUPER_ADMIN') && $tenant->getId() !== $current?->getId()) {
            throw $this->createAccessDeniedException('Cannot review approvals of another tenant.');
        }
    }
}
