<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\IdentityProvider;
use App\Entity\IdentityProviderRoleMapping;
use App\Entity\SsoUserApproval;
use App\Entity\User;
use App\Form\IdentityProviderType;
use App\Repository\AuditLogRepository;
use App\Repository\IdentityProviderRepository;
use App\Repository\IdentityProviderRoleMappingRepository;
use App\Repository\SsoUserApprovalRepository;
use App\Security\Voter\IdentityProviderVoter;
use App\Security\Voter\SsoConfigVoter;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\Sso\OidcAuthenticationFlow;
use App\Service\Sso\OidcDiscoveryService;
use App\Service\Sso\SsoSecretEncryption;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SSO Identity-Provider admin controller.
 *
 * Role-Scope Architecture (Phase 4a, spec
 * `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`).
 *
 * Authorization model:
 *  - Class-level {@see TenantScopedAdminVoter::ADMIN_OWN_TENANT} is the
 *    baseline fence — Tenant-Admins configure SSO inside their own tenant
 *    tree, SUPER_ADMIN transparently across any tenant.
 *  - Cross-tenant attempts (modify/review entities belonging to a different
 *    tenant) are rejected by passing the entity's tenant as the voter
 *    subject — replaces the previously duplicated inline
 *    `assertCanModify()` / `assertCanReview()` logic.
 *  - Global IdPs (tenant == null) require SUPER_ADMIN — the voter rejects
 *    `null` subjects for non-SUPER callers.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/sso', name: 'admin_sso_')]
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
final class SsoProviderController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdentityProviderRepository $repo,
        private readonly SsoUserApprovalRepository $approvalRepo,
        private readonly SsoSecretEncryption $secrets,
        private readonly OidcDiscoveryService $discovery,
        private readonly OidcAuthenticationFlow $flow,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
        private readonly ModuleConfigurationService $moduleService,
        private readonly IdentityProviderRoleMappingRepository $roleMappingRepo,
        private readonly AuditLogRepository $auditLogRepo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

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
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $provider = new IdentityProvider();
        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            $provider->setTenant($this->tenantContext->getCurrentTenant());
        }

        return $this->handleForm($request, $provider, isNew: true);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(IdentityProvider $provider, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $this->assertCanModify($provider);
        return $this->handleForm($request, $provider, isNew: false);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('sso_provider_delete')]
    public function delete(IdentityProvider $provider): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $this->assertCanModify($provider);
        $slug = (string) $provider->getSlug();
        $providerId = $provider->getId();
        $this->em->remove($provider);
        $this->em->flush();
        $this->audit->logCustom('sso.provider.delete', 'IdentityProvider', $providerId, null, ['slug' => $slug]);
        $this->addFlash('success', 'SSO provider deleted.');

        return $this->redirectToRoute('admin_sso_index');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('sso_provider_toggle')]
    public function toggle(IdentityProvider $provider): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $this->assertCanModify($provider);
        $provider->setEnabled(!$provider->isEnabled());
        $this->em->flush();
        $this->audit->logCustom('sso.provider.toggle', 'IdentityProvider', $provider->getId(), null, [
            'slug' => $provider->getSlug(),
            'enabled' => $provider->isEnabled(),
        ]);

        return $this->redirectToRoute('admin_sso_index');
    }

    #[Route('/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('sso_provider_test')]
    public function test(IdentityProvider $provider): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

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

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(IdentityProvider $provider): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }
        $this->denyAccessUnlessGranted(IdentityProviderVoter::VIEW, $provider);

        $mappings   = $this->roleMappingRepo->findActiveByProvider($provider);
        $auditTrail = $this->auditLogRepo->findByEntity('IdentityProvider', (int) $provider->getId());
        $last50     = array_slice(array_reverse($auditTrail), 0, 50);

        return $this->render('admin/sso/show.html.twig', [
            'provider'    => $provider,
            'mappings'    => $mappings,
            'audit_trail' => $last50,
        ]);
    }

    #[Route('/{id}/role-mappings', name: 'role_mappings_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('sso_role_mappings')]
    public function roleMappingsSave(IdentityProvider $provider, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }
        $this->denyAccessUnlessGranted(SsoConfigVoter::CONFIGURE, $provider);

        // Remove all existing mappings, re-persist from submitted form data
        foreach ($provider->getRoleMappings() as $m) {
            $this->em->remove($m);
        }
        $this->em->flush();

        $data   = $request->request->all('role_mappings') ?: [];
        $tenant = $provider->getTenant();

        foreach ($data as $row) {
            $claimKey = trim((string) ($row['claimKey'] ?? ''));
            $role     = trim((string) ($row['assignedRole'] ?? ''));
            if ($claimKey === '' || $role === '') {
                continue;
            }
            $mapping = new IdentityProviderRoleMapping();
            $mapping->setIdentityProvider($provider);
            $mapping->setTenant($tenant);
            $mapping->setClaimKey($claimKey);
            $mapping->setClaimValueExpression(trim((string) ($row['claimValueExpression'] ?? '')));
            $mapping->setAssignedRole($role);
            $mapping->setPriority((int) ($row['priority'] ?? 0));
            $mapping->setIsActive(isset($row['isActive']) && $row['isActive'] !== '');
            $mapping->setAuditDescription(($row['auditDescription'] ?? '') !== '' ? (string) $row['auditDescription'] : null);
            $this->em->persist($mapping);
        }
        $this->em->flush();

        $this->audit->logCustom(
            AuditLogger::ACTION_SSO_CONFIG_CHANGED,
            'IdentityProvider',
            $provider->getId(),
            null,
            ['action' => 'role_mappings_saved', 'count' => count($data)],
        );

        $this->addFlash('success', 'Role mappings saved.');

        return $this->redirectToRoute('admin_sso_show', ['id' => $provider->getId()]);
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

    /**
     * Tenant-scope guard for IdentityProvider mutations.
     *
     * Global IdPs (tenant == null) require SUPER_ADMIN, enforced via
     * {@see TenantScopedAdminVoter::ADMIN_GLOBAL_OP}. Tenant IdPs are
     * gated by {@see TenantScopedAdminVoter::ADMIN_OWN_TENANT} against
     * the provider's tenant — SUPER passes any, ROLE_ADMIN only within
     * its accessible tenant tree.
     */
    private function assertCanModify(IdentityProvider $provider): void
    {
        if ($provider->isGlobal()) {
            $this->denyAccessUnlessGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP);
            return;
        }
        $this->denyAccessUnlessGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT, $provider->getTenant());
    }

    // ----------------------------- Approval Queue --------------------------------

    #[Route('/approvals', name: 'approvals', methods: ['GET'])]
    public function approvals(): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $tenant = $this->isGranted('ROLE_SUPER_ADMIN') ? null : $this->tenantContext->getCurrentTenant();
        $approvals = $tenant === null && $this->isGranted('ROLE_SUPER_ADMIN')
            ? $this->approvalRepo->findBy(['status' => SsoUserApproval::STATUS_PENDING], ['requestedAt' => 'DESC'])
            : $this->approvalRepo->findPendingForTenant($tenant);

        return $this->render('admin/sso/approvals.html.twig', [
            'approvals' => $approvals,
        ]);
    }

    #[Route('/approvals/{id}/approve', name: 'approval_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('sso_approval')]
    public function approve(
        SsoUserApproval $approval,
        #[CurrentUser] User $reviewer,
    ): Response {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $this->assertCanReview($approval);
        $user = $this->flow->provisionFromApproval($approval);
        $approval->setStatus(SsoUserApproval::STATUS_APPROVED);
        $approval->setReviewedBy($reviewer);
        $approval->setReviewedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->audit->logCustom('sso.approval.approve', 'SsoUserApproval', $approval->getId(), null, [
            'email' => $approval->getEmail(),
            'user_id' => $user->getId(),
        ]);
        $this->addFlash('success', sprintf('Approved: %s', $approval->getEmail()));

        return $this->redirectToRoute('admin_sso_approvals');
    }

    #[Route('/approvals/{id}/reject', name: 'approval_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('sso_approval')]
    public function reject(
        SsoUserApproval $approval,
        Request $request,
        #[CurrentUser] User $reviewer,
    ): Response {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $this->assertCanReview($approval);
        $reason = trim((string) $request->request->get('reason', ''));
        $approval->setStatus(SsoUserApproval::STATUS_REJECTED);
        $approval->setRejectReason($reason !== '' ? $reason : null);
        $approval->setReviewedBy($reviewer);
        $approval->setReviewedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->audit->logCustom('sso.approval.reject', 'SsoUserApproval', $approval->getId(), null, [
            'email' => $approval->getEmail(),
        ]);
        $this->addFlash('success', sprintf('Rejected: %s', $approval->getEmail()));

        return $this->redirectToRoute('admin_sso_approvals');
    }

    /**
     * Tenant-scope guard for SSO user-approval mutations.
     *
     * Mirrors {@see self::assertCanModify()}: global-tenant approvals
     * require SUPER_ADMIN, tenant-scoped approvals require either
     * SUPER_ADMIN or ROLE_ADMIN with access to the approval's tenant.
     */
    private function assertCanReview(SsoUserApproval $approval): void
    {
        $tenant = $approval->getTenant();
        if ($tenant === null) {
            $this->denyAccessUnlessGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP);
            return;
        }
        $this->denyAccessUnlessGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT, $tenant);
    }
}
