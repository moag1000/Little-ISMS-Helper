<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Diff;

use App\Entity\Document;
use App\Entity\DocumentSection;

/**
 * Policy-Wizard W7-C — DTO emitted by {@see PolicyDiffService::diffDocuments}.
 *
 * Doc-level + variable-level only — explicit "NOT character-level" per the
 * ISB practitioner review (`docs/plans/policy-wizard/persona-reviews/05-isb-
 * practitioner-review.md` lines 198-204) and the sprint reconciliation
 * spec (`07-phase4-sprint-reconciliation.md` lines 313-316).
 *
 * Severity heuristic justification:
 *  - `minor`    : metadata only, OR ≤2 substitution-variable changes. The
 *                 policy is essentially the same — a typo fix, a contact
 *                 update, a date roll. Fast-path approval is appropriate.
 *  - `moderate` : 3+ variable changes OR 1-2 section adds/removes. The
 *                 policy now says materially different things in scattered
 *                 spots; the CISO/DPO needs to skim before approval.
 *  - `major`    : standard or topic changed (different framework!) OR
 *                 3+ section adds/removes. Effectively a new policy with
 *                 the old as historical reference; a full re-review is
 *                 unavoidable.
 *
 * The thresholds map onto the §9.4 Review-without-changes Fast-Path:
 * `minor` ⇒ CISO-only quittance, `moderate`/`major` ⇒ full GF approval.
 */
final readonly class PolicyDiff
{
    public const string SEVERITY_MINOR = 'minor';
    public const string SEVERITY_MODERATE = 'moderate';
    public const string SEVERITY_MAJOR = 'major';

    /** @var list<string> */
    public const array SEVERITY_LEVELS = [
        self::SEVERITY_MINOR,
        self::SEVERITY_MODERATE,
        self::SEVERITY_MAJOR,
    ];

    /**
     * @param list<array{field: string, oldValue: mixed, newValue: mixed}> $metadataDelta
     * @param list<array{key: string, change_type: string, oldValue: mixed, newValue: mixed}> $variableChanges
     * @param list<DocumentSection> $sectionsAdded
     * @param list<DocumentSection> $sectionsRemoved
     * @param list<array{section: DocumentSection, oldHash: ?string, newHash: ?string}> $sectionsModified
     * @param array{totalChanges: int, severity: string} $summaryStats
     * @param bool $policyBodyDrift
     *     True when the `previous` document carried tenant-specific
     *     post-generation edits to its `policyBody` — re-generation
     *     preserved the edited body on `previous` and forked a new
     *     wizard-baseline version (`current`). The diff UI surfaces
     *     this so the CISO can manually merge the edits forward.
     */
    public function __construct(
        public Document $previous,
        public Document $current,
        public bool $metadataChanged,
        public array $metadataDelta,
        public array $variableChanges,
        public array $sectionsAdded,
        public array $sectionsRemoved,
        public array $sectionsModified,
        public bool $bodyHashChanged,
        public array $summaryStats,
        public bool $policyBodyDrift = false,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->metadataChanged
            || $this->variableChanges !== []
            || $this->sectionsAdded !== []
            || $this->sectionsRemoved !== []
            || $this->sectionsModified !== []
            || $this->bodyHashChanged
            || $this->policyBodyDrift;
    }

    public function severity(): string
    {
        return $this->summaryStats['severity'];
    }

    public function totalChanges(): int
    {
        return $this->summaryStats['totalChanges'];
    }
}
