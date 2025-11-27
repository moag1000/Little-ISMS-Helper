<?php

namespace App\Controller;

use App\Entity\ISMSContext;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\CorporateGovernanceRepository;
use App\Repository\TenantRepository;
use App\Service\CorporateStructureService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CorporateStructureController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly CorporateGovernanceRepository $corporateGovernanceRepository,
        private readonly CorporateStructureService $corporateStructureService,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Get corporate structure tree for a tenant
     */
    #[Route('/api/corporate-structure/tree/{id}', name: 'api_corporate_structure_tree', methods: ['GET'])]
    public function getTree(Tenant $tenant): JsonResponse
    {
        $root = $tenant->getRootParent();
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
            if ($allTenant->isCorporateParent() && $allTenant->getParent() === null) {
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
            $tenant->setParent(null);

            // Delete all governance rules for this tenant
            $this->entityManager->createQueryBuilder()
                ->delete(CorporateGovernance::class, 'cg')
                ->where('cg.tenant = :tenant')
                ->setParameter('tenant', $tenant)
                ->getQuery()
                ->execute();
        } else {
            $parent = $this->tenantRepository->find($parentId);
            if (!$parent) {
                return $this->json(['error' => 'Parent tenant not found'], Response::HTTP_NOT_FOUND);
            }

            // Validate governance model
            if (!$governanceModel || !in_array($governanceModel, ['hierarchical', 'shared', 'independent'])) {
                return $this->json(['error' => 'Valid governance model is required'], Response::HTTP_BAD_REQUEST);
            }

            $tenant->setParent($parent);
            $parent->setIsCorporateParent(true);

            // Create or update default governance rule
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
            if (!$governance) {
                $governance = new CorporateGovernance();
                $governance->setTenant($tenant);
                $governance->setParent($parent);
                $governance->setScope('default');
                $governance->setScopeId(null);
                $governance->setCreatedBy($this->getUser());
            }
            $governance->setGovernanceModel(GovernanceModel::from($governanceModel));
            $this->entityManager->persist($governance);
        }

        // Validate structure
        $errors = $this->corporateStructureService->validateStructure($tenant);
        if ($errors !== []) {
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        // Get default governance for response
        $defaultGovernance = $tenant->getParent() ? $this->corporateGovernanceRepository->findDefaultGovernance($tenant) : null;

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
        $data = json_decode($request->getContent(), true);
        $governanceModel = $data['governanceModel'] ?? null;

        if (!$governanceModel || !in_array($governanceModel, ['hierarchical', 'shared', 'independent'])) {
            return $this->json(['error' => 'Valid governance model is required'], Response::HTTP_BAD_REQUEST);
        }

        // Update default governance rule
        $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
        if (!$governance) {
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
        $context = $this->corporateStructureService->getEffectiveISMSContext($tenant);

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

        $hasAccess = $this->corporateStructureService->canAccessTenant($userTenant, $targetTenant);

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
    public function propagateContext(Tenant $tenant): JsonResponse
    {
        $context = $this->corporateStructureService->getEffectiveISMSContext($tenant);

        if (!$context instanceof ISMSContext) {
            return $this->json(['error' => 'No ISMS context found for tenant'], Response::HTTP_NOT_FOUND);
        }

        if (!$tenant->isCorporateParent()) {
            return $this->json(['error' => 'Tenant is not a corporate parent'], Response::HTTP_BAD_REQUEST);
        }

        $updatedCount = $this->corporateStructureService->propagateContextChanges($tenant, $context);

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

        $rules = $this->corporateGovernanceRepository->findBy(['tenant' => $tenant], ['scope' => 'ASC', 'scopeId' => 'ASC']);

        $result = array_map(fn($rule): array => [
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
        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            return $this->json(['error' => 'Tenant not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$tenant->getParent()) {
            return $this->json(['error' => 'Tenant must have a parent to set governance'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $scopeId = $data['scopeId'] ?? null;
        $governanceModel = $data['governanceModel'] ?? null;
        $notes = $data['notes'] ?? null;

        if (!$governanceModel || !in_array($governanceModel, ['hierarchical', 'shared', 'independent'])) {
            return $this->json(['error' => 'Valid governance model is required'], Response::HTTP_BAD_REQUEST);
        }

        // Find existing rule or create new one
        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, $scope, $scopeId);

        if (!$governance) {
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
    public function deleteScopeGovernance(int $tenantId, string $scope, ?string $scopeId = null): JsonResponse
    {
        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            return $this->json(['error' => 'Tenant not found'], Response::HTTP_NOT_FOUND);
        }

        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, $scope, $scopeId);

        if (!$governance) {
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
