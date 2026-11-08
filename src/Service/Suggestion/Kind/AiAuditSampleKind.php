<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\InternalAudit;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\InternalAuditStatus;
use App\Repository\ControlRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Preparing the audit (ISO 19011 6.3, 6.4): a planned or running audit
 * without a sample → the model picks which controls and assets of the scope
 * to check, each with the reason. Accepting appends the list to the audit's
 * observations; the checklist, the findings and the audit status are not
 * touched.
 *
 * Decided by ISB and CISO: auditors are read-only and cannot accept
 * suggestions (ReadOnlyRoleSubscriber).
 */
final class AiAuditSampleKind extends AbstractAiKind
{
    public const string KEY = 'ai.audit_sample';

    /** Records shown to the model. */
    private const int POOL = 40;

    private const int MAX_PICKS = 8;

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly InternalAuditRepository $audits,
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
        return ['ROLE_ISB', 'ROLE_CISO'];
    }

    public function requiredModule(): string
    {
        return 'audits';
    }

    public function subjectClasses(): array
    {
        return [InternalAudit::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->audits->findBy(['tenant' => $tenant], ['plannedDate' => 'DESC'], 30) as $audit) {
            if ($this->isCandidate($audit)) {
                yield $audit;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        $tenant = $record instanceof InternalAudit ? $record->getTenant() : null;

        return $record instanceof InternalAudit
            && $tenant instanceof Tenant
            && in_array($record->getStatus(), [InternalAuditStatus::Planned->value, InternalAuditStatus::InProgress->value], true)
            && !str_contains((string) $record->getObservations(), $this->text->t($tenant, 'suggestions.text.ai_sample.heading'))
            && $this->pool($record) !== [];
    }

    protected function instructions(): string
    {
        return 'Propose the sample for this internal audit (ISO/IEC 19011 6.3): choose at most ' . self::MAX_PICKS
            . ' records from the list to check, favouring controls that are critical for the scope, recently changed '
            . 'or with weak implementation status, and a spread over different areas. Return key "sample": a list of '
            . 'objects {"ref": the reference exactly as listed, "why": one sentence what to check}.';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof InternalAudit);
        $lines = [
            'Audit: ' . $record->getAuditNumber() . ' ' . $record->getTitle(),
            'Scope type: ' . $record->getScopeType(),
            $record->getScope() ? 'Scope: ' . $record->getScope() : null,
            $record->getObjectives() ? 'Objectives: ' . $record->getObjectives() : null,
            $record->getScopedFramework() ? 'Framework: ' . $record->getScopedFramework()->getName() : null,
            'Records in scope (reference | name | state):',
        ];
        foreach ($this->pool($record) as $ref => $label) {
            $lines[] = $ref . ' | ' . $label;
        }

        return implode("\n", array_filter($lines));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof InternalAudit);
        $pool = $this->pool($record);
        $items = is_array($data['sample'] ?? null) ? $data['sample'] : [];
        $picked = [];
        foreach ($items as $item) {
            $ref = is_array($item) && is_scalar($item['ref'] ?? null) ? trim((string) $item['ref']) : '';
            if ($ref === '' || !isset($pool[$ref]) || isset($picked[$ref]) || count($picked) >= self::MAX_PICKS) {
                continue;
            }
            $why = is_scalar($item['why'] ?? null) ? mb_substr(trim((string) $item['why']), 0, 300) : '';
            $picked[$ref] = '- ' . $ref . ' ' . (string) strstr($pool[$ref], ' |', true) . ($why !== '' ? ': ' . $why : '');
        }
        if ($picked === []) {
            return null;
        }
        $heading = $this->text->t($tenant, 'suggestions.text.ai_sample.heading');

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_sample.title', [
                '%audit%' => trim(($record->getAuditNumber() ?? '') . ' ' . ($record->getTitle() ?? '')),
            ]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_sample.rationale', [
                '%count%' => count($picked),
                '%pool%' => count($pool),
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: ['heading' => $heading, 'refs' => array_keys($picked)],
            editableText: $heading . "\n" . implode("\n", $picked),
            confidence: self::confidence($data),
            dedupKey: 'audit:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $audit = $this->entityManager->find(InternalAudit::class, (int) $suggestion->getSubjectId());
        if (!$audit instanceof InternalAudit || $audit->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('audit_gone');
        }
        if (!in_array($audit->getStatus(), [InternalAuditStatus::Planned->value, InternalAuditStatus::InProgress->value], true)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $heading = (string) ($suggestion->getPayload()['heading'] ?? '');
        $current = trim((string) $audit->getObservations());
        if ($heading !== '' && str_contains($current, $heading)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $text = trim((string) ($editedText ?? $suggestion->getEditableText()));
        if ($text === '') {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $audit->setObservations($current === '' ? $text : $current . "\n\n" . $text);

        return $audit;
    }

    /**
     * Reference => "name | state" of the records the model may pick from:
     * the audit's scoped assets, then the organisation's applicable controls.
     *
     * @return array<string, string>
     */
    private function pool(InternalAudit $audit): array
    {
        $tenant = $audit->getTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }
        $pool = [];
        foreach (array_slice($audit->getScopedAssets()->toArray(), 0, intdiv(self::POOL, 2)) as $asset) {
            $pool['asset ' . (int) $asset->getId()] = $asset->getName() . ' | ' . $asset->getAssetType();
        }
        foreach ($this->controls->findApplicableControls($tenant) as $control) {
            if (count($pool) >= self::POOL) {
                break;
            }
            if (trim((string) $control->getControlId()) !== '') {
                $pool[(string) $control->getControlId()] = $control->getName() . ' | ' . $control->getImplementationStatus();
            }
        }

        return $pool;
    }
}
