<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\DataBreach;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataBreachRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\SectionedText;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DPO within the 72 hours (GDPR Art. 33): a breach that must be notified and
 * is not yet → the model drafts the empty notification parts of Art. 33 (3):
 * (a) nature of the breach, (c) likely consequences, (d) measures taken or
 * proposed. The person edits the three blocks in one text; accepting fills
 * only the fields that are still empty.
 */
final class AiBreachNotificationKind extends AbstractAiKind
{
    public const string KEY = 'ai.breach_notification';

    /** Field => translation key of its block heading. */
    private const array FIELDS = [
        'breachNature' => 'suggestions.text.ai_breach.nature',
        'likelyConsequences' => 'suggestions.text.ai_breach.consequences',
        'measuresTaken' => 'suggestions.text.ai_breach.measures',
    ];

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly DataBreachRepository $breaches,
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
        return ['ROLE_DPO'];
    }

    public function requiredModule(): string
    {
        return 'privacy';
    }

    public function subjectClasses(): array
    {
        return [DataBreach::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->breaches->findBy(['tenant' => $tenant], ['detectedAt' => 'DESC'], 50) as $breach) {
            if ($this->isCandidate($breach)) {
                yield $breach;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        return $record instanceof DataBreach
            && $record->getRequiresAuthorityNotification()
            && $record->getSupervisoryAuthorityNotifiedAt() === null
            && self::emptyFields($record) !== []
            && mb_strlen(trim($record->getTitle() . ' ' . $record->getIncident()?->getDescription())) >= 20;
    }

    protected function instructions(): string
    {
        return 'Draft the parts of a personal data breach notification to the supervisory authority (GDPR Art. 33 (3)): '
            . '"nature" = nature of the breach incl. categories and approximate number of data subjects and records (a), '
            . '"consequences" = likely consequences for the data subjects (c), '
            . '"measures" = measures taken or proposed, incl. mitigation of adverse effects (d). '
            . '2-5 factual sentences each; write "not yet known" where a fact is missing. Return keys "nature", "consequences", "measures".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof DataBreach);
        $incident = $record->getIncident();

        return implode("\n", array_filter([
            'Breach: ' . $record->getTitle(),
            'Detected: ' . ($record->getEffectiveDetectedAt()?->format(DATE_ATOM) ?? 'unknown'),
            $record->getSeverity() ? 'Severity: ' . $record->getSeverity() : null,
            $record->getAffectedDataSubjects() !== null ? 'Affected data subjects: ' . $record->getAffectedDataSubjects() : null,
            $record->getDataCategories() !== [] ? 'Data categories: ' . implode(', ', array_map('strval', $record->getDataCategories())) : null,
            $record->getDataSubjectCategories() !== [] ? 'Data subject categories: ' . implode(', ', array_map('strval', $record->getDataSubjectCategories())) : null,
            $record->getSpecialCategoriesAffected() ? 'Special categories (Art. 9) affected: yes' : null,
            $record->getProcessingActivity() ? 'Processing activity: ' . $record->getProcessingActivity()->getName() : null,
            $record->getBreachNature() ? 'Nature (already written): ' . $record->getBreachNature() : null,
            $record->getLikelyConsequences() ? 'Consequences (already written): ' . $record->getLikelyConsequences() : null,
            $record->getMeasuresTaken() ? 'Measures (already written): ' . $record->getMeasuresTaken() : null,
            $incident?->getDescription() ? 'Incident description: ' . $incident->getDescription() : null,
            $incident?->getImmediateActions() ? 'Immediate actions: ' . $incident->getImmediateActions() : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof DataBreach);
        $answers = ['breachNature' => 'nature', 'likelyConsequences' => 'consequences', 'measuresTaken' => 'measures'];
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
            title: $this->text->t($tenant, 'suggestions.text.ai_breach.title', ['%breach%' => (string) $record->getDisplayName()]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_breach.rationale', [
                '%deadline%' => $record->getAuthorityNotificationDeadline()?->format('d.m.Y H:i') ?? '—',
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: ['fields' => $drafted, 'labels' => array_map(fn (string $k): string => $this->text->t($tenant, $k), self::FIELDS)],
            editableText: SectionedText::compose($sections),
            confidence: self::confidence($data),
            dedupKey: 'breach:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $breach = $this->entityManager->find(DataBreach::class, (int) $suggestion->getSubjectId());
        if (!$breach instanceof DataBreach || $breach->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('breach_gone');
        }
        $p = $suggestion->getPayload();
        $values = (array) ($p['fields'] ?? []);
        if ($editedText !== null) {
            $values = array_merge($values, SectionedText::parse($editedText, (array) ($p['labels'] ?? [])));
        }
        $written = 0;
        foreach (self::emptyFields($breach) as $field) {
            $value = trim((string) ($values[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            match ($field) {
                'breachNature' => $breach->setBreachNature($value),
                'likelyConsequences' => $breach->setLikelyConsequences($value),
                'measuresTaken' => $breach->setMeasuresTaken($value),
            };
            $written++;
        }
        if ($written === 0) {
            throw new StaleSuggestionException('changed_meanwhile');
        }

        return $breach;
    }

    /** @return list<string> the Art. 33 (3) a, c, d fields still empty */
    private static function emptyFields(DataBreach $breach): array
    {
        return array_values(array_filter([
            trim((string) $breach->getBreachNature()) === '' ? 'breachNature' : null,
            trim((string) $breach->getLikelyConsequences()) === '' ? 'likelyConsequences' : null,
            trim((string) $breach->getMeasuresTaken()) === '' ? 'measuresTaken' : null,
        ]));
    }
}
