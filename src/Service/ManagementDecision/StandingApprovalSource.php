<?php

declare(strict_types=1);

namespace App\Service\ManagementDecision;

use App\Entity\ManagementDecision;
use App\Entity\Tenant;
use App\Enum\ManagementDecisionType;
use App\Repository\ManagementDecisionRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\ModuleConfigurationService;
use App\Service\Responsibility\Responsibility;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Standing approvals the norms expect from top management (policy, risk
 * criteria, SoA, objectives, resources; NIS-2 measures) that are missing or
 * end within 60 days, and no proposal is waiting yet. The ISB prepares the
 * proposal; the board then decides it through {@see ManagementDecisionDeadlineSource}.
 * The board panel reads the same list ({@see self::missing()}).
 */
final class StandingApprovalSource implements DeadlineSource
{
    /** Approvals top management has to keep in force. */
    public const array STANDING = [
        ManagementDecisionType::Policy,
        ManagementDecisionType::RiskCriteria,
        ManagementDecisionType::Soa,
        ManagementDecisionType::Objectives,
        ManagementDecisionType::Resources,
    ];

    public const int LEAD_DAYS = 60;

    /**
     * @param (\Closure(): ModuleConfigurationService)|null $modules lazy: sources reach Doctrine listeners
     */
    public function __construct(
        private readonly ManagementDecisionRepository $repository,
        private readonly ManagementDecisionService $decisions,
        private readonly TranslatorInterface $translator,
        #[AutowireServiceClosure(ModuleConfigurationService::class)]
        private readonly ?\Closure $modules = null,
    ) {
    }

    public function slug(): string
    {
        return 'standing_approval';
    }

    public function label(): string
    {
        return 'Leitungsentscheidung fehlt oder läuft ab';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return list<ManagementDecisionType> */
    public function standingTypes(Tenant $tenant): array
    {
        $types = self::STANDING;
        if ($this->modules !== null && in_array('nis2_dora', ($this->modules)()->getActiveModulesForTenant($tenant), true)) {
            $types[] = ManagementDecisionType::Nis2Measures;
        }

        return $types;
    }

    /**
     * Standing types without an approval in force and without a pending proposal.
     *
     * @return list<ManagementDecisionType>
     */
    public function missing(Tenant $tenant): array
    {
        $pending = $this->pendingTypes($tenant);

        return array_values(array_filter(
            $this->standingTypes($tenant),
            fn (ManagementDecisionType $type): bool => !in_array($type, $pending, true) && $this->approvalOf($tenant, $type) === null,
        ));
    }

    /** @return list<StandingApproval> */
    public function findOpen(Tenant $tenant): iterable
    {
        $items = array_map(static fn (ManagementDecisionType $t): StandingApproval => new StandingApproval($t), $this->missing($tenant));
        $pending = $this->pendingTypes($tenant);
        $standing = $this->standingTypes($tenant);
        foreach ($this->repository->findApprovalsEndingBefore($tenant, new DateTimeImmutable(sprintf('+%d days', self::LEAD_DAYS))) as $approval) {
            if (in_array($approval->getType(), $standing, true) && !in_array($approval->getType(), $pending, true)
                && $this->approvalOf($tenant, $approval->getType()) === $approval) {
                $items[] = new StandingApproval($approval->getType(), $approval);
            }
        }

        return $items;
    }

    /** Missing approvals are due now; running-out ones at their end date. */
    public function dueDateOf(object $item): DateTimeInterface
    {
        assert($item instanceof StandingApproval);

        return $item->endsAt() ?? new DateTimeImmutable('today');
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof StandingApproval);

        return $this->translator->trans($item->type->label(), [], 'management_decision');
    }

    public function isCompleted(object $item): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof StandingApproval);

        return (int) array_search($item->type, ManagementDecisionType::cases(), true) + 1;
    }

    public function supports(object $item): bool
    {
        return $item instanceof StandingApproval;
    }

    public function responsibleOf(object $item): Responsibility
    {
        return Responsibility::roles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof StandingApproval);

        return new RecordLink('app_management_decision_new', ['type' => $item->type->value]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'management_approvals_missing', leadDays: self::LEAD_DAYS, normReference: 'ISO 27001 5.1, 6.1.2, 6.1.3 d, 6.2, 7.1 / NIS-2 Art. 20');
    }

    /** @return list<ManagementDecisionType> */
    private function pendingTypes(Tenant $tenant): array
    {
        return array_map(static fn (ManagementDecision $d): ManagementDecisionType => $d->getType(), $this->repository->findPending($tenant));
    }

    private function approvalOf(Tenant $tenant, ManagementDecisionType $type): ?ManagementDecision
    {
        // Changed thresholds after the approval count as not approved.
        return $type === ManagementDecisionType::RiskCriteria
            ? $this->decisions->criteriaApproval($tenant)
            : $this->decisions->currentApproval($tenant, $type);
    }
}
