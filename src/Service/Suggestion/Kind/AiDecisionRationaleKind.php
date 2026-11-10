<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\ManagementDecision;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ManagementDecisionStatus;
use App\Repository\ManagementDecisionRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use App\Controller\ManagementDecisionController;
use App\Service\Suggestion\TargetAccess;

/**
 * Whoever puts a proposal before top management (ISO 27001 5.1, 9.3.3;
 * NIS-2 Art. 20 (1)): a pending decision without proposal text → the model
 * drafts the justification (what is put forward, why, what happens if
 * approved or not) from the decision's type, title and the values it covers.
 * Accepting fills the proposal text only, while it is still empty. Approving
 * or rejecting stays with ROLE_TOP_MGMT in the decision workflow.
 */
final class AiDecisionRationaleKind extends AbstractAiKind
{
    public const string KEY = 'ai.decision_rationale';

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly ManagementDecisionRepository $decisions,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionText $text,
    ) {
        parent::__construct($ai, $suggestions);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function targetAccess(): TargetAccess
    {
        return TargetAccess::action(ManagementDecisionController::class, 'new');
    }

    public function decisionRoles(): array
    {
        return ['ROLE_ISB', 'ROLE_CISO'];
    }

    public function requiredModule(): string
    {
        return 'core';
    }

    public function subjectClasses(): array
    {
        return [ManagementDecision::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->decisions->findPending($tenant) as $decision) {
            if ($this->isCandidate($decision)) {
                yield $decision;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        return $record instanceof ManagementDecision
            && $record->getStatus() === ManagementDecisionStatus::Pending->value
            && trim((string) $record->getProposal()) === ''
            && trim((string) $record->getTitle()) !== '';
    }

    protected function instructions(): string
    {
        return 'Write the justification for this proposal to top management: what is put before them, why it is needed '
            . '(norm requirement and the organisation\'s situation, from the given facts only), what changes if it is '
            . 'approved and what the risk is if it is not. 5-8 sentences in plain business language, neutral, '
            . 'no recommendation wording like "we urge". Return key "proposal".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof ManagementDecision);
        $snapshot = $record->getSubjectSnapshot();

        return implode("\n", array_filter([
            'Decision type: ' . $record->getType()->value . ' (' . $record->getNormReference() . ')',
            'Title: ' . $record->getTitle(),
            $record->getManagementReview() ? 'Management review: ' . $record->getManagementReview()->getTitle() : null,
            $record->getDueDate() ? 'Decision due: ' . $record->getDueDate()->format('Y-m-d') : null,
            $record->getSubjectType() ? 'About: ' . $record->getSubjectType() . ' #' . $record->getSubjectId() : null,
            $snapshot !== null && $snapshot !== [] ? 'Values covered: ' . mb_substr((string) json_encode($snapshot, JSON_UNESCAPED_UNICODE), 0, 2000) : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof ManagementDecision);
        $proposal = self::text($data, 'proposal', 6000);
        if ($proposal === '') {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_decision.title', ['%decision%' => (string) $record->getTitle()]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_decision.rationale', [
                '%norm%' => $record->getNormReference(),
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            editableText: $proposal,
            confidence: self::confidence($data),
            dedupKey: 'decision:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $decision = $this->entityManager->find(ManagementDecision::class, (int) $suggestion->getSubjectId());
        if (!$decision instanceof ManagementDecision || $decision->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('decision_gone');
        }
        if ($decision->getStatus() !== ManagementDecisionStatus::Pending->value || trim((string) $decision->getProposal()) !== '') {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $decision->setProposal((string) ($editedText ?? $suggestion->getEditableText()));

        return $decision;
    }
}
