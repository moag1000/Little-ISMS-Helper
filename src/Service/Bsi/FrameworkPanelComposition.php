<?php

declare(strict_types=1);

namespace App\Service\Bsi;

/**
 * Data-driven map: given a source + target framework code, return the expert-panel
 * composition that should review a mapping batch for that pair.
 *
 * ## Specialist assignment rules
 * Each framework code is assigned a primary specialist skill from the set of available
 * persona-skills. When both source and target map to the same specialist, the second
 * expert slot falls back to `risk-management-specialist` (if that is not itself a
 * duplicate) or `persona-compliance-manager` as a final fallback.
 *
 * ## Cross-cutting lenses
 * Every panel gets two additional cross-cutting reviewers regardless of the framework
 * pair: a senior consultant and an external auditor.
 *
 * ## Return structure
 * `compose()` returns:
 * ```php
 * [
 *     'experts' => [
 *         ['skill' => 'isms-specialist', 'refs' => '.claude/skills/isms-specialist/references/'],
 *         ['skill' => 'bsi-specialist',  'refs' => '.claude/skills/bsi-specialist/references/'],
 *     ],
 *     'lenses' => [
 *         'persona-consultant-senior',
 *         'persona-auditor-external',
 *     ],
 * ]
 * ```
 *
 * This is a pure-data service: no I/O, no Doctrine, no HTTP. The panel-authoring
 * controller reads this map when assembling a P3 panel run for a framework pair.
 */
final class FrameworkPanelComposition
{
    /** Root directory for specialist reference catalogues (relative to project root) */
    private const REFS_ROOT = '.claude/skills/';

    /**
     * Cross-cutting lens skills present in every panel.
     *
     * @var list<string>
     */
    private const CROSS_CUTTING_LENSES = [
        'persona-consultant-senior',
        'persona-auditor-external',
    ];

    /**
     * Per-primary-skill fallback: when both frameworks map to the same specialist,
     * use this map to pick the second expert slot.
     *
     * Design rationale:
     *   - isms+isms  → risk-management-specialist  (risk is the nearest ISMS neighbour)
     *   - dpo+dpo    → isms-specialist              (ISO 27001 governs the security underpinning)
     *   - bsi+bsi    → isms-specialist              (ISO 27001 is the cross-reference norm)
     *   - risk+risk  → persona-compliance-manager   (compliance reviewer most suitable)
     *   - bcm+bcm    → risk-management-specialist   (BCM risk is closely related)
     *   - default    → persona-compliance-manager   (safe generic fallback)
     *
     * @var array<string, string>  primarySkill => fallbackSkill
     */
    private const FALLBACK_BY_PRIMARY = [
        'isms-specialist'             => 'risk-management-specialist',
        'dpo-specialist'              => 'isms-specialist',
        'bsi-specialist'              => 'isms-specialist',
        'risk-management-specialist'  => 'persona-compliance-manager',
        'bcm-specialist'              => 'risk-management-specialist',
    ];

    /**
     * Framework-code → specialist skill map.
     *
     * Matching is case-insensitive and uses a prefix check so variant codes like
     * `ISO27001-2022` and `ISO_27001` both hit the `iso` bucket.
     *
     * @var array<string, string>  pattern => skill
     */
    private const FRAMEWORK_SKILL_MAP = [
        // ISO family (covers ISO27001, ISO27001-2022, ISO_27001, EUCS, NIST, NIS2, DORA, BaFin)
        'iso'   => 'isms-specialist',
        'eucs'  => 'isms-specialist',
        'nist'  => 'isms-specialist',
        'nis2'  => 'isms-specialist',
        'dora'  => 'isms-specialist',
        'bafin' => 'isms-specialist',
        // BSI / TISAX
        'bsi'   => 'bsi-specialist',
        'tisax' => 'bsi-specialist',
        // GDPR / privacy (ISO 27701, ISO 27018)
        'gdpr'     => 'dpo-specialist',
        'iso27701' => 'dpo-specialist',
        'iso27018' => 'dpo-specialist',
        // Risk management
        'iso27005' => 'risk-management-specialist',
        'iso31000' => 'risk-management-specialist',
        'bcm'      => 'bcm-specialist',
        'iso22301' => 'bcm-specialist',
    ];

    /**
     * Resolve the specialist skill for a single framework code.
     *
     * Matching order:
     *  1. Exact lowercased match in the skill map.
     *  2. Prefix match — the lowercased code starts with a map key (longest key first
     *     to avoid short-key ambiguity, e.g. 'iso27701' before 'iso').
     *  3. Default: `isms-specialist` (broadest coverage for unknown frameworks).
     */
    public function specialistFor(string $frameworkCode): string
    {
        $code = strtolower($frameworkCode);

        // Exact match first
        if (isset(self::FRAMEWORK_SKILL_MAP[$code])) {
            return self::FRAMEWORK_SKILL_MAP[$code];
        }

        // Prefix match — sort keys by descending length so longer keys win
        $keys = array_keys(self::FRAMEWORK_SKILL_MAP);
        usort($keys, static fn (string $a, string $b): int => strlen($b) - strlen($a));

        foreach ($keys as $pattern) {
            if (str_starts_with($code, $pattern)) {
                return self::FRAMEWORK_SKILL_MAP[$pattern];
            }
        }

        // Default — isms-specialist has the broadest cross-framework coverage
        return 'isms-specialist';
    }

    /**
     * Compose the full expert panel for a source ↔ target framework pair.
     *
     * @param string $sourceCode Framework code of the source (e.g. 'ISO27001', 'NIS2', 'GDPR')
     * @param string $targetCode Framework code of the target (e.g. 'BSI_GRUNDSCHUTZ', 'ISO27001')
     *
     * @return array{
     *     experts: list<array{skill: string, refs: string}>,
     *     lenses: list<string>,
     * }
     */
    public function compose(string $sourceCode, string $targetCode): array
    {
        $sourceSkill = $this->specialistFor($sourceCode);
        $targetSkill = $this->specialistFor($targetCode);

        // When both frameworks require the same specialist, pick a fallback for the second slot
        if ($sourceSkill === $targetSkill) {
            $targetSkill = $this->fallbackSpecialist($sourceSkill);
        }

        return [
            'experts' => [
                [
                    'skill' => $sourceSkill,
                    'refs'  => self::REFS_ROOT . $sourceSkill . '/references/',
                ],
                [
                    'skill' => $targetSkill,
                    'refs'  => self::REFS_ROOT . $targetSkill . '/references/',
                ],
            ],
            'lenses' => self::CROSS_CUTTING_LENSES,
        ];
    }

    /**
     * Pick a fallback specialist for the second expert slot when both frameworks map
     * to the same primary skill.
     *
     * Uses the per-primary fallback map; defaults to `persona-compliance-manager` for
     * any unmapped primary skill.
     */
    private function fallbackSpecialist(string $primarySkill): string
    {
        return self::FALLBACK_BY_PRIMARY[$primarySkill] ?? 'persona-compliance-manager';
    }
}
