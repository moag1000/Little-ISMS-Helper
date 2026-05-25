<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\WizardRunRepository;

/**
 * Policy-Wizard W4-C — Document-Split-Needed reminder.
 *
 * Fires on a Tenant when at least one {@see WizardRun} carries a
 * `bestandsaufnahme` decision with action `split_to_topics`. The wizard
 * cannot auto-split a legacy document into multiple topic-specific
 * follow-ups — it logs a warning (see
 * {@see \App\Service\PolicyWizard\DocumentGenerator}) and persists the
 * unresolved decision inside {@see WizardRun::$inputs}. This rule lifts
 * that backlog into the operator's view so the manual handling is not
 * silently forgotten.
 *
 * Tier 2 (audit-gap-closer) and dismissible: a delayed split is not an
 * immediate regulatory breach, but the persona-Auditor will flag
 * unresolved legacy docs that should have been replaced. The CTA
 * jumps back into the originating wizard run so the operator can
 * either re-classify (action='merge_into_topic' / 'replace') or kick
 * off a fresh targeted run for each target topic.
 *
 * Source signal: {@see WizardRun::$inputs}['bestandsaufnahme']['decisions'][].
 * Spec anchor: `07-phase4-sprint-reconciliation.md` W4-C + DocumentGenerator
 * inline TODO at `src/Service/PolicyWizard/DocumentGenerator.php:302`.
 */
final class DocumentSplitNeededRule extends AbstractAlvaHintRule
{
    /** Bump when the rule's threshold or condition changes. */
    public const VERSION = 1;

    public function __construct(
        private readonly WizardRunRepository $wizardRunRepository,
    ) {
    }

    public function key(): string
    {
        return 'policy_wizard.document_split_needed';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    /**
     * @return array<int, string>
     */
    public function requiredModules(): array
    {
        return ['policy_wizard'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Tenant) {
            return false;
        }
        return $this->firstUnresolvedSplit($entity) !== null;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Tenant);

        $picked = $this->firstUnresolvedSplit($entity);
        $run = $picked['run'] ?? null;
        $decision = $picked['decision'] ?? [];
        $topic = (string) ($picked['topic'] ?? '');
        $targets = $decision['target_topics'] ?? [];
        if (!is_array($targets)) {
            $targets = [];
        }
        $targetList = $targets === [] ? '—' : implode(', ', array_map('strval', $targets));
        $totalCount = $this->countUnresolvedSplits($entity);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'alva_hint.document_split_needed.title',
            bodyTranslationKey: 'alva_hint.document_split_needed.body',
            bodyTranslationParams: [
                '%topic%' => $topic,
                '%target_topics%' => $targetList,
                '%count%' => (string) $totalCount,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'alva_hint.document_split_needed.cta_label',
            actionRoute: 'app_policy_wizard_step_show',
            actionRouteParams: [
                'runId' => $run?->getId() ?? 0,
                'step' => 'bestandsaufnahme',
            ],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: self::VERSION,
        );
    }

    /**
     * Return the first open WizardRun + decision that carries an unresolved
     * split_to_topics action. Caller uses the returned topic / target list
     * to render the hint body.
     *
     * @return array{run: WizardRun, decision: array<string, mixed>, topic: string}|null
     */
    private function firstUnresolvedSplit(Tenant $tenant): ?array
    {
        foreach ($this->wizardRunRepository->findOpenForTenant($tenant) as $run) {
            $hit = $this->findFirstSplitDecision($run);
            if ($hit !== null) {
                return ['run' => $run, 'decision' => $hit['decision'], 'topic' => $hit['topic']];
            }
        }
        return null;
    }

    /**
     * Total number of unresolved splits across the tenant's open runs.
     * Used for the body translation parameter so the operator can gauge
     * the size of the backlog before opening the first run.
     */
    private function countUnresolvedSplits(Tenant $tenant): int
    {
        $count = 0;
        foreach ($this->wizardRunRepository->findOpenForTenant($tenant) as $run) {
            foreach ($this->iterateSplitDecisions($run) as $_) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return array{decision: array<string, mixed>, topic: string}|null
     */
    private function findFirstSplitDecision(WizardRun $run): ?array
    {
        foreach ($this->iterateSplitDecisions($run) as $hit) {
            return $hit;
        }
        return null;
    }

    /**
     * @return iterable<array{decision: array<string, mixed>, topic: string}>
     */
    private function iterateSplitDecisions(WizardRun $run): iterable
    {
        $inputs = $run->getInputs() ?? [];
        $slot = $inputs['bestandsaufnahme'] ?? null;
        if (!is_array($slot)) {
            return;
        }
        $decisions = $slot['decisions'] ?? [];
        if (!is_array($decisions)) {
            return;
        }
        foreach ($decisions as $key => $decision) {
            if (!is_array($decision)) {
                continue;
            }
            if (($decision['action'] ?? null) !== 'split_to_topics') {
                continue;
            }
            yield ['decision' => $decision, 'topic' => is_string($key) ? $key : (string) $key];
        }
    }
}
