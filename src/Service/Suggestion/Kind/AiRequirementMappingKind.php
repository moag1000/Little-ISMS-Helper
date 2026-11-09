<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Suggestion\TargetAccess;

/**
 * Compliance manager after activating a framework: a requirement of an
 * active framework that none of the organisation's controls covers yet → the
 * model picks, from a shortlist of the organisation's own controls, those
 * that fulfil it. Accepting links the controls to the requirement through the
 * same path as the rule-based ControlRequirementLinkKind; the global mapping
 * catalogue is never written.
 *
 * Nightly only: a requirement is a catalogue row without an organisation, so
 * it has no "Entwurf vorschlagen" button (RecordDrafts needs the record's tenant).
 */
final class AiRequirementMappingKind extends AbstractAiKind
{
    public const string KEY = 'ai.requirement_mapping';

    /** Controls shown to the model. */
    private const int SHORTLIST = 40;

    /** Controls one suggestion may link. */
    private const int MAX_LINKS = 5;

    private const int MAX_SCANNED = 400;

    /** Organisation of the running nightly pass: describe() gets no tenant, and no request context runs at night. */
    private ?Tenant $runTenant = null;

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly ComplianceFrameworkRepository $frameworks,
        private readonly ComplianceRequirementRepository $requirements,
        private readonly ControlRepository $controls,
        private readonly FulfillmentRuleEngine $engine,
        private readonly ControlRequirementLinkKind $links,
        private readonly TenantContext $tenantContext,
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
        return TargetAccess::role('ROLE_MANAGER', 'links several controls; the SoA link per control needs ControlVoter::EDIT');
    }

    public function decisionRoles(): array
    {
        return ['ROLE_COMPLIANCE_MANAGER', 'ROLE_ISB', 'ROLE_MANAGER'];
    }

    public function requiredModule(): string
    {
        return 'compliance';
    }

    /** A catalogue row has no organisation: no button on the requirement page. */
    public function subjectClasses(): array
    {
        return [];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        $this->runTenant = $tenant;
        $scanned = 0;
        foreach ($this->frameworks->findActiveFrameworks() as $framework) {
            foreach ($this->requirements->findInScopeForFrameworkListing($framework, $tenant) as $requirement) {
                if (++$scanned > self::MAX_SCANNED) {
                    return;
                }
                if ($this->needsControls($requirement, $tenant)) {
                    yield $requirement;
                }
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        return $record instanceof ComplianceRequirement
            && $tenant instanceof Tenant
            && $this->needsControls($record, $tenant);
    }

    protected function instructions(): string
    {
        return 'Decide which of the listed controls of the organisation fulfil this compliance requirement. '
            . 'Pick at most ' . self::MAX_LINKS . ' controls whose objective clearly covers the requirement; pick none '
            . 'when no listed control fits. Return key "controls" (list of control ids exactly as listed, e.g. "A.5.1") '
            . 'and "reasoning" (one sentence per control).';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof ComplianceRequirement);
        $tenant = $this->runTenant ?? $this->tenantContext->getCurrentTenant();
        $lines = [
            'Framework: ' . $record->getFramework()?->getName(),
            'Requirement: ' . $record->getRequirementId() . ' ' . $record->getTitle(),
            $record->getCategory() ? 'Category: ' . $record->getCategory() : null,
            $record->getDescription() ? 'Description: ' . mb_substr((string) $record->getDescription(), 0, 1500) : null,
            'Controls (id | name | objective):',
        ];
        foreach ($tenant instanceof Tenant ? $this->shortlist($record, $tenant) : [] as $control) {
            $lines[] = $control->getControlId() . ' | ' . $control->getName() . ' | ' . mb_substr((string) $control->getDescription(), 0, 120);
        }

        return implode("\n", array_filter($lines));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof ComplianceRequirement);
        $ids = is_array($data['controls'] ?? null) ? $data['controls'] : [];
        $covered = $this->engine->controlsFor($record, $tenant);
        $picked = [];
        foreach ($ids as $controlId) {
            if (!is_scalar($controlId) || count($picked) >= self::MAX_LINKS) {
                continue;
            }
            $control = $this->controls->findByControlIdAndTenant(trim((string) $controlId), $tenant);
            if ($control instanceof Control && !isset($picked[(int) $control->getId()])
                && !in_array($control, $covered, true)) {
                $picked[(int) $control->getId()] = $control;
            }
        }
        if ($picked === []) {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_mapping.title', [
                '%requirement%' => trim(($record->getFramework()?->getCode() ?? '') . ' ' . ($record->getRequirementId() ?? '')),
                '%controls%' => implode(', ', array_map(static fn (Control $c): string => ControlState::label($c), $picked)),
            ]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_mapping.rationale', [
                '%title%' => (string) $record->getTitle(),
                '%reasoning%' => self::text($data, 'reasoning', 800),
            ]),
            payload: ['controls' => array_keys($picked)],
            confidence: self::confidence($data),
            dedupKey: 'requirement:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $tenant = $suggestion->getTenant();
        $requirement = $this->entityManager->find(ComplianceRequirement::class, (int) $suggestion->getSubjectId());
        if (!$requirement instanceof ComplianceRequirement
            || ($requirement->getUploadTenant() !== null && $requirement->getUploadTenant() !== $tenant)) {
            throw new StaleSuggestionException('requirement_gone');
        }
        $covered = $this->engine->controlsFor($requirement, $tenant);
        $linked = 0;
        foreach ((array) ($suggestion->getPayload()['controls'] ?? []) as $id) {
            $control = $this->entityManager->find(Control::class, (int) $id);
            if (!$control instanceof Control || $control->getTenant() !== $tenant || in_array($control, $covered, true)) {
                continue;
            }
            $this->links->link($requirement, $control);
            $linked++;
        }
        if ($linked === 0) {
            throw new StaleSuggestionException('changed_meanwhile');
        }

        return $requirement;
    }

    /** Active framework, assessable row of the organisation, none of its controls behind it yet. */
    private function needsControls(ComplianceRequirement $requirement, Tenant $tenant): bool
    {
        $framework = $requirement->getFramework();
        $upload = $requirement->getUploadTenant();
        if ($framework === null || !$framework->isActive() || ($upload !== null && $upload !== $tenant)
            || in_array($requirement->getCategory(), ['section', 'legacy_unmapped'], true)
            || trim((string) $requirement->getTitle()) === '') {
            return false;
        }
        // The engine's answer (identity control, catalogue ids, mappings), scoped to the organisation.
        return $this->engine->controlsFor($requirement, $tenant) === [];
    }

    /**
     * The organisation's applicable controls that share most words with the
     * requirement, at most SHORTLIST, in ISO order among equals.
     *
     * @return list<Control>
     */
    private function shortlist(ComplianceRequirement $requirement, Tenant $tenant): array
    {
        $wanted = self::words($requirement->getTitle() . ' ' . $requirement->getDescription() . ' ' . $requirement->getCategory());
        $scored = [];
        foreach ($this->controls->findApplicableControls($tenant) as $position => $control) {
            $words = self::words($control->getName() . ' ' . $control->getDescription());
            $scored[] = [count(array_intersect_key($wanted, $words)), -$position, $control];
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return array_map(static fn (array $row): Control => $row[2], array_slice($scored, 0, self::SHORTLIST));
    }

    /** @return array<string, true> words of 4+ letters, lower case */
    private static function words(string $text): array
    {
        preg_match_all('/\p{L}{4,}/u', mb_strtolower($text), $matches);

        return array_fill_keys($matches[0], true);
    }
}
