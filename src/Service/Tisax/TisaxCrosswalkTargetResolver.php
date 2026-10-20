<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\Tenant;
use App\Repository\TisaxCrosswalkEntryRepository;

/**
 * Resolves the confirmed legacy-id → canonical-id targets used by
 * app:tisax:consolidate: the shared crosswalk fixture map plus, per tenant,
 * the crosswalk confirmed in that tenant's own database.
 *
 * Calling buildConfirmedTargetMap() starts a new run and clears the per-tenant
 * cache, so every command run reads the tenant crosswalks fresh.
 */
final class TisaxCrosswalkTargetResolver
{
    /** @var array<int, array<string, string>> tenant id => confirmed targets (fixture + that tenant's DB crosswalk) */
    private array $tenantTargetCache = [];

    public function __construct(
        private readonly TisaxCrosswalkEntryRepository $crosswalkRepository,
    ) {
    }

    /**
     * Build a map legacy_id → confirmed canonical target id.
     *
     * Only entries that carry an explicit `target` AND are NOT flagged
     * `needs_human_review` are treated as authoritative (no-silent-cap rule:
     * unconfirmed ids must NOT be auto-mapped). Today the shipped fixture has
     * zero confirmed targets — all domain-prefixed/AL3 ids therefore park under
     * legacy_unmapped until a reviewer with the licensed catalogue fills them in.
     *
     * @param array<string, mixed> $crosswalk
     * @return array<string, string>  legacy_id => canonical_id
     */
    public function buildConfirmedTargetMap(array $crosswalk): array
    {
        $this->tenantTargetCache = [];
        $map = [];
        foreach ((array) ($crosswalk['domain_prefixed_entries'] ?? []) as $entry) {
            $this->collectConfirmed($entry, $map);
        }
        // The AL3 (§3/§4) entries live under the same top-level list in YAML.
        // parseFile already merged them into domain_prefixed_entries when they
        // share the key; defensively scan any other list-of-entries keys too.
        foreach ($crosswalk as $key => $value) {
            if (!is_array($value) || $key === 'domain_prefixed_entries') {
                continue;
            }
            if (array_is_list($value)) {
                foreach ($value as $entry) {
                    if (is_array($entry)) {
                        $this->collectConfirmed($entry, $map);
                    }
                }
            }
        }
        // The per-tenant confirmed crosswalk persisted in the DB is merged per
        // tenant in forTenant(), never flat across tenants: it is
        // derived from each tenant's OWN licensed VDA-ISA workbook, so one
        // organisation's confirmations must not drive another's migration.
        return $map;
    }

    /**
     * Confirmed targets for one tenant's rows: the shared fixture map plus the
     * crosswalk confirmed in THAT tenant's database (DB confirmations win).
     * Tenant-less rows only see the fixture map.
     *
     * @param array<string, string> $fixtureTargets
     * @return array<string, string>
     */
    public function forTenant(?Tenant $tenant, array $fixtureTargets): array
    {
        $tenantId = $tenant?->getId();
        if ($tenantId === null) {
            return $fixtureTargets;
        }

        if (!isset($this->tenantTargetCache[$tenantId])) {
            $tenantTargets = [];
            foreach ($this->crosswalkRepository->findBy(['tenant' => $tenant]) as $entry) {
                $tenantTargets[$entry->getLegacyId()] = $entry->getCanonicalId();
            }
            $this->tenantTargetCache[$tenantId] = $tenantTargets + $fixtureTargets;
        }

        return $this->tenantTargetCache[$tenantId];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, string> $map
     */
    private function collectConfirmed(array $entry, array &$map): void
    {
        $legacyId = $entry['legacy_id'] ?? null;
        $target = $entry['target'] ?? null;
        $status = $entry['status'] ?? null;
        if (is_string($legacyId) && is_string($target) && $target !== '' && $status !== 'needs_human_review') {
            $map[$legacyId] = $target;
        }
    }

    /** @param array<string, mixed> $crosswalk */
    public function countNeedsReview(array $crosswalk): int
    {
        $summary = $crosswalk['summary'] ?? [];
        if (is_array($summary) && isset($summary['needs_human_review'])) {
            return (int) $summary['needs_human_review'];
        }
        return 0;
    }
}
