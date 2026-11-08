<?php

declare(strict_types=1);

namespace App\Service\Risk;

use App\Entity\Tenant;
use App\Enum\TreatmentStrategy;
use App\Repository\RiskRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Service\RiskApprovalConfigResolver;
use App\Service\RiskApprovalConfigView;
use DateTimeInterface;

/**
 * Requested risk acceptances (ISO 27005 §8.4.4, ISO 27001 6.1.3 f): one duty
 * per risk with strategy "accept", a justification and no decision yet. The
 * approver is the level the organisation's approval thresholds give for the
 * residual risk — the same rule as RiskAcceptanceVoter: management level goes
 * to the risk management roles, executive level to top management.
 *
 * As a deadline source the request reaches My Day (decided in place), the
 * morning digest and the assistant's `my_day`.
 */
final class RiskAcceptanceRequestSource implements DeadlineSource
{
    /** Who decides an acceptance on the management level (all reach ROLE_MANAGER, the voter's rule). */
    public const array MANAGER_LEVEL_ROLES = ['ROLE_RISK_MANAGER', 'ROLE_CISO', 'ROLE_ISB'];

    public function __construct(
        private readonly RiskRepository $risks,
        private readonly RiskApprovalConfigResolver $approvalConfig,
    ) {
    }

    public function slug(): string
    {
        return 'risk_acceptance_request';
    }

    public function label(): string
    {
        return 'Risikoakzeptanz zu entscheiden';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return list<RiskAcceptanceRequest> */
    public function findOpen(Tenant $tenant): iterable
    {
        $requests = [];
        foreach ($this->risks->findBy([
            'tenant' => $tenant,
            'treatmentStrategy' => TreatmentStrategy::Accept,
            'formallyAccepted' => false,
        ]) as $risk) {
            if ($risk->getAcceptanceJustification() !== null) {
                $requests[] = new RiskAcceptanceRequest($risk);
            }
        }

        return $requests;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        return null;
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof RiskAcceptanceRequest);

        return (string) $item->risk->getTitle();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof RiskAcceptanceRequest);

        return $item->risk->isFormallyAccepted() || $item->risk->getTreatmentStrategy() !== TreatmentStrategy::Accept;
    }

    public function refId(object $item): int
    {
        assert($item instanceof RiskAcceptanceRequest);

        return (int) $item->risk->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof RiskAcceptanceRequest;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof RiskAcceptanceRequest);

        $tenant = $item->risk->getTenant();
        $config = $tenant instanceof Tenant ? $this->approvalConfig->resolveFor($tenant) : RiskApprovalConfigView::defaults();
        $role = RiskApprovalConfigView::approverRole($config->levelFor((int) $item->risk->getResidualRiskLevel()));

        return $role === 'ROLE_MANAGER'
            ? Responsibility::roles(...self::MANAGER_LEVEL_ROLES)
            : Responsibility::roles($role);
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof RiskAcceptanceRequest);

        return new RecordLink('app_risk_show', ['id' => (int) $item->risk->getId(), '_fragment' => 'acceptance']);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(
            bucket: 'risk_acceptance_pending',
            leadDays: null,
            normReference: 'ISO 27005 8.4.4 / ISO 27001 6.1.3 f',
            decision: true,
        );
    }
}
