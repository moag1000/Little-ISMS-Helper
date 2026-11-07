<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditFindingRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Audit finding without a corrective action (ISO 27001 10.2 a–c): the model
 * drafts cause and action from the finding. Accepting creates the action in
 * status "planned", linked to the finding; owner and due date stay with the
 * person who plans it.
 */
final class AiCorrectiveActionKind extends AbstractAiKind
{
    public const string KEY = 'ai.corrective_action';

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
        return ['ROLE_ISB', 'ROLE_MANAGER'];
    }

    public function requiredModule(): ?string
    {
        return 'corrective_actions';
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->findings->findOpenByTenant($tenant) as $finding) {
            if ($finding->getCorrectiveActions()->count() === 0 && mb_strlen((string) $finding->getDescription()) >= 20) {
                yield $finding;
            }
        }
    }

    protected function instructions(): string
    {
        return 'Propose the corrective action for this audit finding (ISO/IEC 27001 clause 10.2): the likely root cause '
            . 'and what to do so it does not recur. Return keys "title" (max 120 chars), "root_cause" (1-3 sentences), '
            . '"description" (the action, 2-5 sentences, concrete and checkable).';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof AuditFinding);
        $control = $record->getRelatedControl();

        return implode("\n", array_filter([
            'Finding: ' . $record->getFindingNumber() . ' ' . $record->getTitle(),
            'Severity: ' . $record->getSeverity(),
            $record->getClauseReference() ? 'Clause: ' . $record->getClauseReference() : null,
            $control !== null ? 'Control: ' . ControlState::label($control) : null,
            'Description: ' . $record->getDescription(),
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof AuditFinding);
        $title = self::text($data, 'title', 200);
        $description = self::text($data, 'description');
        if ($title === '' || $description === '') {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_capa.title', ['%action%' => $title]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_capa.rationale', [
                '%finding%' => trim(($record->getFindingNumber() ?? '') . ' ' . ($record->getTitle() ?? '')),
                '%cause%' => self::text($data, 'root_cause', 1000) ?: '—',
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: ['title' => $title, 'root_cause' => self::text($data, 'root_cause', 2000)],
            editableText: $description,
            confidence: self::confidence($data),
            dedupKey: 'finding:' . (int) $record->getId(),
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
        $action = new CorrectiveAction();
        $action->setTenant($suggestion->getTenant());
        $action->setFinding($finding);
        $action->setSourceType(CorrectiveAction::SOURCE_TYPE_AUDIT_FINDING);
        $action->setActionType('corrective');
        $action->setTitle((string) $p['title']);
        $action->setDescription((string) ($editedText ?? $suggestion->getEditableText()));
        $action->setRootCauseAnalysis((string) ($p['root_cause'] ?? '') ?: null);
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        return $action;
    }
}
