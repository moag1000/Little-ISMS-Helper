<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\SuggestionOrigin;
use App\Lifecycle\LifecycleService;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "In treatment" → "treated" (ISO 27001 8.3) when every control of the
 * risk's treatment plans (or, without a plan, every control linked to the
 * risk) is implemented. Accepting runs the lifecycle transition, so roles,
 * audit log and four-eyes rules of risk_lifecycle apply unchanged.
 */
final class RiskTreatmentCompleteKind extends AbstractSuggestionKind
{
    public const string KEY = 'risk.treatment_complete';

    public function __construct(
        private readonly RiskRepository $risks,
        private readonly RiskTreatmentPlanRepository $plans,
        private readonly LifecycleService $lifecycle,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionText $text,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function origin(): SuggestionOrigin
    {
        return SuggestionOrigin::Rule;
    }

    public function decisionRoles(): array
    {
        return ['ROLE_RISK_MANAGER'];
    }

    public function requiredModule(): ?string
    {
        return 'risks';
    }

    public function generate(Tenant $tenant): iterable
    {
        $plansByRisk = [];
        foreach ($this->plans->findBy(['tenant' => $tenant]) as $plan) {
            if ($plan->getStatus() !== 'cancelled' && $plan->getRisk() !== null) {
                $plansByRisk[(int) $plan->getRisk()->getId()][] = $plan;
            }
        }
        foreach ($this->risks->findByTenant($tenant) as $risk) {
            if ($risk->getStatus() !== RiskStatus::InTreatment) {
                continue;
            }
            $controls = self::controlsOf($risk, $plansByRisk[(int) $risk->getId()] ?? []);
            if ($controls === [] || array_filter($controls, static fn (Control $c): bool => !ControlState::isImplemented($c)) !== []) {
                continue;
            }
            yield new SuggestionDraft(
                subject: $risk,
                title: $this->text->t($tenant, 'suggestions.text.risk_treatment_complete.title', ['%risk%' => (string) $risk->getTitle()]),
                rationale: $this->text->t($tenant, 'suggestions.text.risk_treatment_complete.rationale', [
                    '%controls%' => implode(', ', array_map(ControlState::label(...), $controls)),
                ]),
                payload: ['transition' => 'complete_treatment'],
                dedupKey: 'risk:' . (int) $risk->getId(),
            );
        }
    }

    /**
     * @param list<RiskTreatmentPlan> $plans
     * @return list<Control>
     */
    private static function controlsOf(Risk $risk, array $plans): array
    {
        $controls = [];
        foreach ($plans as $plan) {
            foreach ($plan->getControls() as $control) {
                $controls[(int) $control->getId()] = $control;
            }
        }
        if ($controls === []) {
            foreach ($risk->getControls() as $control) {
                $controls[(int) $control->getId()] = $control;
            }
        }

        return array_values($controls);
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $risk = $this->entityManager->find(Risk::class, (int) $suggestion->getSubjectId());
        if (!$risk instanceof Risk || $risk->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('risk_gone');
        }
        if ($risk->getStatus() !== RiskStatus::InTreatment) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $this->lifecycle->transition($risk, 'risk_lifecycle', 'complete_treatment', $by, $suggestion->getRationale());

        return $risk;
    }
}
