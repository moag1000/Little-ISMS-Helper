<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\TenantRepository;
use App\Service\CorporateStructureService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/corporate-structure')]
#[IsGranted('ROLE_ADMIN')]
class CorporateStructureController extends AbstractController
{
    public function __construct(
        private TenantRepository $tenantRepository,
        private CorporateStructureService $corporateStructureService,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Get corporate structure tree for a tenant
     */
    #[Route('/tree/{id}', name: 'api_corporate_structure_tree', methods: ['GET'])]
    public function getTree(Tenant $tenant): JsonResponse
    {
        $root = $tenant->getRootParent();
        $tree = $this->corporateStructureService->getStructureTree($root);

        return $this->json($tree);
    }

    /**
     * Get all corporate groups (root parents)
     */
    #[Route('/groups', name: 'api_corporate_structure_groups', methods: ['GET'])]
    public function getGroups(): JsonResponse
    {
        $allTenants = $this->tenantRepository->findBy(['isActive' => true]);
        $groups = [];

        foreach ($allTenants as $tenant) {
            if ($tenant->isCorporateParent() && $tenant->getParent() === null) {
                $groups[] = [
                    'id' => $tenant->getId(),
                    'code' => $tenant->getCode(),
                    'name' => $tenant->getName(),
                    'subsidiaryCount' => $tenant->getSubsidiaries()->count(),
                    'totalSubsidiaries' => count($tenant->getAllSubsidiaries())
                ];
            }
        }

        return $this->json($groups);
    }

    /**
     * Set parent for a tenant (create relationship)
     */
    #[Route('/set-parent', name: 'api_corporate_structure_set_parent', methods: ['POST'])]
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
            $tenant->setGovernanceModel(null);
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
            $tenant->setGovernanceModel(GovernanceModel::from($governanceModel));
            $parent->setIsCorporateParent(true);
        }

        // Validate structure
        $errors = $this->corporateStructureService->validateStructure($tenant);
        if (!empty($errors)) {
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'tenant' => [
                'id' => $tenant->getId(),
                'name' => $tenant->getName(),
                'parent' => $tenant->getParent() ? [
                    'id' => $tenant->getParent()->getId(),
                    'name' => $tenant->getParent()->getName()
                ] : null,
                'governanceModel' => $tenant->getGovernanceModel()?->value,
                'governanceLabel' => $tenant->getGovernanceModel()?->getLabel()
            ]
        ]);
    }

    /**
     * Update governance model for a tenant
     */
    #[Route('/governance-model/{id}', name: 'api_corporate_structure_governance_model', methods: ['PATCH'])]
    public function updateGovernanceModel(Tenant $tenant, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $governanceModel = $data['governanceModel'] ?? null;

        if (!$governanceModel || !in_array($governanceModel, ['hierarchical', 'shared', 'independent'])) {
            return $this->json(['error' => 'Valid governance model is required'], Response::HTTP_BAD_REQUEST);
        }

        $tenant->setGovernanceModel(GovernanceModel::from($governanceModel));
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'tenant' => [
                'id' => $tenant->getId(),
                'name' => $tenant->getName(),
                'governanceModel' => $tenant->getGovernanceModel()->value,
                'governanceLabel' => $tenant->getGovernanceModel()->getLabel()
            ]
        ]);
    }

    /**
     * Get effective ISMS context for a tenant
     */
    #[Route('/effective-context/{id}', name: 'api_corporate_structure_effective_context', methods: ['GET'])]
    public function getEffectiveContext(Tenant $tenant): JsonResponse
    {
        $context = $this->corporateStructureService->getEffectiveISMSContext($tenant);

        if (!$context) {
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
    #[Route('/governance-models', name: 'api_corporate_structure_governance_models', methods: ['GET'])]
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
    #[Route('/check-access/{targetId}', name: 'api_corporate_structure_check_access', methods: ['GET'])]
    public function checkAccess(int $targetId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
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
    #[Route('/propagate-context/{id}', name: 'api_corporate_structure_propagate_context', methods: ['POST'])]
    public function propagateContext(Tenant $tenant): JsonResponse
    {
        $context = $this->corporateStructureService->getEffectiveISMSContext($tenant);

        if (!$context) {
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
}
