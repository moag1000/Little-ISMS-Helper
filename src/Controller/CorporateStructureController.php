<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ISMSContext;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\CorporateGovernanceRepository;
use App\Repository\TenantRepository;
use App\Security\TenantOwnership;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\CorporateStructureService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Corporate-structure (holding / subsidiary) API.
 *
 * Authorization model — ROLE_ADMIN is a per-organisation admin, so the
 * class-level fence alone is not enough; every action is checked against
 * the tenant it touches (TenantScopedAdminVoter):
 *
 *  - read (tree, effective context, governance rules, groups):
 *    ADMIN_OWN_TENANT or ADMIN_HOLDING_READ on that tenant, i.e. own tenant
 *    or a descendant of it, or SUPER_ADMIN.
 *  - governance of a subsidiary (default model, per-scope rules) is decided
 *    by the holding: ADMIN_OWN_TENANT on the subsidiary's PARENT. A
 *    subsidiary admin can read but not change how its parent governs it.
 *  - restructuring (set / remove parent): only a SUPER_ADMIN, or an admin
 *    moving a strict descendant of their own tenant to another parent inside
 *    their own tree. Attaching a tenant to another organisation's group
 *    would hand that group read access to it, so it is SUPER_ADMIN-only.
 *  - propagate context: ADMIN_OWN_TENANT on the holding tenant (the targets
 *    are its own subsidiaries).
 */
#[IsGranted('ROLE_ADMIN')]
class CorporateStructureController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly CorporateGovernanceRepository $corporateGovernanceRepository,
        private readonly CorporateStructureService $corporateStructureService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
    ) {
    }

    private function canRead(Tenant $tenant): bool
    {
        return $this->isGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT, $tenant)
            || $this->isGranted(TenantScopedAdminVoter::ADMIN_HOLDING_READ, $tenant);
    }

    /**
     * Governance of $tenant towards its parent is set by the holding side.
     */
    private function canManageGovernanceOf(Tenant $tenant): bool
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }
        $parent = $tenant->getParent();

        return $parent instanceof Tenant && $this->isGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT, $parent);
    }

    /**
     * Moving $tenant under $newParent (null = detach).
     */
    private function canRestructure(Tenant $tenant, ?Tenant $newParent): bool
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }
        $current = $this->tenantContext->getCurrentTenant();
        if (!TenantOwnership::isDescendant($current, $tenant)) {
            return false;
        }

        // Tenant admin: only within the own tree — never to root, never outside (#1059).
        return $newParent instanceof Tenant && $this->isGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT, $newParent);
    }

    /**
     * Run $fn with the Doctrine tenant filter suspended: governance rules and ISMS
     * contexts belong to the SUBSIDIARY's tenant, the request filter to the
     * caller's. Only called AFTER the per-tenant authorization above; every
     * query inside names its tenant. suspend()/restore() keeps the parameter.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withoutTenantFilter(callable $fn): mixed
    {
        $filters = $this->entityManager->getFilters();
        if (!$filters->isEnabled('tenant_filter')) {
            return $fn();
        }
        $filters->suspend('tenant_filter');
        try {
            return $fn();
        } finally {
            $filters->restore('tenant_filter');
        }
    }

    private function accessDenied(): JsonResponse
    {
        return $this->json(['error' => 'Access denied for this organisation'], Response::HTTP_FORBIDDEN);
    }

    /**
     * Get corporate structure tree for a tenant
     */
    #[Route('/api/corporate-structure/tree/{id}', name: 'api_corporate_structure_tree', methods: ['GET'])]
    public function getTree(Tenant $tenant): JsonResponse
    {
        $root = $tenant->getRootParent();
        // The tree spans the whole group, so the caller must be able to read its root.
        if (!$this->canRead($root)) {
            return $this->accessDenied();
        }
        $tree = $this->corporateStructureService->getStructureTree($root);

        return $this->json($tree);
    }

    /**
     * Get all corporate groups (root parents)
     */
    #[Route('/api/corporate-structure/groups', name: 'api_corporate_structure_groups', methods: ['GET'])]
    public function getGroups(): JsonResponse
    {
        $allTenants = $this->tenantRepository->findBy(['isActive' => true]);
        $groups = [];

        foreach ($allTenants as $allTenant) {
            if ($allTenant->isCorporateParent() && $allTenant->getParent() === null && $this->canRead($allTenant)) {
                $groups[] = [
                    'id' => $allTenant->getId(),
                    'code' => $allTenant->getCode(),
                    'name' => $allTenant->getName(),
                    'subsidiaryCount' => $allTenant->getSubsidiaries()->count(),
                    'totalSubsidiaries' => count($allTenant->getAllSubsidiaries())
                ];
            }
        }

        return $this->json($groups);
    }

    /**
     * Set parent for a tenant (create relationship)
     */
    #[Route('/api/corporate-structure/set-parent', name: 'api_corporate_structure_set_parent', methods: ['POST'])]
    public function setParent(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('corporate_structure', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        $tenantId = $data['tenantId'] ?? null;
        $parentId = $data['parentId'] ?? null;
        $governanceModel = $data['governanceModel'] ?? null;

        if (!$tenantId) {
            return $this->json(['error' => 'Tenant ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            return $this->json(['error' => 'Tenant not found'], Response::HTTP_NOT_FOUND);
        }

        // Remove parent if parentId is null
        if ($parentId === null) {
            if (!$this->canRestructure($tenant, null)) {
                return $this->accessDenied();
            }
            $tenant->setParent(null);

            // Delete all governance rules for this tenant (audit-logged)
            $this->withoutTenantFilter(
                fn(): int => $this->corporateStructureService->removeAllGovernanceRules($tenant, 'parent_removed'),
            );
        } else {
            $parent = $this->tenantRepository->find($parentId);
            if (!$parent) {
                return $this->json(['error' => 'Parent tenant not found'], Response::HTTP_NOT_FOUND);
            }
            if (!$this->canRestructure($tenant, $parent)) {
                return $this->accessDenied();
            }

            // Validate governance model
            if (!$governanceModel || !in_array($governanceModel, ['hierarchical', 'shared', 'independent'])) {
                return $this->json(['error' => 'Valid governance model is required'], Response::HTTP_BAD_REQUEST);
            }

            if ($parent === $tenant || $parent->isChildOf($tenant)) {
                $cycle = ['errors' => ['Circular reference detected in corporate structure']];
                return $this->json($cycle, Response::HTTP_BAD_REQUEST);
            }
            $tenant->setParent($parent);
            $parent->setIsCorporateParent(true);

            // Create or update default governance rule
            $governance = $this->withoutTenantFilter(
                fn(): ?CorporateGovernance => $this->corporateGovernanceRepository->findDefaultGovernance($tenant),
            );
            if (!$governance instanceof CorporateGovernance) {
                $governance = new CorporateGovernance();
                $governance->setTenant($tenant);
                $governance->setScope('default');
                $governance->setScopeId(null);
                $governance->setCreatedBy($this->getUser());
            }
            $governance->setGovernanceModel(GovernanceModel::from($governanceModel));
            $this->corporateStructureService->reparentGovernanceRules($tenant, $parent, $governance);
            $this->entityManager->persist($governance);
        }

        // Validate structure
        $errors = $this->corporateStructureService->validateStructure($tenant);
        if ($errors !== []) {
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        // Get default governance for response
        $defaultGovernance = $tenant->getParent() ? $this->withoutTenantFilter(
            fn(): ?CorporateGovernance => $this->corporateGovernanceRepository->findDefaultGovernance($tenant),
        ) : null;

        return $this->json([
            'success' => true,
            'tenant' => [
                'id' => $tenant->getId(),
                'name' => $tenant->getName(),
                'parent' => $tenant->getParent() ? [
                    'id' => $tenant->getParent()->getId(),
                    'name' => $tenant->getParent()->getName()
                ] : null,
                'governanceModel' => $defaultGovernance?->getGovernanceModel()?->value,
                'governanceLabel' => $defaultGovernance?->getGovernanceModel()?->getLabel()
            ]
        ]);
    }

    /**
     * Update governance model for a tenant
     */
    #[Route('/api/corporate-structure/governance-model/{id}', name: 'api_corporate_structure_governance_model', methods: ['PATCH'])]
    public function updateGovernanceModel(Tenant $tenant, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('corporate_structure', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $governanceModel = $data['governanceModel'] ?? null;

        if (!$this->canManageGovernanceOf($tenant)) {
            return $this->accessDenied();
        }

        if (!$governanceModel || !in_array($governanceModel, ['hierarchical', 'shared', 'independent'])) {
            return $this->json(['error' => 'Valid governance model is required'], Response::HTTP_BAD_REQUEST);
        }

        // Update default governance rule
        $governance = $this->withoutTenantFilter(
            fn(): ?CorporateGovernance => $this->corporateGovernanceRepository->findDefaultGovernance($tenant),
        );
        if (!$governance instanceof CorporateGovernance) {
            return $this->json(['error' => 'No default governance found for this tenant'], Response::HTTP_NOT_FOUND);
        }

        $governance->setGovernanceModel(GovernanceModel::from($governanceModel));
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'tenant' => [
                'id' => $tenant->getId(),
                'name' => $tenant->getName(),
                'governanceModel' => $governance->getGovernanceModel()->value,
                'governanceLabel' => $governance->getGovernanceModel()->getLabel()
            ]
        ]);
    }

    /**
     * Get effective ISMS context for a tenant
     */
    #[Route('/api/corporate-structure/effective-context/{id}', name: 'api_corporate_structure_effective_context', methods: ['GET'])]
    public function getEffectiveContext(Tenant $tenant): JsonResponse
    {
        if (!$this->canRead($tenant)) {
            return $this->accessDenied();
        }

        $context = $this->withoutTenantFilter(
            fn(): ?ISMSContext => $this->corporateStructureService->getEffectiveISMSContext($tenant),
        );

        if (!$context instanceof ISMSContext) {
            return $this->json(['error' => 'No ISMS context found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'context' => [
                'id' => $context->getId(),
                'organizationName' => $context->getOrganizationName(),
                'tenant' => [
                    'id' => $context->getTenant()->getId(),
                    'name' => $context->getTenant()->getName()
                ],
                'isInherited' => $context->getTenant()->getId() !== $tenant->getId(),
                'inheritedFrom' => $context->getTenant()->getId() !== $tenant->getId() ? [
                    'id' => $context->getTenant()->getId(),
                    'name' => $context->getTenant()->getName()
                ] : null
            ]
        ]);
    }

    /**
     * Get all available governance models
     */
    #[Route('/api/corporate-structure/governance-models', name: 'api_corporate_structure_governance_models', methods: ['GET'])]
    public function getGovernanceModels(): JsonResponse
    {
        $models = [];
        foreach (GovernanceModel::cases() as $model) {
            $models[] = [
                'value' => $model->value,
                'label' => $model->getLabel(),
                'description' => $model->getDescription()
            ];
        }

        return $this->json($models);
    }

    /**
     * Check access permissions for a tenant
     */
    #[Route('/api/corporate-structure/check-access/{targetId}', name: 'api_corporate_structure_check_access', methods: ['GET'])]
    public function checkAccess(int $targetId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userTenant = $user->getTenant();
        if (!$userTenant) {
            return $this->json(['error' => 'User has no tenant'], Response::HTTP_BAD_REQUEST);
        }

        $targetTenant = $this->tenantRepository->find($targetId);
        if (!$targetTenant) {
            return $this->json(['error' => 'Target tenant not found'], Response::HTTP_NOT_FOUND);
        }
        // Relationship details of foreign organisations are not disclosed.
        $hasAccess = $this->corporateStructureService->canAccessTenant($userTenant, $targetTenant);
        if (!$hasAccess && !$this->canRead($targetTenant)) {
            return $this->json(['hasAccess' => false, 'reason' => 'No access relationship']);
        }

        return $this->json([
            'hasAccess' => $hasAccess,
            'reason' => $this->getAccessReason($userTenant, $targetTenant, $hasAccess)
        ]);
    }

    private function getAccessReason(Tenant $userTenant, Tenant $targetTenant, bool $hasAccess): string
    {
        if ($userTenant->getId() === $targetTenant->getId()) {
            return 'Same tenant';
        }

        if ($this->corporateStructureService->isParentOf($userTenant, $targetTenant)) {
            return 'User tenant is parent of target';
        }

        if ($hasAccess && $this->corporateStructureService->isInSameCorporateGroup($userTenant, $targetTenant)) {
            return 'Same corporate group with shared governance';
        }

        return 'No access relationship';
    }

    /**
     * Propagate ISMS context changes to subsidiaries
     */
    #[Route('/api/corporate-structure/propagate-context/{id}', name: 'api_corporate_structure_propagate_context', methods: ['POST'])]
    public function propagateContext(Tenant $tenant, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('corporate_structure', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT, $tenant)) {
            return $this->accessDenied();
        }

        $context = $this->withoutTenantFilter(
            fn(): ?ISMSContext => $this->corporateStructureService->getEffectiveISMSContext($tenant),
        );

        if (!$context instanceof ISMSContext) {
            return $this->json(['error' => 'No ISMS context found for tenant'], Response::HTTP_NOT_FOUND);
        }

        if (!$tenant->isCorporateParent()) {
            return $this->json(['error' => 'Tenant is not a corporate parent'], Response::HTTP_BAD_REQUEST);
        }

        $updatedCount = $this->withoutTenantFilter(
            fn(): int => $this->corporateStructureService->propagateContextChanges($tenant, $context),
        );

        return $this->json([
            'success' => true,
            'updatedCount' => $updatedCount,
            'message' => sprintf('ISMS context propagated to %d subsidiary(ies)', $updatedCount)
        ]);
    }

    /**
     * Get all governance rules for a tenant
     */
    #[Route('/api/corporate-structure/{tenantId}/governance', name: 'api_corporate_structure_get_governance', methods: ['GET'])]
    public function getGovernanceRules(int $tenantId): JsonResponse
    {
        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            return $this->json(['error' => 'Tenant not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canRead($tenant)) {
            return $this->accessDenied();
        }

        $rules = $this->withoutTenantFilter(
            fn(): array => $this->corporateGovernanceRepository
                ->findBy(['tenant' => $tenant], ['scope' => 'ASC', 'scopeId' => 'ASC']),
        );

        $result = array_map(fn(CorporateGovernance $rule): array => [
            'id' => $rule->getId(),
            'scope' => $rule->getScope(),
            'scopeId' => $rule->getScopeId(),
            'governanceModel' => $rule->getGovernanceModel()->value,
            'governanceLabel' => $rule->getGovernanceModel()->getLabel(),
            'notes' => $rule->getNotes(),
            'parent' => [
                'id' => $rule->getParent()->getId(),
                'name' => $rule->getParent()->getName(),
            ],
            'createdAt' => $rule->getCreatedAt()->format('Y-m-d H:i:s'),
        ], $rules);

        return $this->json([
            'tenant' => [
                'id' => $tenant->getId(),
                'name' => $tenant->getName(),
            ],
            'rules' => $result,
            'total' => count($result),
        ]);
    }

    /**
     * Create or update governance rule for specific scope
     */
    #[Route('/api/corporate-structure/{tenantId}/governance/{scope}', name: 'api_corporate_structure_set_scope_governance', methods: ['POST'])]
    public function setScopeGovernance(int $tenantId, string $scope, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('corporate_structure', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            return $this->json(['error' => 'Tenant not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$tenant->getParent()) {
            return $this->json(['error' => 'Tenant must have a parent to set governance'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->canManageGovernanceOf($tenant)) {
            return $this->accessDenied();
        }

        $data = json_decode($request->getContent(), true);
        $scopeId = $data['scopeId'] ?? null;
        $governanceModel = $data['governanceModel'] ?? null;
        $notes = $data['notes'] ?? null;

        if (!$governanceModel || !in_array($governanceModel, ['hierarchical', 'shared', 'independent'])) {
            return $this->json(['error' => 'Valid governance model is required'], Response::HTTP_BAD_REQUEST);
        }

        // Find existing rule or create new one
        $governance = $this->withoutTenantFilter(
            fn(): ?CorporateGovernance => $this->corporateGovernanceRepository
                ->findGovernanceForScope($tenant, $scope, $scopeId),
        );

        if (!$governance instanceof CorporateGovernance) {
            $governance = new CorporateGovernance();
            $governance->setTenant($tenant);
            $governance->setParent($tenant->getParent());
            $governance->setScope($scope);
            $governance->setScopeId($scopeId);
            $governance->setCreatedBy($this->getUser());
        }

        $governance->setGovernanceModel(GovernanceModel::from($governanceModel));
        if ($notes !== null) {
            $governance->setNotes($notes);
        }

        $this->entityManager->persist($governance);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'rule' => [
                'id' => $governance->getId(),
                'scope' => $governance->getScope(),
                'scopeId' => $governance->getScopeId(),
                'governanceModel' => $governance->getGovernanceModel()->value,
                'governanceLabel' => $governance->getGovernanceModel()->getLabel(),
                'notes' => $governance->getNotes(),
            ],
        ]);
    }

    /**
     * Delete governance rule for specific scope
     */
    #[Route('/api/corporate-structure/{tenantId}/governance/{scope}/{scopeId}', name: 'api_corporate_structure_delete_scope_governance', methods: ['DELETE'])]
    public function deleteScopeGovernance(int $tenantId, string $scope, Request $request, ?string $scopeId = null): JsonResponse
    {
        if (!$this->isCsrfTokenValid('corporate_structure', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            return $this->json(['error' => 'Tenant not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canManageGovernanceOf($tenant)) {
            return $this->accessDenied();
        }

        $governance = $this->withoutTenantFilter(
            fn(): ?CorporateGovernance => $this->corporateGovernanceRepository
                ->findGovernanceForScope($tenant, $scope, $scopeId),
        );

        if (!$governance instanceof CorporateGovernance) {
            return $this->json(['error' => 'Governance rule not found'], Response::HTTP_NOT_FOUND);
        }

        // Don't allow deletion of default governance if tenant has parent
        if ($governance->getScope() === 'default' && $governance->getScopeId() === null && $tenant->getParent()) {
            return $this->json(['error' => 'Cannot delete default governance for subsidiary'], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->remove($governance);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Governance rule deleted',
        ]);
    }
}
