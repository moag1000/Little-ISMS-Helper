<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Tenant;
use App\Service\Group\HoldingScope;

/**
 * Group view of the location map (Konzernsicht): the sites of the current
 * organisation and all its subsidiaries on one map, read-only, with one
 * summary row per organisation. For ROLE_GROUP_CISO and ROLE_KONZERN_AUDITOR
 * at a holding (the caller checks the role).
 *
 * The tenant filter limits every query to the user's own organisation, so the
 * read runs through {@see HoldingScope::read()}; the organisations come from the caller's own
 * subtree (TenantContext::getAccessibleTenants()), never from the request.
 * Rows carry only what the map shows: no owners, no record links.
 */
class LocationGroupView
{
    /** Fields of a posture row the group map shows. */
    private const array FIELDS = [
        'id', 'name', 'type', 'area', 'city', 'country', 'lat', 'lng', 'geoSource', 'protection',
        'score', 'riskBand', 'gapBand', 'gaps', 'checksApplicable', 'checksRate', 'openIncidents', 'active',
    ];

    public function __construct(
        private readonly HoldingScope $holdingScope,
        private readonly LocationPostureService $posture,
    ) {
    }

    /**
     * @param list<Tenant> $tenants the current organisation first, then its subsidiaries
     * @return array{sites: list<array<string, mixed>>, companies: list<array<string, mixed>>}
     */
    public function build(array $tenants): array
    {
        $sites = $companies = [];
        $this->holdingScope->read(function () use ($tenants, &$sites, &$companies): void {
            foreach ($tenants as $tenant) {
                $rows = array_values(array_filter(
                    $this->posture->forTenant($tenant),
                    static fn (array $s): bool => $s['active'],
                ));
                foreach ($rows as $row) {
                    $site = array_intersect_key($row, array_flip(self::FIELDS));
                    $site['tenantId'] = $tenant->getId();
                    $site['tenantName'] = (string) $tenant->getName();
                    $site['bands'] = ['risk' => $row['riskBand'], 'gaps' => $row['gapBand']];
                    $site['mine'] = false;
                    $sites[] = $site;
                }
                $applicable = array_sum(array_column($rows, 'checksApplicable'));
                $gaps = array_sum(array_map(static fn (array $s): int => count($s['gaps']), $rows));
                $companies[] = [
                    'id' => $tenant->getId(),
                    'name' => (string) $tenant->getName(),
                    'sites' => count($rows),
                    'checksRate' => $applicable > 0 ? (int) round(100 * ($applicable - $gaps) / $applicable) : null,
                    'gaps' => $gaps,
                    'critical' => count(array_filter($rows, static fn (array $s): bool => $s['riskBand'] === 'critical')),
                ];
            }
        });

        return ['sites' => $sites, 'companies' => $companies];
    }
}
