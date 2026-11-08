<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ControlRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionNote;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Preparing an audit (ISO 19011 6.4): a control with evidence documents →
 * the model judges from the documents' titles and descriptions whether they
 * fit the control objective and drafts a short audit note. Accepting adds the
 * note as a comment on the control, under the name of the person who
 * accepted; the control's status and effectiveness stay untouched.
 *
 * Auditors (ROLE_AUDITOR) are read-only and cannot accept suggestions
 * (ReadOnlyRoleSubscriber), so the note is decided by ISB and CISO.
 */
final class AiEvidenceFitKind extends AbstractAiKind
{
    public const string KEY = 'ai.evidence_fit';

    private const int MAX_DOCUMENTS = 10;

    private const array VERDICTS = ['yes', 'partly', 'no'];

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly ControlRepository $controls,
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
        return ['ROLE_ISB', 'ROLE_CISO'];
    }

    public function requiredModule(): string
    {
        return 'audits';
    }

    public function subjectClasses(): array
    {
        return [Control::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->controls->findApplicableControls($tenant) as $control) {
            if ($this->isCandidate($control) && ControlState::isImplemented($control)) {
                yield $control;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        return $record instanceof Control
            && $record->isApplicable() !== false
            && $record->getEvidenceDocuments()->count() > 0
            && trim((string) $record->getName()) !== '';
    }

    protected function instructions(): string
    {
        return 'Judge whether the evidence documents listed for this ISO/IEC 27001 Annex A control fit its objective, '
            . 'judging only from the documents\' titles, categories and descriptions (you cannot see their content). '
            . 'Return keys "fits" (one of: yes, partly, no), "note" (the audit note, 2-4 factual sentences: what the '
            . 'evidence shows, what is missing, what the auditor should ask for) and "reasoning" (one sentence).';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof Control);
        $lines = [
            'Control: ' . ControlState::label($record),
            'Control objective: ' . $record->getDescription(),
            'Implementation status: ' . $record->getImplementationStatus(),
            $record->getImplementationNotes() ? 'Implementation notes: ' . $record->getImplementationNotes() : null,
            'Evidence documents:',
        ];
        foreach (self::documents($record) as $document) {
            $lines[] = '- ' . ($document->getOriginalFilename() ?: $document->getFilename())
                . ($document->getCategory() ? ' [' . $document->getCategory() . ']' : '')
                . ($document->getUploadedAt() ? ' (' . $document->getUploadedAt()->format('Y-m-d') . ')' : '')
                . ($document->getDescription() ? ': ' . mb_substr($document->getDescription(), 0, 300) : '');
        }

        return implode("\n", array_filter($lines));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof Control);
        $note = self::text($data, 'note', 3000);
        $fits = strtolower(self::text($data, 'fits', 10));
        if ($note === '' || !in_array($fits, self::VERDICTS, true)) {
            return null;
        }
        $verdict = $this->text->t($tenant, 'suggestions.text.ai_evidence.verdict.' . $fits);

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_evidence.title', ['%control%' => ControlState::label($record)]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_evidence.rationale', [
                '%documents%' => count(self::documents($record)),
                '%verdict%' => $verdict,
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: ['fits' => $fits, 'documents' => self::documentIds($record)],
            editableText: $this->text->t($tenant, 'suggestions.text.ai_evidence.heading', ['%verdict%' => $verdict]) . "\n" . $note,
            confidence: self::confidence($data),
            dedupKey: 'control:' . (int) $record->getId() . ':' . implode(',', self::documentIds($record)),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $control = $this->entityManager->find(Control::class, (int) $suggestion->getSubjectId());
        $tenant = $suggestion->getTenant();
        if (!$control instanceof Control || $control->getTenant() !== $tenant) {
            throw new StaleSuggestionException('control_gone');
        }
        if (self::documentIds($control) !== array_map('intval', (array) ($suggestion->getPayload()['documents'] ?? []))) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $body = trim((string) ($editedText ?? $suggestion->getEditableText()));
        if ($body === '') {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $this->notes->add($tenant, 'Control', (int) $control->getId(), $by, $body);

        return $control;
    }

    /** @return list<Document> */
    private static function documents(Control $control): array
    {
        return array_slice($control->getEvidenceDocuments()->toArray(), 0, self::MAX_DOCUMENTS);
    }

    /** @return list<int> sorted ids of the documents the model read */
    private static function documentIds(Control $control): array
    {
        $ids = array_map(static fn (Document $d): int => (int) $d->getId(), self::documents($control));
        sort($ids);

        return $ids;
    }
}
