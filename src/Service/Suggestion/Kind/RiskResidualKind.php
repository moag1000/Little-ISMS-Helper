<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\SuggestionOrigin;
use App\Repository\RiskRepository;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Residual risk (ISO 27001 6.1.3 / 8.3, ISO 27005 8.6) from the controls
 * linked to the risk: no implemented control → residual equals inherent;
 * implemented controls lower likelihood by one step, effective ones by two;
 * an implemented corrective or recovery control lowers impact by one step.
 * For risks in treatment or later, and for assessed risks that already have
 * implemented controls (existing controls, ISO 27005 8.4) — while treatment
 * is still under way the proposal says it is an interim value. The risk
 * owner confirms.
 */
final class RiskResidualKind extends AbstractSuggestionKind
{
    public const string KEY = 'risk.residual';

    private const array TREATED_STATES = [RiskStatus::InTreatment, RiskStatus::Treated, RiskStatus::Mitigated, RiskStatus::Monitored];

    /** States in which the residual risk is an interim value: treatment not finished. */
    private const array INTERIM_STATES = [RiskStatus::Assessed, RiskStatus::InTreatment];

    public function __construct(
        private readonly RiskRepository $risks,
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

    public function requiredModule(): string
    {
        return 'risks';
    }

    public function generate(Tenant $tenant): iterable
    {
        foreach ($this->risks->findByTenant($tenant) as $risk) {
            $assessed = $risk->getStatus() === RiskStatus::Assessed;
            if (!$assessed && !in_array($risk->getStatus(), self::TREATED_STATES, true)) {
                continue;
            }
            if ($assessed && (self::derive($risk)['implemented'] ?? []) === []) {
                continue;
            }
            $draft = $this->draftFor($risk, $tenant);
            if ($draft !== null) {
                yield $draft;
            }
        }
    }

    /** @return array{probability: int, impact: int, implemented: list<string>, effective: list<string>}|null */
    public static function derive(Risk $risk): ?array
    {
        $probability = (int) $risk->getProbability();
        $impact = (int) $risk->getImpact();
        if ($probability < 1 || $impact < 1) {
            return null;
        }
        $implemented = [];
        $effective = [];
        $lowersImpact = false;
        foreach ($risk->getControls() as $control) {
            if (!ControlState::isImplemented($control)) {
                continue;
            }
            $implemented[] = ControlState::label($control);
            if (ControlState::isEffective($control)) {
                $effective[] = ControlState::label($control);
            }
            if (in_array($control->getControlType(), ['corrective', 'recovery'], true)) {
                $lowersImpact = true;
            }
        }
        $steps = $effective !== [] ? 2 : ($implemented !== [] ? 1 : 0);

        return [
            'probability' => max(1, $probability - $steps),
            'impact' => max(1, $impact - ($lowersImpact ? 1 : 0)),
            'implemented' => $implemented,
            'effective' => $effective,
        ];
    }

    public function draftFor(Risk $risk, Tenant $tenant): ?SuggestionDraft
    {
        $derived = self::derive($risk);
        if ($derived === null) {
            return null;
        }
        $from = [(int) $risk->getResidualProbability(), (int) $risk->getResidualImpact()];
        $to = [$derived['probability'], $derived['impact']];
        if ($from === $to) {
            return null;
        }

        $basis = $derived['implemented'] === []
            ? $this->text->t($tenant, 'suggestions.text.risk_residual.no_controls')
            : $this->text->t($tenant, 'suggestions.text.risk_residual.controls', [
                '%implemented%' => implode(', ', $derived['implemented']),
                '%effective%' => $derived['effective'] === [] ? '—' : implode(', ', $derived['effective']),
            ]);

        return new SuggestionDraft(
            subject: $risk,
            title: $this->text->t($tenant, 'suggestions.text.risk_residual.title', ['%risk%' => (string) $risk->getTitle()]),
            rationale: $this->text->t($tenant, 'suggestions.text.risk_residual.rationale', [
                '%from%' => sprintf('%d × %d', $from[0], $from[1]),
                '%to%' => sprintf('%d × %d', $to[0], $to[1]),
                '%inherent%' => sprintf('%d × %d', (int) $risk->getProbability(), (int) $risk->getImpact()),
                '%basis%' => $basis,
            ]) . (in_array($risk->getStatus(), self::INTERIM_STATES, true)
                ? ' ' . $this->text->t($tenant, 'suggestions.text.risk_residual.interim')
                : ''),
            payload: ['probability' => $to[0], 'impact' => $to[1], 'from' => $from],
            dedupKey: sprintf('risk:%d:%d:%d:%d:%d', (int) $risk->getId(), $from[0], $from[1], $to[0], $to[1]),
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $risk = $this->entityManager->find(Risk::class, (int) $suggestion->getSubjectId());
        if (!$risk instanceof Risk || $risk->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('risk_gone');
        }
        $payload = $suggestion->getPayload();
        if ([(int) $risk->getResidualProbability(), (int) $risk->getResidualImpact()] !== array_map('intval', (array) ($payload['from'] ?? []))) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $risk->setResidualProbability((int) $payload['probability']);
        $risk->setResidualImpact((int) $payload['impact']);

        return $risk;
    }
}
