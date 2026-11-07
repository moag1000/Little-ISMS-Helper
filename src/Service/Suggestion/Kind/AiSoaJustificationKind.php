<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ControlRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ISB: Statement of Applicability needs a justification per control
 * (ISO 27001 6.1.3 d) — the model drafts it from the applicability decision,
 * the implementation state and the risks the control treats. The ISB edits
 * the text in the dialog before it lands in the SoA.
 */
final class AiSoaJustificationKind extends AbstractAiKind
{
    public const string KEY = 'ai.soa_justification';

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly ControlRepository $controls,
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
        return 'controls';
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->controls->findAllInIsoOrder($tenant) as $control) {
            if ($control->isApplicable() !== null && trim((string) $control->getJustification()) === '') {
                yield $control;
            }
        }
    }

    protected function instructions(): string
    {
        return 'Write the Statement of Applicability justification for this ISO/IEC 27001 Annex A control: '
            . 'why it is applicable (or excluded) for this organisation and how it is met. 2-4 factual sentences, '
            . 'no marketing language, refer to the given risks where they apply. Return key "justification".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof Control);
        $risks = array_map(
            static fn (Risk $r): string => sprintf('%s (likelihood %d, impact %d)', $r->getTitle(), (int) $r->getProbability(), (int) $r->getImpact()),
            array_slice($record->getRisks()->toArray(), 0, 10),
        );

        return implode("\n", array_filter([
            'Control: ' . $record->getControlId() . ' ' . $record->getName(),
            'Control text: ' . $record->getDescription(),
            'Applicable: ' . ($record->isApplicable() ? 'yes' : 'no'),
            'Implementation status: ' . $record->getImplementationStatus(),
            $record->getImplementationNotes() ? 'Implementation notes: ' . $record->getImplementationNotes() : null,
            $risks !== [] ? 'Risks treated: ' . implode('; ', $risks) : 'Risks treated: none linked',
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof Control);
        $justification = self::text($data, 'justification');
        if ($justification === '') {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_soa.title', ['%control%' => ControlState::label($record)]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_soa.rationale', [
                '%risks%' => $record->getRisks()->count(),
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            editableText: $justification,
            confidence: self::confidence($data),
            dedupKey: 'control:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $control = $this->entityManager->find(Control::class, (int) $suggestion->getSubjectId());
        if (!$control instanceof Control || $control->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('control_gone');
        }
        if (trim((string) $control->getJustification()) !== '') {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $control->setJustification((string) ($editedText ?? $suggestion->getEditableText()));

        return $control;
    }
}
