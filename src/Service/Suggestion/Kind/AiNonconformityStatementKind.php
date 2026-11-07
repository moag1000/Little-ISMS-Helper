<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\AuditFinding;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\AuditFindingStatus;
use App\Repository\AuditFindingRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Auditor after the interview: a nonconformity noted in a few words → the
 * model turns the notes into the nonconformity statement the audit report
 * needs (ISO 19011 6.4.8, ISO 27001 10.2 a): requirement, what was observed,
 * why it does not meet the requirement. Accepting replaces the description
 * (unchanged since the draft) and fills the evidence if it is empty.
 */
final class AiNonconformityStatementKind extends AbstractAiKind
{
    public const string KEY = 'ai.nonconformity_statement';

    /** Notes up to this length count as notes, longer descriptions as written statements. */
    private const int NOTES_MAX = 400;

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly AuditFindingRepository $findings,
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
        return ['ROLE_ISB'];
    }

    public function requiredModule(): string
    {
        return 'audits';
    }

    public function subjectClasses(): array
    {
        return [AuditFinding::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->findings->findOpenByTenant($tenant) as $finding) {
            if ($this->isCandidate($finding)) {
                yield $finding;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        if (!$record instanceof AuditFinding
            || !in_array($record->getType(), [AuditFinding::TYPE_MAJOR_NC, AuditFinding::TYPE_MINOR_NC], true)
            || in_array($record->getStatus(), AuditFindingStatus::doneValues(), true)) {
            return false;
        }
        $length = mb_strlen(trim((string) $record->getDescription()));

        return $length >= 15 && ($length <= self::NOTES_MAX || trim((string) $record->getEvidence()) === '');
    }

    protected function instructions(): string
    {
        return 'Turn the auditor\'s notes into a nonconformity statement for the audit report (ISO 19011 6.4.8): '
            . '"statement" = which requirement is not met, what was observed, why the observation does not meet the '
            . 'requirement (3-6 sentences, factual, no blame, no recommendation); "evidence" = the objective evidence '
            . 'seen (documents, records, interviews), one line each. Use only facts from the notes. '
            . 'Return keys "statement", "evidence".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof AuditFinding);
        $audit = $record->getAudit();
        $control = $record->getRelatedControl();

        return implode("\n", array_filter([
            'Finding: ' . $record->getTitle() . ' (' . $record->getType() . ')',
            $audit !== null ? 'Audit: ' . $audit->getTitle() . ($audit->getScope() ? ' — scope: ' . $audit->getScope() : '') : null,
            $record->getClauseReference() ? 'Requirement / clause: ' . $record->getClauseReference() : null,
            $control !== null ? 'Control: ' . ControlState::label($control) : null,
            'Notes: ' . $record->getDescription(),
            $record->getEvidence() ? 'Evidence noted: ' . $record->getEvidence() : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof AuditFinding);
        $statement = self::text($data, 'statement');
        if ($statement === '') {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_nc.title', [
                '%finding%' => trim(($record->getFindingNumber() ?? '') . ' ' . ($record->getTitle() ?? '')),
            ]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_nc.rationale', [
                '%clause%' => $record->getClauseReference() ?: '—',
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: [
                'evidence' => self::text($data, 'evidence', 3000),
                'notes_hash' => sha1(trim((string) $record->getDescription())),
            ],
            editableText: $statement,
            confidence: self::confidence($data),
            dedupKey: 'finding:' . (int) $record->getId() . ':' . sha1(trim((string) $record->getDescription())),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $finding = $this->entityManager->find(AuditFinding::class, (int) $suggestion->getSubjectId());
        if (!$finding instanceof AuditFinding || $finding->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('finding_gone');
        }
        $p = $suggestion->getPayload();
        if (sha1(trim((string) $finding->getDescription())) !== ($p['notes_hash'] ?? null)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $finding->setDescription((string) ($editedText ?? $suggestion->getEditableText()));
        $evidence = trim((string) ($p['evidence'] ?? ''));
        if ($evidence !== '' && trim((string) $finding->getEvidence()) === '') {
            $finding->setEvidence($evidence);
        }

        return $finding;
    }
}
