<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\WizardRunRepository;
use DateInterval;
use DateTimeImmutable;

/**
 * Policy-Wizard W7-D — Tier-1 open-finding-reference reminder.
 *
 * Fires on a Tenant when at least one in-progress {@see WizardRun}
 * with a {@see WizardRun::$findingReference} is older than
 * {@see self::STALE_AFTER_DAYS} days AND no completed re-run has
 * consumed that finding reference. The auditor expects every audit
 * finding referenced inside a wizard run to either close (re-run
 * completes, generates fresh policy) or to carry an explicit
 * justification — silent staleness is the typical NC trigger.
 *
 * Tier 1 (regulatory / audit-blocking) and non-dismissible: the
 * Konzern-CISO and Tenant-Admin both need the reminder until the
 * referenced finding is materially closed. CTA links to a fresh
 * targeted re-run pre-seeded with the finding reference, so the
 * user can ack-and-act in one click.
 *
 * Spec anchor: `07-phase4-sprint-reconciliation.md` lines 309-311
 * (W7-D Alva-Hints catalogue, finding-reference item).
 */
final class OpenFindingReferenceRule extends AbstractAlvaHintRule
{
    /** Bump when the rule's threshold or condition changes. */
    public const VERSION = 1;

    /** Days a referenced finding may remain in_progress before the rule fires. */
    public const STALE_AFTER_DAYS = 30;

    public function __construct(
        private readonly WizardRunRepository $wizardRunRepository,
    ) {
    }

    public function key(): string
    {
        return 'policy_wizard.open_finding_reference';
    }

    public function priorityTier(): int
    {
        return 1;
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
        return $this->firstStaleRun($entity) !== null;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Tenant);

        $stale = $this->firstStaleRun($entity);
        $findingRef = $stale?->getFindingReference() ?? '';
        $startedAt = $stale?->getStartedAt();
        $ageDays = $startedAt !== null
            ? (int) max(0, (new DateTimeImmutable())->diff($startedAt)->days)
            : self::STALE_AFTER_DAYS;

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'alva_hint.open_finding_reference.title',
            bodyTranslationKey: 'alva_hint.open_finding_reference.body',
            bodyTranslationParams: [
                '%finding_ref%' => $findingRef,
                '%age_days%' => (string) $ageDays,
                '%threshold_days%' => (string) self::STALE_AFTER_DAYS,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Tenant',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'alva_hint.open_finding_reference.cta_label',
            actionRoute: 'app_policy_wizard_index',
            actionRouteParams: [
                'mode' => 'targeted',
                'finding_ref' => $findingRef,
            ],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_ADMIN', 'ROLE_GROUP_CISO'],
            mood: 'thinking',
            version: self::VERSION,
        );
    }

    /**
     * Return the first {@see WizardRun} that is in_progress, carries
     * a non-empty findingReference, is older than the staleness
     * threshold, and whose finding has not been consumed by a later
     * completed run on the same tenant.
     */
    private function firstStaleRun(Tenant $tenant): ?WizardRun
    {
        $openRuns = $this->wizardRunRepository->findOpenForTenant($tenant);
        if ($openRuns === []) {
            return null;
        }

        $cutoff = (new DateTimeImmutable())->sub(new DateInterval('P' . self::STALE_AFTER_DAYS . 'D'));
        $consumedRefs = $this->consumedFindingReferences($tenant);

        foreach ($openRuns as $run) {
            $ref = $run->getFindingReference();
            if ($ref === null || $ref === '') {
                continue;
            }
            $startedAt = $run->getStartedAt();
            if ($startedAt === null || $startedAt > $cutoff) {
                continue;
            }
            if (in_array($ref, $consumedRefs, true)) {
                continue;
            }
            return $run;
        }
        return null;
    }

    /**
     * Collect findingReference strings from completed runs for the
     * tenant — those count as "consumed" and silence the open run that
     * carries the same reference.
     *
     * @return list<string>
     */
    private function consumedFindingReferences(Tenant $tenant): array
    {
        $completed = $this->wizardRunRepository->findBy([
            'tenant' => $tenant,
            'status' => 'completed',
        ]);
        $refs = [];
        foreach ($completed as $run) {
            $ref = $run->getFindingReference();
            if (is_string($ref) && $ref !== '') {
                $refs[] = $ref;
            }
        }
        return $refs;
    }
}
