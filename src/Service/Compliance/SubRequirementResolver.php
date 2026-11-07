<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Catalog\FrameworkCode;
use App\Service\Catalog\RequirementIdPrefixes;

/**
 * Shared resolver for the sub-requirement decomposition pipeline.
 *
 * The decomposition fixtures (fixtures/library/decompositions/decomp_*.json)
 * carry FINE-grained sub-requirement IDs on both the source and target side —
 * finer than the coarse ComplianceRequirement catalogue rows seeded by the
 * Load*RequirementsCommand family. The raw IDs are highly heterogeneous:
 *
 *   - "GDPR-5.1.b"                                         (already prefixed, ≤50)
 *   - "GDPR.Art5(1)(a)-lawfulness"                         (dotted, ≤50)
 *   - "Art.10(2)(a) — data design choices"                (verbose, >50, needs prefix)
 *   - "ISO27001:2022 A.5.1 (Policies for information…)"   (verbose, >50)
 *   - "1.1.1"                                              (bare VDA-ISA chapter)
 *   - "BSIG-§30(1)-Schadensminimierung"                   (German §, prefixed)
 *
 * Both SeedSubRequirementsCommand and ImportSubMappingsCommand MUST agree on the
 * canonical compact requirementId derived from a raw ID, otherwise the importer
 * cannot find the rows the seeder created. That canonicalisation lives here so
 * there is a single source of truth.
 *
 * This is a GLOBAL catalogue concern: ComplianceRequirement / ComplianceMapping
 * carry no tenant_id (only the optional TISAX-BYO uploadTenant, which stays NULL
 * for system-seeded rows). We therefore follow the existing Load*Requirements
 * pattern: requirements are seeded globally, uploadTenant left NULL.
 */
final class SubRequirementResolver
{
    /**
     * Fixture-filename slugs whose letters/digits do not spell the canonical
     * framework code (everything else is matched via FrameworkCode).
     *
     * @var array<string, string>
     */
    private const SLUG_EXCEPTIONS = [
        'ai-act' => 'EU-AI-ACT',
        'cis-v8' => 'CIS-CONTROLS',
        'tkg' => 'TKG-2024',
        'mris' => 'MRIS-v1.5',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    /**
     * Source/target framework codes for a decomposition fixture basename
     * (`decomp_<source>_<target>[_suffix]`), derived from the file name and the
     * canonical code registry. Null when a slug names no registered framework
     * (e.g. historic editions like iso27001-2013); callers skip such files.
     *
     * @return array{source: string, target: string}|null
     */
    public function frameworkPairFor(string $basename): ?array
    {
        $parts = explode('_', $basename);
        if (count($parts) < 3 || $parts[0] !== 'decomp') {
            return null;
        }
        $source = $this->codeForSlug($parts[1]);
        $target = $this->codeForSlug($parts[2]);

        return $source !== null && $target !== null ? ['source' => $source, 'target' => $target] : null;
    }

    private function codeForSlug(string $slug): ?string
    {
        if (isset(self::SLUG_EXCEPTIONS[$slug])) {
            return self::SLUG_EXCEPTIONS[$slug];
        }
        $normalize = static fn (string $v): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $v));
        $wanted = $normalize($slug);
        foreach (FrameworkCode::CANONICAL as $code) {
            if ($normalize($code) === $wanted) {
                return $code;
            }
        }
        foreach (FrameworkCode::ALIASES as $alias => $code) {
            if ($normalize($alias) === $wanted) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Resolve a ComplianceFramework by its catalogue code (with name fallback).
     *
     * @param array<string, ComplianceFramework|null> $cache mutated in place
     */
    public function resolveFramework(string $code, array &$cache): ?ComplianceFramework
    {
        if (!array_key_exists($code, $cache)) {
            $canonical = FrameworkCode::canonicalize($code);
            $cache[$code] = $this->frameworkRepository->findOneBy(['code' => $code])
                ?? ($canonical !== null && $canonical !== $code
                    ? $this->frameworkRepository->findOneBy(['code' => $canonical])
                    : null)
                ?? $this->frameworkRepository->findOneBy(['name' => $code]);
        }

        return $cache[$code];
    }

    /**
     * Compact a raw decomposition ID into a deterministic ≤50-char requirementId.
     *
     * Strategy:
     *   1. Strip a trailing human description ("Art.10(1) — foo bar" → "Art.10(1)").
     *   2. Collapse whitespace.
     *   3. If still > 50 chars, keep the first 41 chars + "~" + a short stable
     *      hash so distinct long IDs never collide and re-runs stay idempotent.
     *
     * The requirementId column is length 50, so this MUST never exceed 50.
     */
    public function compactId(string $rawId): string
    {
        $id = $this->stripDescription($rawId);

        if (mb_strlen($id) <= 50) {
            return $id;
        }

        // Deterministic, collision-resistant truncation.
        $hash = substr(hash('sha256', $rawId), 0, 8);
        $head = mb_substr($id, 0, 41);

        return rtrim($head, " .-_") . '~' . $hash;
    }

    /**
     * Strip the descriptive tail from a verbose raw ID, keeping the structured
     * leading token. Handles the separators seen across the 8 fixture files:
     * em-dash, " - ", " (", " +".
     */
    public function stripDescription(string $rawId): string
    {
        $id = trim($rawId);

        // Em-dash / en-dash separated description.
        foreach (['—', '–'] as $dash) {
            $pos = mb_strpos($id, $dash);
            if ($pos !== false) {
                $id = trim(mb_substr($id, 0, $pos));
            }
        }

        // " - " separated description (DORA style "Art.10(1) - mechanisms…").
        // Only split on a space-hyphen-space to avoid eating hyphenated tokens
        // like "GDPR-5.1" or "Art.20(1)-governance-approval".
        $pos = mb_strpos($id, ' - ');
        if ($pos !== false) {
            $id = trim(mb_substr($id, 0, $pos));
        }

        // " (Description)" or " + …" tail on otherwise-structured IDs such as
        // "ISO27001:2022 A.5.1 (Policies…)" or "A.5.14 + A.6.8".
        // Only trim when the leading token already looks like a clause/article
        // reference, so we don't truncate "GDPR-Art5(1)(a)-lawfulness".
        if (preg_match('/^([A-Za-z0-9.:§()\-\/]+?)\s*\(/', $id, $m) && str_contains($id, ' (')) {
            $id = trim($m[1]);
        }
        $plusPos = mb_strpos($id, ' + ');
        if ($plusPos !== false) {
            $id = trim(mb_substr($id, 0, $plusPos));
        }

        // Normalise internal whitespace runs.
        $id = (string) preg_replace('/\s+/', ' ', $id);

        return $id === '' ? trim($rawId) : $id;
    }

    /**
     * Derive a short human title from the raw ID + rationale.
     */
    public function deriveTitle(string $rawId, string $rationale): string
    {
        // Prefer a descriptive tail in the raw ID itself.
        $stripped = $this->stripDescription($rawId);
        if ($stripped !== trim($rawId)) {
            $tail = trim(mb_substr(trim($rawId), mb_strlen($stripped)));
            $tail = ltrim($tail, " —–-:(");
            $tail = rtrim($tail, " )");
            if ($tail !== '') {
                return mb_substr($tail, 0, 255);
            }
        }

        // Otherwise use the first sentence/clause of the rationale.
        $first = preg_split('/(?<=[.;])\s|;\s/', trim($rationale))[0] ?? $rationale;
        $first = trim((string) $first);
        if ($first !== '') {
            return mb_substr($first, 0, 255);
        }

        return mb_substr($this->compactId($rawId), 0, 255);
    }

    /**
     * Derive the coarse PARENT requirementId for a sub-ID by stripping the
     * finest trailing segment.
     *
     * Examples (raw → compact sub-id → coarse parent):
     *   "GDPR-5.1.b"                  → "GDPR-5.1.b"     → "GDPR-5.1"
     *   "GDPR-5.1"                    → "GDPR-5.1"       → "GDPR-5"
     *   "Art.10(2)(a) — …"           → "Art.10(2)(a)"   → "Art.10(2)"
     *   "Art.10(2)"                  → "Art.10(2)"      → "Art.10"
     *   "A.1.2.3"                    → "A.1.2.3"        → "A.1.2"
     *   "1.1.1"                      → "1.1.1"          → "1.1"
     *   "GDPR.Art5(1)(a)-lawfulness" → …                → "GDPR.Art5(1)(a)"
     *   "BSIG-§30(2)-Nr.1-Risiko"    → …                → "BSIG-§30(2)"
     *
     * Returns null when the ID is already at the coarsest level we can strip
     * (e.g. a bare "Art.5" or "5"); callers then attach to the nearest
     * existing ancestor / minimal stub.
     */
    public function deriveParentId(string $compactId): ?string
    {
        $id = $compactId;

        // 1. Trailing "-word" segment (NIS2/BSIG slugged tails):
        //    "A.5.1-policies-information-security" → "A.5.1"
        //    "Art.20(1)-governance-approval"       → "Art.20(1)"
        //    "GDPR.Art5(1)(a)-lawfulness"          → "GDPR.Art5(1)(a)"
        //    but NOT "GDPR-5.1" (the hyphen there joins the prefix to a number).
        if (preg_match('/^(.*[)\d])-([A-Za-z].*)$/', $id, $m)) {
            return $m[1];
        }

        // 2. Trailing "(x)" parenthetical group:
        //    "Art.10(2)(a)" → "Art.10(2)";  "Art.10(2)" → "Art.10"
        if (preg_match('/^(.*)\([^()]+\)$/', $id, $m) && $m[1] !== '') {
            return rtrim($m[1], '.');
        }

        // 3. Trailing ".segment" (dotted numbering, optionally lettered):
        //    "GDPR-5.1.b" → "GDPR-5.1";  "A.1.2.3" → "A.1.2";  "1.1.1" → "1.1"
        $lastDot = mb_strrpos($id, '.');
        if ($lastDot !== false && $lastDot > 0) {
            $parent = mb_substr($id, 0, $lastDot);
            // Guard against degenerate "A." → "A" with nothing meaningful left;
            // require the parent to still contain a digit or be multi-char.
            if (preg_match('/\d/', $parent) || mb_strlen($parent) > 1) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * Candidate coarse-parent IDs to probe against the existing catalogue,
     * tolerating prefix-scheme drift between fixtures and loaders.
     *
     * @return list<string>
     */
    public function parentCandidateIds(ComplianceFramework $framework, string $parentId): array
    {
        $code = $framework->getCode() ?? '';
        $candidates = [$parentId];

        // Strip a leading Art./§/A. so we can re-prefix in loader style.
        $stripped = preg_replace('/^(Art\.|§)/i', '', $parentId) ?? $parentId;
        $strippedAnnex = preg_replace('/^A\./i', '', $stripped) ?? $stripped;
        // Strip a leading framework-name prefix the fixture may carry inline,
        // e.g. "GDPR-5.1" / "NIS2-Art.20(1)" / "BSIG-§30(2)" / "ISO27018-A.10.1".
        foreach (RequirementIdPrefixes::forFramework($code) as $prefix) {
            foreach (['-', '_', '.', ':'] as $sep) {
                $needle = $prefix . $sep;
                if (str_starts_with($parentId, $needle)) {
                    $candidates[] = substr($parentId, strlen($needle));
                }
            }
        }

        foreach ([$stripped, $strippedAnnex] as $variant) {
            if ($variant !== '' && $variant !== $parentId) {
                $candidates[] = $variant;
            }
        }

        // Re-prefix in loader conventions.
        $cores = array_filter([$parentId, $stripped, $strippedAnnex], static fn (string $v) => $v !== '');
        foreach ($cores as $core) {
            foreach (RequirementIdPrefixes::forFramework($code) as $prefix) {
                $candidates[] = $prefix . '-' . $core;
                $candidates[] = $prefix . '_' . $core;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Resolve an existing coarse parent requirement for a sub-ID, trying each
     * candidate exactly + a LIKE prefix fallback (e.g. fixture "Art.20" vs
     * loader "NIS2-20.1").
     */
    public function findExistingParent(
        ComplianceFramework $framework,
        string $parentId,
    ): ?ComplianceRequirement {
        foreach ($this->parentCandidateIds($framework, $parentId) as $candidate) {
            $hit = $this->requirementRepository->findOneBy([
                'framework' => $framework,
                'requirementId' => $candidate,
            ]);
            if ($hit instanceof ComplianceRequirement) {
                return $hit;
            }
        }

        // Prefix fallback: pick the lexicographically-first requirement whose id
        // starts with the parent core (e.g. parent "Art.20" → "NIS2-20.1").
        foreach ($this->parentCandidateIds($framework, $parentId) as $candidate) {
            $qb = $this->requirementRepository->createQueryBuilder('r')
                ->andWhere('r.framework = :f')
                ->andWhere('r.requirementId LIKE :p')
                ->setParameter('f', $framework)
                ->setParameter('p', $candidate . '.%')
                ->orderBy('r.requirementId', \SortDirection::Ascending)
                ->setMaxResults(1);
            $hit = $qb->getQuery()->getOneOrNullResult();
            if ($hit instanceof ComplianceRequirement) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * Resolve a sub-requirement row previously created by the seeder.
     */
    public function findSubRequirement(
        ComplianceFramework $framework,
        string $rawId,
    ): ?ComplianceRequirement {
        return $this->requirementRepository->findOneBy([
            'framework' => $framework,
            'requirementId' => $this->compactId($rawId),
        ]);
    }
}
