<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Supplier;
use App\Entity\SupplierQuestionnaire;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionStatus;
use App\Repository\SuggestionRepository;
use App\Repository\SupplierQuestionnaireRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Supplier owner: the supplier answered the security questionnaire → the
 * model reads question and answer side by side and writes the evaluation
 * (ISO 27001 A.5.19–5.22, NIS-2 Art. 21 (2) d, DORA Art. 28): what is
 * covered, what is missing or vague, what to ask again. Accepting adds it to
 * the supplier's assessment findings; the score stays with the person.
 * The suggestion is about the supplier, so it shows on the supplier page.
 */
final class AiQuestionnaireEvaluationKind extends AbstractAiKind
{
    public const string KEY = 'ai.questionnaire_evaluation';

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly SupplierQuestionnaireRepository $questionnaires,
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
        return ['ROLE_ISB', 'ROLE_MANAGER'];
    }

    public function requiredModule(): string
    {
        return 'suppliers';
    }

    public function subjectClasses(): array
    {
        return [Supplier::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->questionnaires->findByTenant($tenant) as $questionnaire) {
            if ($this->isCandidate($questionnaire)) {
                yield $questionnaire;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        return $record instanceof SupplierQuestionnaire
            && $record->getStatus() === SupplierQuestionnaire::STATUS_COMPLETED
            && $record->getSupplier() instanceof Supplier
            && $record->getAnswers() !== [];
    }

    /** On the supplier page: the newest answered questionnaire not evaluated yet. */
    protected function recordFor(object $record): ?object
    {
        if (!$record instanceof Supplier || !$record->getTenant() instanceof Tenant) {
            return null;
        }
        $latest = null;
        foreach ($this->questionnaires->findByTenant($record->getTenant()) as $questionnaire) {
            if ($questionnaire->getSupplier() === $record && $this->isCandidate($questionnaire)
                && !$this->isEvaluated($record->getTenant(), $questionnaire)
                && ($latest === null || $questionnaire->getCompletedAt() > $latest->getCompletedAt())) {
                $latest = $questionnaire;
            }
        }

        return $latest;
    }

    protected function alreadyAsked(Tenant $tenant, object $record, array $askedSubjectIds): bool
    {
        \assert($record instanceof SupplierQuestionnaire);

        return $this->suggestions->findOneByDedup($tenant, self::KEY, sha1(self::KEY . '|' . self::dedup($record))) !== null;
    }

    protected function instructions(): string
    {
        return 'Evaluate the supplier\'s answers to this information security questionnaire (ISO/IEC 27001 A.5.19-5.22, '
            . 'NIS-2 Art. 21 (2) d): which requirements the answers cover, which answers are missing, vague or point to '
            . 'a gap, and what to ask the supplier again. 5-10 sentences or short lines, factual. '
            . 'Return keys "evaluation" and "gaps" (number of answers that point to a gap or are missing).';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof SupplierQuestionnaire);
        $supplier = $record->getSupplier();
        $answers = $record->getAnswers();
        $lines = [
            'Supplier: ' . $supplier?->getName() . ($supplier?->getServiceProvided() ? ' — service: ' . $supplier->getServiceProvided() : ''),
            $supplier?->getCriticality() ? 'Criticality: ' . $supplier->getCriticality() : '',
            'Questionnaire: ' . $record->getTitle(),
        ];
        foreach ($record->getQuestions() as $question) {
            $answer = trim((string) ($answers[$question['id']] ?? ''));
            $lines[] = 'Q: ' . $question['text'] . "\nA: " . ($answer !== '' ? mb_substr($answer, 0, 1200) : '(no answer)');
        }

        return implode("\n", array_filter($lines));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof SupplierQuestionnaire);
        $evaluation = self::text($data, 'evaluation', 8000);
        $supplier = $record->getSupplier();
        if ($evaluation === '' || !$supplier instanceof Supplier) {
            return null;
        }

        return new SuggestionDraft(
            subject: $supplier,
            title: $this->text->t($tenant, 'suggestions.text.ai_questionnaire.title', [
                '%questionnaire%' => $record->getTitle(),
                '%supplier%' => (string) $supplier->getName(),
            ]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_questionnaire.rationale', [
                '%answers%' => count($record->getAnswers()),
                '%questions%' => count($record->getQuestions()),
                '%gaps%' => is_numeric($data['gaps'] ?? null) ? (int) $data['gaps'] : '—',
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: [
                'questionnaire' => (int) $record->getId(),
                'heading' => $this->text->t($tenant, 'suggestions.text.ai_questionnaire.heading', [
                    '%questionnaire%' => $record->getTitle(),
                    '%date%' => $record->getCompletedAt()?->format('d.m.Y') ?? '—',
                ]),
            ],
            editableText: $evaluation,
            confidence: self::confidence($data),
            dedupKey: self::dedup($record),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $supplier = $this->entityManager->find(Supplier::class, (int) $suggestion->getSubjectId());
        if (!$supplier instanceof Supplier || $supplier->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('supplier_gone');
        }
        $p = $suggestion->getPayload();
        $block = trim((string) ($p['heading'] ?? '')) . "\n" . trim((string) ($editedText ?? $suggestion->getEditableText()));
        $existing = trim((string) $supplier->getAssessmentFindings());
        $supplier->setAssessmentFindings($existing !== '' ? $existing . "\n\n" . $block : $block);

        return $supplier;
    }

    private function isEvaluated(Tenant $tenant, SupplierQuestionnaire $questionnaire): bool
    {
        $accepted = $this->suggestions->findBy([
            'tenant' => $tenant,
            'kind' => self::KEY,
            'subjectClass' => Supplier::class,
            'subjectId' => $questionnaire->getSupplier()?->getId(),
            'status' => SuggestionStatus::Accepted,
        ]);
        foreach ($accepted as $suggestion) {
            if ((int) ($suggestion->getPayload()['questionnaire'] ?? 0) === $questionnaire->getId()) {
                return true;
            }
        }

        return false;
    }

    private static function dedup(SupplierQuestionnaire $questionnaire): string
    {
        return 'questionnaire:' . (int) $questionnaire->getId();
    }
}
