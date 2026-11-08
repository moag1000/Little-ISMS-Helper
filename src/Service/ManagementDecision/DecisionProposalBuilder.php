<?php

declare(strict_types=1);

namespace App\Service\ManagementDecision;

use App\Entity\Control;
use App\Entity\ISMSContext;
use App\Entity\ISMSObjective;
use App\Entity\ManagementReview;
use App\Entity\RiskApprovalConfig;
use App\Entity\SoaSnapshot;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Enum\ManagementDecisionType;
use App\Repository\ControlRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\RiskAppetiteRepository;
use App\Repository\RiskApprovalConfigRepository;
use App\Repository\SoaSnapshotRepository;
use App\Repository\SupplierRepository;
use App\Service\RiskApprovalConfigResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Prepares what is put before top management, from the records that already
 * exist — the person preparing it checks and adds, top management decides.
 *
 * One method per decision type; each returns the subject the decision is
 * about and a snapshot of the values it covers (for risk criteria the
 * thresholds, so a later change is no longer covered by the approval).
 */
class DecisionProposalBuilder
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RiskApprovalConfigResolver $approvalConfigResolver,
        private readonly RiskApprovalConfigRepository $approvalConfigRepository,
        private readonly RiskAppetiteRepository $riskAppetiteRepository,
        private readonly ControlRepository $controlRepository,
        private readonly ISMSContextRepository $contextRepository,
        private readonly ISMSObjectiveRepository $objectiveRepository,
        private readonly ManagementReviewRepository $reviewRepository,
        private readonly SoaSnapshotRepository $soaSnapshotRepository,
        private readonly ?SupplierRepository $supplierRepository = null,
    ) {
    }

    public function build(ManagementDecisionType $type, Tenant $tenant, ?string $locale = null): DecisionProposal
    {
        return match ($type) {
            ManagementDecisionType::RiskCriteria => $this->riskCriteria($tenant, $locale),
            ManagementDecisionType::Soa => $this->soa($tenant, $locale),
            ManagementDecisionType::Policy => $this->policy($tenant, $locale),
            ManagementDecisionType::Objectives => $this->objectives($tenant, $locale),
            ManagementDecisionType::Resources => $this->resources($tenant, $locale),
            ManagementDecisionType::Nis2Measures => $this->nis2Measures($tenant, $locale),
            ManagementDecisionType::DoraIctFramework => $this->doraFramework($tenant, $locale),
            ManagementDecisionType::DoraStrategy => new DecisionProposal(
                $this->t('proposal.title.dora_strategy', [], $locale),
                $this->t('proposal.dora_strategy.body', [], $locale),
            ),
            ManagementDecisionType::DoraThirdParty => $this->doraThirdParty($tenant, $locale),
            default => new DecisionProposal($this->t('proposal.title.' . $type->value, [], $locale), ''),
        };
    }

    /**
     * The values an approval of the acceptance criteria covers. Compared with
     * the current values before risks are accepted automatically.
     *
     * @return array{automatic: int, manager: int, executive: int}
     */
    public function criteriaSnapshot(Tenant $tenant): array
    {
        $view = $this->approvalConfigResolver->resolveFor($tenant);

        return [
            'automatic' => $view->thresholdAutomatic,
            'manager' => $view->thresholdManager,
            'executive' => $view->thresholdExecutive,
        ];
    }

    private function riskCriteria(Tenant $tenant, ?string $locale): DecisionProposal
    {
        $snapshot = $this->criteriaSnapshot($tenant);
        $lines = [$this->t('proposal.risk_criteria.thresholds', [
            '%automatic%' => $snapshot['automatic'],
            '%manager%' => $snapshot['manager'],
            '%executive%' => $snapshot['executive'],
        ], $locale)];

        $appetites = $this->riskAppetiteRepository->findAllActiveForTenant($tenant);
        if ($appetites !== []) {
            $lines[] = '';
            $lines[] = $this->t('proposal.risk_criteria.appetite', [], $locale);
            foreach ($appetites as $appetite) {
                $lines[] = sprintf(
                    '- %s: %d',
                    $appetite->getCategory() ?? $this->t('proposal.risk_criteria.appetite_global', [], $locale),
                    (int) $appetite->getMaxAcceptableRisk(),
                );
            }
        }
        $lines[] = '';
        $lines[] = $this->t('proposal.risk_criteria.effect', [], $locale);

        $config = $this->approvalConfigRepository->findByTenant($tenant);

        return new DecisionProposal(
            $this->t('proposal.title.risk_criteria', [], $locale),
            implode("\n", $lines),
            $config instanceof RiskApprovalConfig ? 'RiskApprovalConfig' : null,
            $config?->getId(),
            $snapshot,
        );
    }

    private function soa(Tenant $tenant, ?string $locale): DecisionProposal
    {
        $controls = $this->controlRepository->findBy(['tenant' => $tenant]);
        $applicable = array_filter($controls, static fn (Control $c): bool => $c->isApplicable() !== false);
        $implemented = array_filter(
            $applicable,
            static fn (Control $c): bool => $c->getImplementationStatus() === 'implemented',
        );
        $snapshots = $this->soaSnapshotRepository->findByTenant($tenant);
        $latest = $snapshots[0] ?? null;

        $text = $this->t('proposal.soa.body', [
            '%total%' => count($controls),
            '%applicable%' => count($applicable),
            '%excluded%' => count($controls) - count($applicable),
            '%implemented%' => count($implemented),
        ], $locale);

        return new DecisionProposal(
            $this->t('proposal.title.soa', [], $locale),
            $text,
            $latest instanceof SoaSnapshot ? 'SoaSnapshot' : null,
            $latest?->getId(),
            ['applicable' => count($applicable), 'excluded' => count($controls) - count($applicable)],
        );
    }

    private function policy(Tenant $tenant, ?string $locale): DecisionProposal
    {
        $context = $this->contextRepository->getContextForTenant($tenant);
        $lines = [];
        if ($context instanceof ISMSContext) {
            if ($context->getIsmsScope() !== null && trim($context->getIsmsScope()) !== '') {
                $lines[] = $this->t('proposal.policy.scope', [], $locale);
                $lines[] = trim($context->getIsmsScope());
                $lines[] = '';
            }
            if ($context->getIsmsPolicy() !== null && trim($context->getIsmsPolicy()) !== '') {
                $lines[] = $this->t('proposal.policy.policy', [], $locale);
                $lines[] = trim($context->getIsmsPolicy());
            }
        }
        if ($lines === []) {
            $lines[] = $this->t('proposal.policy.empty', [], $locale);
        }

        return new DecisionProposal(
            $this->t('proposal.title.policy', [], $locale),
            implode("\n", $lines),
            $context instanceof ISMSContext ? 'ISMSContext' : null,
            $context?->getId(),
        );
    }

    private function objectives(Tenant $tenant, ?string $locale): DecisionProposal
    {
        $objectives = array_filter(
            $this->objectiveRepository->findBy(['tenant' => $tenant]),
            static fn (ISMSObjective $o): bool => !in_array($o->getStatus(), ['cancelled', 'achieved'], true),
        );
        $lines = [];
        foreach ($objectives as $objective) {
            $target = trim(sprintf('%s %s', (string) $objective->getTargetValue(), (string) $objective->getUnit()));
            $due = $objective->getTargetDate()?->format('d.m.Y');
            $lines[] = '- ' . $objective->getTitle()
                . ($target !== '' ? ' — ' . $this->t('proposal.objectives.target', ['%target%' => $target], $locale) : '')
                . ($due !== null ? ' — ' . $this->t('proposal.objectives.due', ['%date%' => $due], $locale) : '');
        }
        if ($lines === []) {
            $lines[] = $this->t('proposal.objectives.empty', [], $locale);
        }

        return new DecisionProposal(
            $this->t('proposal.title.objectives', [], $locale),
            implode("\n", $lines),
        );
    }

    private function resources(Tenant $tenant, ?string $locale): DecisionProposal
    {
        $latest = $this->reviewRepository->findLatestForTenant($tenant);
        $needs = $latest instanceof ManagementReview ? trim((string) $latest->getResourceNeeds()) : '';
        if (!$latest instanceof ManagementReview || $needs === '') {
            return new DecisionProposal(
                $this->t('proposal.title.resources', [], $locale),
                $this->t('proposal.resources.empty', [], $locale),
            );
        }

        return new DecisionProposal(
            $this->t('proposal.title.resources', [], $locale),
            $this->t('proposal.resources.from_review', ['%review%' => (string) $latest->getTitle()], $locale) . "\n" . $needs,
            'ManagementReview',
            $latest->getId(),
        );
    }

    private function nis2Measures(Tenant $tenant, ?string $locale): DecisionProposal
    {
        $controls = $this->controlRepository->findBy(['tenant' => $tenant]);
        $applicable = array_filter($controls, static fn (Control $c): bool => $c->isApplicable() !== false);
        $implemented = array_filter(
            $applicable,
            static fn (Control $c): bool => $c->getImplementationStatus() === 'implemented',
        );

        return new DecisionProposal(
            $this->t('proposal.title.nis2_measures', [], $locale),
            $this->t('proposal.nis2_measures.body', [
                '%implemented%' => count($implemented),
                '%applicable%' => count($applicable),
            ], $locale),
        );
    }

    private function doraFramework(Tenant $tenant, ?string $locale): DecisionProposal
    {
        $applicable = array_filter(
            $this->controlRepository->findBy(['tenant' => $tenant]),
            static fn (Control $c): bool => $c->isApplicable() !== false,
        );
        $implemented = array_filter($applicable, static fn (Control $c): bool => $c->getImplementationStatus() === 'implemented');

        return new DecisionProposal(
            $this->t('proposal.title.dora_ict_framework', [], $locale),
            $this->t('proposal.dora_ict_framework.body', [
                '%implemented%' => count($implemented),
                '%applicable%' => count($applicable),
            ], $locale),
        );
    }

    private function doraThirdParty(Tenant $tenant, ?string $locale): DecisionProposal
    {
        $suppliers = $this->supplierRepository?->findByTenantAndDoraRelevant($tenant) ?? [];

        return new DecisionProposal(
            $this->t('proposal.title.dora_third_party', [], $locale),
            $this->t('proposal.dora_third_party.body', [
                '%total%' => count($suppliers),
                '%critical%' => count(array_filter($suppliers, static fn (Supplier $s): bool => $s->getIctCriticality() === 'critical')),
                '%exit%' => count(array_filter($suppliers, static fn (Supplier $s): bool => $s->hasExitStrategy())),
            ], $locale),
        );
    }

    /** @param array<string, int|string> $params */
    private function t(string $key, array $params, ?string $locale): string
    {
        return $this->translator->trans('management_decision.' . $key, $params, 'management_decision', $locale);
    }
}
