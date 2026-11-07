<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ManagementDecision;
use App\Entity\Tenant;
use App\Enum\ManagementDecisionType;
use App\Repository\ManagementDecisionRepository;
use App\Service\ManagementDecision\ManagementDecisionService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Twig\Attribute\AsTwigFunction;

/**
 * `board_decisions()` — what top management has to decide and has decided,
 * for the board dashboard and the board one-pager:
 *
 * - pending:  proposals waiting for the decision (oldest due first)
 * - missing:  standing approvals the norm expects that are not in force
 *             (policy, risk criteria, SoA, objectives; NIS-2 measures)
 * - expiring: approvals that end within 60 days
 * - recent:   decisions of the last 12 months
 *
 * `management_approval('soa')` — the approval of one standing decision type
 * that is in force now, or null; for the page the decision is about (SoA,
 * objectives, risk criteria), so its owner sees whether it is approved.
 */
final class ManagementDecisionExtension
{
    private const array STANDING = [
        ManagementDecisionType::Policy,
        ManagementDecisionType::RiskCriteria,
        ManagementDecisionType::Soa,
        ManagementDecisionType::Objectives,
    ];

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ManagementDecisionRepository $repository,
        private readonly ManagementDecisionService $decisions,
        private readonly ModuleConfigurationService $modules,
    ) {
    }

    /**
     * @return array{pending: list<ManagementDecision>, missing: list<ManagementDecisionType>,
     *               expiring: list<ManagementDecision>, recent: list<ManagementDecision>}
     */
    #[AsTwigFunction('board_decisions')]
    public function boardDecisions(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant || $tenant->getId() === null) {
            return ['pending' => [], 'missing' => [], 'expiring' => [], 'recent' => []];
        }

        return $this->cache[$tenant->getId()] ??= $this->load($tenant);
    }

    /** @return array{pending: list<ManagementDecision>, missing: list<ManagementDecisionType>, expiring: list<ManagementDecision>, recent: list<ManagementDecision>} */
    private function load(Tenant $tenant): array
    {
        $standing = self::STANDING;
        if ($this->modules->isModuleActive('nis2_dora')) {
            $standing[] = ManagementDecisionType::Nis2Measures;
        }
        $pending = $this->repository->findPending($tenant);
        $pendingTypes = array_map(static fn (ManagementDecision $d): ManagementDecisionType => $d->getType(), $pending);

        return [
            'pending' => $pending,
            'missing' => array_values(array_filter(
                $standing,
                fn (ManagementDecisionType $type): bool => !in_array($type, $pendingTypes, true)
                    && ($type === ManagementDecisionType::RiskCriteria
                        // thresholds changed since the approval count as not approved
                        ? $this->decisions->criteriaApproval($tenant)
                        : $this->decisions->currentApproval($tenant, $type)) === null,
            )),
            'expiring' => $this->repository->findApprovalsEndingBefore($tenant, new DateTimeImmutable('+60 days')),
            'recent' => $this->repository->findDecidedSince($tenant, new DateTimeImmutable('-12 months'), 8),
        ];
    }

    #[AsTwigFunction('management_approval')]
    public function managementApproval(string $type): ?ManagementDecision
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $decisionType = ManagementDecisionType::tryFrom($type);
        if (!$tenant instanceof Tenant || $decisionType === null) {
            return null;
        }

        return $decisionType === ManagementDecisionType::RiskCriteria
            ? $this->decisions->criteriaApproval($tenant)
            : $this->decisions->currentApproval($tenant, $decisionType);
    }
}
