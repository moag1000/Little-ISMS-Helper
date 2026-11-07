<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\ComplianceFramework;
use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\RecordDraftKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ISB without a language model: the SoA justification (ISO 27001 6.1.3 d)
 * written from the organisation's own links — the risks the control treats
 * and the requirements of other active frameworks that ask for it, plus the
 * implementation state. No link, no draft: a justification without facts is
 * what auditors reject. Nightly only while the AI variant
 * (ai.soa_justification) is not available, so a control never gets both.
 */
final class SoaJustificationRuleKind extends AbstractSuggestionKind implements RecordDraftKind
{
    public const string KEY = 'soa.justification';

    private const int MAX_LISTED = 5;

    public function __construct(
        private readonly ControlRepository $controls,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly AiAssistant $ai,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionText $text,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function origin(): SuggestionOrigin
    {
        return SuggestionOrigin::Rule;
    }

    public function decisionRoles(): array
    {
        return ['ROLE_ISB', 'ROLE_MANAGER'];
    }

    public function requiredModule(): string
    {
        return 'controls';
    }

    public function subjectClasses(): array
    {
        return [Control::class];
    }

    public function needsModel(): bool
    {
        return false;
    }

    public function generate(Tenant $tenant): iterable
    {
        if ($this->ai->isAvailable($tenant, AiSoaJustificationKind::KEY)) {
            return;
        }
        foreach ($this->controls->findAllInIsoOrder($tenant) as $control) {
            $draft = $this->supportsRecord($control) ? $this->draftFor($tenant, $control) : null;
            if ($draft !== null) {
                yield $draft;
            }
        }
    }

    public function supportsRecord(object $record): bool
    {
        return $record instanceof Control
            && $record->isApplicable() !== null
            && trim((string) $record->getJustification()) === '';
    }

    public function draftFor(Tenant $tenant, object $record): ?SuggestionDraft
    {
        if (!$record instanceof Control || !$this->supportsRecord($record)) {
            return null;
        }
        $risks = array_map(
            static fn (Risk $r): string => '„' . $r->getTitle() . '“',
            array_slice($record->getRisks()->toArray(), 0, self::MAX_LISTED),
        );
        $requirements = $this->requirements($record);

        if ($record->isApplicable()) {
            if ($risks === [] && $requirements === []) {
                return null;
            }
            $parts = [];
            if ($risks !== []) {
                $parts[] = $this->text->t($tenant, 'suggestions.text.soa_rule.risks', [
                    '%risks%' => implode(', ', $risks) . self::more($record->getRisks()->count()),
                ]);
            }
            if ($requirements !== []) {
                $parts[] = $this->text->t($tenant, 'suggestions.text.soa_rule.requirements', [
                    '%requirements%' => implode(', ', array_slice($requirements, 0, self::MAX_LISTED)) . self::more(count($requirements)),
                ]);
            }
            $parts[] = $this->text->t($tenant, 'suggestions.text.soa_rule.status.' . ($record->getImplementationStatus() ?? 'not_started'));
            $justification = implode(' ', $parts);
        } else {
            if ($risks !== [] || $requirements !== []) {
                // Excluded although a risk or requirement asks for it: a contradiction for the ISB, not a text.
                return null;
            }
            $justification = $this->text->t($tenant, 'suggestions.text.soa_rule.excluded');
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_soa.title', ['%control%' => ControlState::label($record)]),
            rationale: $this->text->t($tenant, 'suggestions.text.soa_rule.rationale', [
                '%risks%' => $record->getRisks()->count(),
                '%requirements%' => count($requirements),
            ]),
            editableText: $justification,
            dedupKey: 'control:' . (int) $record->getId() . ':' . sha1($justification),
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

    /** @return list<string> "NIS2 21.2.a" of active frameworks, without the control's own Annex A entry */
    private function requirements(Control $control): array
    {
        if ($control->getId() === null) {
            return [];
        }
        $labels = [];
        foreach ($this->requirementRepository->findByControl($control->getId()) as $requirement) {
            $framework = $requirement->getFramework();
            $id = (string) $requirement->getRequirementId();
            if (!$framework instanceof ComplianceFramework || $framework->isActive() === false
                || $id === '' || $id === $control->getControlId()) {
                continue;
            }
            $labels[] = $framework->getCode() . ' ' . $id;
        }
        sort($labels);

        return array_values(array_unique($labels));
    }

    private static function more(int $total): string
    {
        return $total > self::MAX_LISTED ? ' …' : '';
    }
}
