<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\BCExercise;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\BCExerciseStatus;
use App\Repository\BCExerciseRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\SectionedText;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * BCM officer (ISO 22301 8.5): an exercise that took place but has no
 * evaluation → the model drafts results, what went well and lessons learned
 * from the scenario, objectives and the facilitator's notes. Accepting fills
 * only the fields that are still empty; success rating and report sign-off
 * stay with the person.
 */
final class AiBcExerciseEvaluationKind extends AbstractAiKind
{
    public const string KEY = 'ai.bc_exercise_evaluation';

    /** Field => translation key of its block heading. */
    private const array FIELDS = [
        'results' => 'suggestions.text.ai_bc_exercise.results',
        'whatWentWell' => 'suggestions.text.ai_bc_exercise.well',
        'lessonsLearned' => 'suggestions.text.ai_bc_exercise.lessons',
    ];

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly BCExerciseRepository $exercises,
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
        return ['ROLE_BCM_OFFICER'];
    }

    public function requiredModule(): string
    {
        return 'bcm';
    }

    public function subjectClasses(): array
    {
        return [BCExercise::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->exercises->findBy(['tenant' => $tenant], ['exerciseDate' => 'DESC'], 30) as $exercise) {
            if ($this->isCandidate($exercise)) {
                yield $exercise;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        return $record instanceof BCExercise
            && in_array($record->getStatus(), [BCExerciseStatus::Completed->value, BCExerciseStatus::InProgress->value], true)
            && $record->getExerciseDate() !== null
            && $record->getExerciseDate() <= new \DateTimeImmutable('today')
            && self::emptyFields($record) !== []
            && mb_strlen(trim($record->getScenario() . ' ' . $record->getObjectives() . ' ' . $record->getDescription())) >= 20;
    }

    protected function instructions(): string
    {
        return 'Draft the evaluation of this business continuity exercise (ISO/IEC 22301 8.5): '
            . '"results" = what happened against the objectives and the scenario (3-6 sentences), '
            . '"went_well" = what worked (2-4 sentences), '
            . '"lessons" = what to change in plans, roles or tests, each as a short checkable point (3-6 lines). '
            . 'Use only the given facts; write "not recorded" where the exercise notes do not say. '
            . 'Return keys "results", "went_well", "lessons".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof BCExercise);
        $plans = array_map(
            static fn ($p): string => (string) $p->getName(),
            array_slice($record->getTestedPlans()->toArray(), 0, 10),
        );

        return implode("\n", array_filter([
            'Exercise: ' . $record->getName() . ' (' . $record->getExerciseType() . ', ' . $record->getExerciseDate()?->format('Y-m-d') . ')',
            $plans !== [] ? 'Plans tested: ' . implode(', ', $plans) : null,
            $record->getDescription() ? 'Description: ' . $record->getDescription() : null,
            $record->getScope() ? 'Scope: ' . $record->getScope() : null,
            $record->getObjectives() ? 'Objectives: ' . $record->getObjectives() : null,
            $record->getScenario() ? 'Scenario: ' . $record->getScenario() : null,
            $record->getDurationHours() !== null ? 'Duration: ' . $record->getDurationHours() . ' hours' : null,
            $record->getActualRtoAchieved() ? 'RTO achieved: ' . $record->getActualRtoAchieved() : null,
            $record->getActualRpoAchieved() ? 'RPO achieved: ' . $record->getActualRpoAchieved() : null,
            $record->getFindings() ? 'Findings: ' . $record->getFindings() : null,
            $record->getAreasForImprovement() ? 'Areas for improvement: ' . $record->getAreasForImprovement() : null,
            $record->getActionItems() ? 'Action items: ' . $record->getActionItems() : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof BCExercise);
        $answers = ['results' => 'results', 'whatWentWell' => 'went_well', 'lessonsLearned' => 'lessons'];
        $sections = [];
        $drafted = [];
        foreach (self::emptyFields($record) as $field) {
            $value = self::text($data, $answers[$field]);
            if ($value !== '') {
                $sections[$this->text->t($tenant, self::FIELDS[$field])] = $value;
                $drafted[$field] = $value;
            }
        }
        if ($drafted === []) {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_bc_exercise.title', ['%exercise%' => (string) $record->getName()]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_bc_exercise.rationale', [
                '%date%' => $record->getExerciseDate()?->format('d.m.Y') ?? '—',
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: ['fields' => $drafted, 'labels' => array_map(fn (string $k): string => $this->text->t($tenant, $k), self::FIELDS)],
            editableText: SectionedText::compose($sections),
            confidence: self::confidence($data),
            dedupKey: 'exercise:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $exercise = $this->entityManager->find(BCExercise::class, (int) $suggestion->getSubjectId());
        if (!$exercise instanceof BCExercise || $exercise->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('exercise_gone');
        }
        $p = $suggestion->getPayload();
        $values = (array) ($p['fields'] ?? []);
        if ($editedText !== null) {
            $values = array_merge($values, SectionedText::parse($editedText, (array) ($p['labels'] ?? [])));
        }
        $written = 0;
        foreach (self::emptyFields($exercise) as $field) {
            $value = trim((string) ($values[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            match ($field) {
                'results' => $exercise->setResults($value),
                'whatWentWell' => $exercise->setWhatWentWell($value),
                default => $exercise->setLessonsLearned($value),
            };
            $written++;
        }
        if ($written === 0) {
            throw new StaleSuggestionException('changed_meanwhile');
        }

        return $exercise;
    }

    /** @return list<string> the evaluation fields still empty */
    private static function emptyFields(BCExercise $exercise): array
    {
        return array_values(array_filter([
            trim((string) $exercise->getResults()) === '' ? 'results' : null,
            trim((string) $exercise->getWhatWentWell()) === '' ? 'whatWentWell' : null,
            trim((string) $exercise->getLessonsLearned()) === '' ? 'lessonsLearned' : null,
        ]));
    }
}
