<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\RiskTreatmentPlan;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\RiskTreatmentPlanRepository;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Progress of a risk treatment plan (ISO 27001 6.1.3 e, 8.3) = share of its
 * controls that are implemented. Nothing set the field before; the plan
 * owner confirms the number.
 */
final class TreatmentPlanProgressKind extends AbstractSuggestionKind
{
    public const string KEY = 'treatment_plan.progress';

    public function __construct(
        private readonly RiskTreatmentPlanRepository $plans,
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
        return ['ROLE_RISK_MANAGER', 'ROLE_MANAGER'];
    }

    public function requiredModule(): ?string
    {
        return 'risk_treatment_plans';
    }

    public function generate(Tenant $tenant): iterable
    {
        foreach ($this->plans->findBy(['tenant' => $tenant]) as $plan) {
            if (in_array($plan->getStatus(), ['completed', 'cancelled'], true)) {
                continue;
            }
            $total = $plan->getControls()->count();
            if ($total === 0) {
                continue;
            }
            $done = 0;
            foreach ($plan->getControls() as $control) {
                $done += ControlState::isImplemented($control) ? 1 : 0;
            }
            $percent = (int) round(100 * $done / $total);
            $current = $plan->getCompletionPercentage();
            if ($percent === $current) {
                continue;
            }
            yield new SuggestionDraft(
                subject: $plan,
                title: $this->text->t($tenant, 'suggestions.text.treatment_plan_progress.title', ['%plan%' => (string) $plan->getTitle()]),
                rationale: $this->text->t($tenant, 'suggestions.text.treatment_plan_progress.rationale', [
                    '%done%' => $done,
                    '%total%' => $total,
                    '%from%' => $current,
                    '%to%' => $percent,
                ]),
                payload: ['percent' => $percent, 'from' => $current],
                dedupKey: sprintf('plan:%d:%d:%d', (int) $plan->getId(), $current, $percent),
            );
        }
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $plan = $this->entityManager->find(RiskTreatmentPlan::class, (int) $suggestion->getSubjectId());
        if (!$plan instanceof RiskTreatmentPlan || $plan->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('plan_gone');
        }
        $payload = $suggestion->getPayload();
        if ($plan->getCompletionPercentage() !== (int) ($payload['from'] ?? -1)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $plan->setCompletionPercentage((int) $payload['percent']);

        return $plan;
    }
}
