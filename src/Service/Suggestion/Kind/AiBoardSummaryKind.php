<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\ManagementReview;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ManagementReviewRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ISB preparing the board meeting: a management review with its inputs
 * (ISO 27001 9.3.2 a–g) filled in but no summary → the model writes the
 * one-page summary for top management (state, trend, what has to be
 * decided). Accepting stores it as the review's summary; the decisions
 * themselves stay with top management (9.3.3).
 */
final class AiBoardSummaryKind extends AbstractAiKind
{
    public const string KEY = 'ai.board_summary';

    /** Minimum filled inputs before a summary is worth drafting. */
    private const int MIN_INPUTS = 3;

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly ManagementReviewRepository $reviews,
        private readonly EntityManagerInterface $entityManager,
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
        return ['ROLE_ISB', 'ROLE_CISO'];
    }

    public function requiredModule(): string
    {
        return 'reviews';
    }

    public function subjectClasses(): array
    {
        return [ManagementReview::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->reviews->findBy(['tenant' => $tenant], ['reviewDate' => 'DESC'], 10) as $review) {
            if ($this->isCandidate($review)) {
                yield $review;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        return $record instanceof ManagementReview
            && trim((string) $record->getSummary()) === ''
            && count(self::inputs($record)) >= self::MIN_INPUTS;
    }

    protected function instructions(): string
    {
        return 'Write the summary of this ISMS management review for top management (ISO/IEC 27001 9.3): '
            . 'overall state of information security, the main changes and trends, and the points top management '
            . 'has to decide or provide resources for. 8-14 sentences, plain business language, no jargon, '
            . 'no new facts. Return key "summary".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof ManagementReview);
        $lines = ['Review: ' . $record->getTitle() . ' (' . $record->getReviewDate()?->format('Y-m-d') . ')'];
        foreach (self::inputs($record) as $label => $value) {
            $lines[] = $label . ': ' . mb_substr($value, 0, 1500);
        }

        return implode("\n", $lines);
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof ManagementReview);
        $summary = self::text($data, 'summary', 8000);
        if ($summary === '') {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_board.title', ['%review%' => (string) $record->getTitle()]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_board.rationale', [
                '%inputs%' => count(self::inputs($record)),
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            editableText: $summary,
            confidence: self::confidence($data),
            dedupKey: 'review:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $review = $this->entityManager->find(ManagementReview::class, (int) $suggestion->getSubjectId());
        if (!$review instanceof ManagementReview || $review->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('review_gone');
        }
        if (trim((string) $review->getSummary()) !== '') {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $review->setSummary((string) ($editedText ?? $suggestion->getEditableText()));

        return $review;
    }

    /** @return array<string, string> filled 9.3.2 inputs, English label => text */
    private static function inputs(ManagementReview $review): array
    {
        return array_filter([
            'Status of actions from previous reviews (a)' => (string) $review->getPreviousReviewActions(),
            'Changes in external and internal issues (b)' => (string) $review->getChangesRelevantToISMS(),
            'Changes in needs of interested parties (c)' => (string) $review->getInterestedPartyNeedsChanges(),
            'Nonconformities and corrective actions (d1)' => trim($review->getNonConformitiesStatus() . ' ' . $review->getCorrectiveActionsStatus()),
            'Monitoring and measurement results (d2)' => (string) $review->getPerformanceEvaluation(),
            'Audit results (d3)' => (string) $review->getAuditResults(),
            'Fulfilment of objectives (d4)' => (string) $review->getObjectivesReview(),
            'Feedback from interested parties (e)' => (string) $review->getFeedbackFromInterestedParties(),
            'Risk assessment and treatment (f)' => trim($review->getRisksReview() . ' ' . $review->getRiskTreatmentEffectiveness()),
            'Incidents' => (string) $review->getIncidentsReview(),
            'Opportunities for improvement (g)' => (string) $review->getOpportunitiesForImprovement(),
            'Resource needs' => (string) $review->getResourceNeeds(),
        ], static fn (string $v): bool => trim($v) !== '');
    }
}
