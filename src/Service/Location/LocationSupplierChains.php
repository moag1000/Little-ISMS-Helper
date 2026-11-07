<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\TransferImpactAssessment;
use App\Privacy\GdprTransferZones;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suppliers at their processing locations, with the subcontractor chain and
 * a GDPR transfer zone per link (layer "Drittland", GDPR Art. 28 (4), 44–49).
 *
 * Everything comes from the supplier record: processing locations (fallback:
 * country of the head office), the subcontractor chain (tier, name, country)
 * and the transfer safeguard. A US provider operating in Ireland therefore
 * appears in Ireland. Suppliers marked as not processing personal data
 * (GDPR role "none") and terminated or inactive suppliers are left out.
 *
 * Link band: EU/EEA and adequacy ok; USA (DPF) ok with a safeguard, else warn
 * (certification to verify); other third countries warn with a safeguard
 * (TIA needed) and danger without one. The chain band is the worst link, so a
 * subcontractor in a third country without safeguard marks the whole chain,
 * even when the direct supplier sits in the EU.
 *
 * The map draws one marker per country: every supplier or subcontractor
 * processing there, band = worst chain among them.
 */
class LocationSupplierChains
{
    private const array RANK = ['none' => 0, 'ok' => 1, 'warn' => 2, 'danger' => 3];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationGeocoder $geocoder,
    ) {
    }

    /**
     * @return array{
     *     countries: list<array<string, mixed>>,
     *     suppliers: list<array<string, mixed>>,
     *     unplaced: int,
     *     tia: array<string, array{total: int, assessed: int}>
     * }
     */
    public function forTenant(Tenant $tenant): array
    {
        /** @var list<Supplier> $suppliers */
        $suppliers = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Supplier::class, 's')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status NOT IN (:gone)')
            ->andWhere('s.gdprProcessorStatus IS NULL OR s.gdprProcessorStatus <> :none')
            ->setParameter('tenant', $tenant)
            ->setParameter('gone', ['terminated', 'inactive'])
            ->setParameter('none', 'none')
            ->orderBy('s.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $rows = [];
        $unplaced = 0;
        foreach ($suppliers as $supplier) {
            $row = $this->chain($supplier);
            if ($row['locations'] === []) {
                ++$unplaced;
                continue;
            }
            $rows[] = $row;
        }

        return [
            'countries' => $this->countries($rows),
            'suppliers' => $rows,
            'unplaced' => $unplaced,
            'tia' => $this->tia($tenant),
        ];
    }

    /**
     * One supplier with its own locations and chain links, each classified.
     *
     * @return array<string, mixed>
     */
    public function chain(Supplier $supplier): array
    {
        $safeguarded = in_array($supplier->getTransferSafeguards(), GdprTransferZones::SAFEGUARDS, true)
            || trim((string) $supplier->getGdprTransferMechanism()) !== '';

        $locations = [];
        foreach ($supplier->getProcessingLocations() ?? [] as $entry) {
            if (is_string($entry) && ($code = $this->code($entry)) !== null) {
                $locations[$code] = $this->link($code, $safeguarded);
            }
        }
        if ($locations === [] && ($code = $this->code($supplier->getCountryOfHeadOffice())) !== null) {
            $locations[$code] = $this->link($code, $safeguarded);
        }

        $chain = [];
        foreach ($supplier->getSubcontractorChain() as $entry) {
            if (!is_array($entry) || trim((string) ($entry['name'] ?? '')) === '') {
                continue;
            }
            $code = $this->code(isset($entry['country']) ? (string) $entry['country'] : null);
            $chain[] = [
                'tier' => max(1, (int) ($entry['tier'] ?? 1)),
                'name' => trim((string) $entry['name']),
                'service' => trim((string) ($entry['service'] ?? '')) ?: null,
            ] + ($code !== null ? $this->link($code, $safeguarded) : ['code' => null, 'zone' => GdprTransferZones::UNKNOWN, 'band' => 'none']);
        }
        usort($chain, static fn (array $a, array $b): int => $a['tier'] <=> $b['tier']);

        $band = 'none';
        foreach (array_merge(array_values($locations), $chain) as $link) {
            $band = self::RANK[$link['band']] > self::RANK[$band] ? $link['band'] : $band;
        }

        return [
            'id' => $supplier->getId(),
            'name' => (string) $supplier->getName(),
            'safeguard' => $supplier->getTransferSafeguards() ?: ($supplier->getGdprTransferMechanism() ?: null),
            'locations' => array_values($locations),
            'chain' => $chain,
            'band' => $band,
        ];
    }

    /**
     * @return array{code: string, zone: string, band: string}
     */
    private function link(string $code, bool $safeguarded): array
    {
        $zone = $code === LocationGeocoder::AREA_EU ? GdprTransferZones::EEA : GdprTransferZones::zoneOf($code);

        return [
            'code' => $code,
            'zone' => $zone,
            'band' => match ($zone) {
                GdprTransferZones::EEA, GdprTransferZones::ADEQUACY => 'ok',
                GdprTransferZones::DPF => $safeguarded ? 'ok' : 'warn',
                GdprTransferZones::THIRD => $safeguarded ? 'warn' : 'danger',
                default => 'none',
            },
        ];
    }

    /**
     * ISO alpha-2 of a free-text country, or "EU" for "EU"/"EWR"/"Europa"
     * (shown at the EU marker). Worldwide or unreadable entries have no place.
     */
    private function code(?string $country): ?string
    {
        $area = $this->geocoder->area($country);

        return $area === LocationGeocoder::AREA_WORLD ? null : $area;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function countries(array $rows): array
    {
        $countries = [];
        foreach ($rows as $row) {
            $entries = [];
            foreach ($row['locations'] as $link) {
                $entries[$link['code']][] = ['supplier' => $row['id'], 'name' => $row['name'], 'tier' => 0];
            }
            foreach ($row['chain'] as $link) {
                if ($link['code'] !== null) {
                    $entries[$link['code']][] = ['supplier' => $row['id'], 'name' => $link['name'], 'tier' => $link['tier']];
                }
            }
            foreach ($entries as $code => $list) {
                $countries[$code] ??= [
                    'code' => $code,
                    'zone' => $code === LocationGeocoder::AREA_EU ? GdprTransferZones::EEA : GdprTransferZones::zoneOf($code),
                    'band' => 'none',
                    'entries' => [],
                ];
                $countries[$code]['entries'] = array_merge($countries[$code]['entries'], $list);
                if (self::RANK[$row['band']] > self::RANK[$countries[$code]['band']]) {
                    $countries[$code]['band'] = $row['band'];
                }
            }
        }

        $out = [];
        foreach ($countries as $code => $country) {
            $point = $this->geocoder->locateArea($code);
            if ($point === null) {
                continue;
            }
            $out[] = $country + ['lat' => $point->latitude, 'lng' => $point->longitude];
        }
        usort($out, static fn (array $a, array $b): int => [self::RANK[$b['band']], $a['code']] <=> [self::RANK[$a['band']], $b['code']]);

        return $out;
    }

    /**
     * Transfer impact assessments per destination country.
     *
     * @return array<string, array{total: int, assessed: int}>
     */
    private function tia(Tenant $tenant): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('t.destinationCountry AS country', 't.status AS status')
            ->from(TransferImpactAssessment::class, 't')
            ->where('t.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $code = $this->code($row['country']);
            if ($code === null) {
                continue;
            }
            $out[$code] ??= ['total' => 0, 'assessed' => 0];
            ++$out[$code]['total'];
            $out[$code]['assessed'] += $row['status'] === 'assessed' ? 1 : 0;
        }

        return $out;
    }
}
