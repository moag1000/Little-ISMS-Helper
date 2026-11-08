<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\TreatmentStrategy;
use App\Repository\RiskRepository;
use App\Repository\SuggestionRepository;
use App\Risk\RiskMatrixThresholds;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionNote;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whoever has to approve a risk acceptance (ISO 27005 8.4.4, ISO 27001
 * 6.1.3 f): "what does this mean for me?" → the model explains in plain
 * language what accepting the residual risk means: what can happen, how
 * likely, what it costs, until when the acceptance holds. Accepting the
 * draft adds the text as a comment on the risk. It never accepts the risk:
 * approving or rejecting stays in the acceptance workflow.
 *
 * The risk owner decides too (the queue lets the person responsible for the
 * record decide); top management reads only and uses the approval screen.
 */
final class AiRiskAcceptanceExplanationKind extends AbstractAiKind
{
    public const string KEY = 'ai.risk_acceptance_explained';

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly RiskRepository $risks,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionNote $notes,
        private readonly SuggestionText $text,
    ) {
        parent::__construct($ai, $suggestions);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function decisionRoles(): array
    {
        return ['ROLE_RISK_MANAGER', 'ROLE_CISO'];
    }

    public function requiredModule(): string
    {
        return 'risks';
    }

    public function subjectClasses(): array
    {
        return [Risk::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->risks->findBy(['tenant' => $tenant, 'formallyAccepted' => false], ['createdAt' => 'DESC'], 100) as $risk) {
            if ($this->isCandidate($risk)) {
                yield $risk;
            }
        }
    }

    /** Acceptance requested (justification given) and not yet decided. */
    protected function isCandidate(object $record): bool
    {
        return $record instanceof Risk
            && $record->getTreatmentStrategy() === TreatmentStrategy::Accept
            && !$record->isFormallyAccepted()
            && trim((string) $record->getAcceptanceJustification()) !== '';
    }

    protected function instructions(): string
    {
        return 'Explain to a non-specialist who has to approve this risk acceptance what it means for them and the '
            . 'organisation: what can happen, how likely and how severe it is after the existing controls, what stays '
            . 'uncovered, what the approver is answerable for, and what to check before approving (e.g. is the review '
            . 'date sensible, is the justification enough). 5-9 plain sentences, no jargon, no recommendation to '
            . 'approve or reject. Return key "explanation".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof Risk);
        $controls = array_map(
            static fn ($c): string => (string) $c->getName() . ' (' . (string) $c->getImplementationStatus() . ')',
            array_slice($record->getControls()->toArray(), 0, 10),
        );

        return implode("\n", array_filter([
            'Risk: ' . $record->getTitle(),
            $record->getDescription() ? 'Description: ' . $record->getDescription() : null,
            $record->getThreat() ? 'Threat: ' . $record->getThreat() : null,
            $record->getVulnerability() ? 'Vulnerability: ' . $record->getVulnerability() : null,
            $record->getAsset() ? 'Asset: ' . $record->getAsset()->getName() : null,
            sprintf(
                'Before treatment: likelihood %d, impact %d, level %s',
                (int) $record->getProbability(),
                (int) $record->getImpact(),
                RiskMatrixThresholds::classify($record->getInherentRiskLevel()),
            ),
            sprintf(
                'Residual: likelihood %d, impact %d, level %s',
                (int) $record->getResidualProbability(),
                (int) $record->getResidualImpact(),
                RiskMatrixThresholds::classify($record->getResidualRiskLevel()),
            ),
            $controls !== [] ? 'Controls: ' . implode('; ', $controls) : 'Controls: none linked',
            $record->getTreatmentDescription() ? 'Treatment: ' . $record->getTreatmentDescription() : null,
            'Justification for acceptance: ' . $record->getAcceptanceJustification(),
            $record->getAcceptanceExpiryDate() ? 'Acceptance valid until: ' . $record->getAcceptanceExpiryDate()->format('Y-m-d') : 'Acceptance valid until: not set',
            $record->getReviewDate() ? 'Next review: ' . $record->getReviewDate()->format('Y-m-d') : null,
            $record->getRiskOwner() ? 'Risk owner: ' . $record->getRiskOwner()->getFullName() : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof Risk);
        $explanation = self::text($data, 'explanation', 4000);
        if ($explanation === '') {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_acceptance.title', ['%risk%' => (string) $record->getTitle()]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_acceptance.rationale', [
                '%level%' => RiskMatrixThresholds::classify($record->getResidualRiskLevel()),
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            editableText: $explanation,
            confidence: self::confidence($data),
            dedupKey: 'risk:' . (int) $record->getId() . ':' . sha1((string) $record->getAcceptanceJustification()),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $risk = $this->entityManager->find(Risk::class, (int) $suggestion->getSubjectId());
        $tenant = $suggestion->getTenant();
        if (!$risk instanceof Risk || $risk->getTenant() !== $tenant) {
            throw new StaleSuggestionException('risk_gone');
        }
        if (!$this->isCandidate($risk)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $body = trim((string) ($editedText ?? $suggestion->getEditableText()));
        if ($body === '') {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $this->notes->add($tenant, 'Risk', (int) $risk->getId(), $by, $this->text->t($tenant, 'suggestions.text.ai_acceptance.heading') . "\n" . $body);

        return $risk;
    }
}
