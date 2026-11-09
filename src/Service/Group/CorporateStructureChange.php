<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\GovernanceModel;
use App\Repository\CorporateGovernanceRepository;
use App\Service\AuditLogger;
use App\Service\CorporateStructureService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one writer of the corporate structure (W6): moving an organisation
 * under a parent (or detaching it) and setting the governance rules between
 * subsidiary and parent. Both admin entry points — the tenant management
 * page and the corporate-structure JSON API — go through here, and every
 * change lands in the audit log with the value before and after
 * (ISO 27001 7.5.3, A.8.15): who decides about whose ISMS is documented
 * information.
 *
 * Callers authorise first (scope of the acting admin); this class does not
 * look at the acting user except to name them. Governance rules belong to
 * the SUBSIDIARY's tenant, so lookups run with the tenant filter lifted
 * ({@see HoldingScope::read()}) and name their tenant explicitly.
 */
final readonly class CorporateStructureChange
{
    public const string ACTION_PARENT_SET = 'corporate.parent_set';
    public const string ACTION_PARENT_REMOVED = 'corporate.parent_removed';
    public const string ACTION_GOVERNANCE_SET = 'corporate.governance_set';
    public const string ACTION_GOVERNANCE_REMOVED = 'corporate.governance_removed';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CorporateGovernanceRepository $governanceRepository,
        private CorporateStructureService $structureService,
        private HoldingScope $holdingScope,
        private AuditLogger $auditLogger,
    ) {
    }

    /**
     * Put $tenant under $parent with the default governance $model, or detach
     * it ($parent null: every governance rule of the tenant is removed).
     * Flushes on success.
     *
     * @return list<string> validation errors (nothing is written then)
     */
    public function move(Tenant $tenant, ?Tenant $parent, ?GovernanceModel $model, ?User $by): array
    {
        $oldParent = $tenant->getParent();
        $oldModel = $this->defaultRule($tenant)?->getGovernanceModel();

        if ($parent === null) {
            $tenant->setParent(null);
            $this->holdingScope->read(fn (): int => $this->structureService->removeAllGovernanceRules($tenant, 'parent_removed'));
            $errors = $this->structureService->validateStructure($tenant);
            if ($errors !== []) {
                $tenant->setParent($oldParent);

                return $errors;
            }
            $this->entityManager->flush();
            $this->log(self::ACTION_PARENT_REMOVED, $tenant, ['parent_id' => $oldParent?->getId(), 'governance_model' => $oldModel?->value], ['parent_id' => null]);

            return [];
        }

        if ($parent === $tenant || $parent->isChildOf($tenant)) {
            return ['Circular reference detected in corporate structure'];
        }
        if ($model === null) {
            return ['Valid governance model is required'];
        }

        $tenant->setParent($parent);
        $parent->setIsCorporateParent(true);
        $rule = $this->defaultRule($tenant);
        if (!$rule instanceof CorporateGovernance) {
            $rule = (new CorporateGovernance())
                ->setTenant($tenant)
                ->setScope('default')
                ->setScopeId(null)
                ->setCreatedBy($by);
        }
        $rule->setGovernanceModel($model);
        $this->structureService->reparentGovernanceRules($tenant, $parent, $rule);
        $this->entityManager->persist($rule);

        $errors = $this->structureService->validateStructure($tenant);
        if ($errors !== []) {
            $tenant->setParent($oldParent);
            $this->entityManager->detach($rule);

            return $errors;
        }
        $this->entityManager->flush();
        $this->log(
            self::ACTION_PARENT_SET,
            $tenant,
            ['parent_id' => $oldParent?->getId(), 'governance_model' => $oldModel?->value],
            ['parent_id' => $parent->getId(), 'governance_model' => $model->value],
        );

        return [];
    }

    /**
     * Record a parent change made elsewhere (the tenant form saves the parent
     * field itself). Call after the flush; no entry when nothing changed.
     */
    public function recordParentChange(Tenant $tenant, ?Tenant $oldParent): void
    {
        $newParent = $tenant->getParent();
        if ($oldParent?->getId() === $newParent?->getId()) {
            return;
        }
        $this->log(
            $newParent === null ? self::ACTION_PARENT_REMOVED : self::ACTION_PARENT_SET,
            $tenant,
            ['parent_id' => $oldParent?->getId()],
            ['parent_id' => $newParent?->getId()],
        );
    }

    /**
     * Create or change the governance rule of $tenant for one scope (default:
     * scope 'default', no scope id). $createMissing false: only an existing
     * rule is changed (null when there is none). Flushes.
     */
    public function setGovernance(
        Tenant $tenant,
        string $scope,
        ?string $scopeId,
        GovernanceModel $model,
        ?string $notes,
        ?User $by,
        bool $createMissing = true,
    ): ?CorporateGovernance {
        $rule = $this->holdingScope->read(fn (): ?CorporateGovernance => $this->governanceRepository->findGovernanceForScope($tenant, $scope, $scopeId));
        $old = $rule instanceof CorporateGovernance ? $this->ruleValues($rule) : null;
        if (!$rule instanceof CorporateGovernance) {
            if (!$createMissing) {
                return null;
            }
            $rule = (new CorporateGovernance())
                ->setTenant($tenant)
                ->setParent($tenant->getParent())
                ->setScope($scope)
                ->setScopeId($scopeId)
                ->setCreatedBy($by);
        }
        $rule->setGovernanceModel($model);
        if ($notes !== null) {
            $rule->setNotes($notes);
        }
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
        $this->log(self::ACTION_GOVERNANCE_SET, $tenant, $old, $this->ruleValues($rule), 'CorporateGovernance', $rule->getId());

        return $rule;
    }

    public function removeGovernance(CorporateGovernance $rule): void
    {
        $tenant = $rule->getTenant();
        $id = $rule->getId();
        $old = $this->ruleValues($rule);
        $this->entityManager->remove($rule);
        $this->entityManager->flush();
        if ($tenant instanceof Tenant) {
            $this->log(self::ACTION_GOVERNANCE_REMOVED, $tenant, $old, null, 'CorporateGovernance', $id);
        }
    }

    public function findRule(Tenant $tenant, string $scope, ?string $scopeId): ?CorporateGovernance
    {
        return $this->holdingScope->read(fn (): ?CorporateGovernance => $this->governanceRepository->findGovernanceForScope($tenant, $scope, $scopeId));
    }

    public function defaultRule(Tenant $tenant): ?CorporateGovernance
    {
        if ($tenant->getId() === null) {
            return null;
        }

        return $this->holdingScope->read(fn (): ?CorporateGovernance => $this->governanceRepository->findDefaultGovernance($tenant));
    }

    /** @return array<string, mixed> */
    private function ruleValues(CorporateGovernance $rule): array
    {
        return [
            'tenant_id' => $rule->getTenant()?->getId(),
            'parent_id' => $rule->getParent()?->getId(),
            'scope' => $rule->getScope(),
            'scope_id' => $rule->getScopeId(),
            'governance_model' => $rule->getGovernanceModel()?->value,
        ];
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function log(string $action, Tenant $tenant, ?array $old, ?array $new, string $entityType = 'Tenant', ?int $entityId = null): void
    {
        $this->auditLogger->logCustom(
            $action,
            $entityType,
            $entityId ?? $tenant->getId(),
            $old,
            $new,
            sprintf('Corporate structure of organisation %d changed (%s)', (int) $tenant->getId(), $action),
        );
    }
}
