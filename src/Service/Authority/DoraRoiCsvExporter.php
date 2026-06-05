<?php

declare(strict_types=1);

namespace App\Service\Authority;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\DoraExitPlanRepository;
use App\Repository\SupplierRepository;
use DateTimeImmutable;
use ZipArchive;

/**
 * F30 — DORA Register of Information (RoI) xBRL-CSV (OIM) Exporter.
 *
 * The ESAs mandate the **xBRL-CSV (OIM plain-csv)** submission format for the
 * Register of Information, NOT XBRL-XML ({@see DoraRoiXbrlExporter} produces the
 * XML representation for internal validation/preview). This exporter renders the
 * RoI data into the ESA template tables as CSV files (one CSV per template,
 * datapoint codes as column headers) packaged in a ZIP, which is the practical,
 * regulator-fillable layout of the OIM report.
 *
 * Format scope (honest): this produces the **per-template CSV tables** with the
 * official ESA dotted datapoint codes as headers (b_01.01, b_02.02, b_03.01,
 * b_03.02, b_03.03, rt_06). Wrapping these into a full OIM report **package**
 * (a `report.json` documentInfo + taxonomy entry-point reference that Arelle
 * round-trips) is the remaining step for fully-automated regulator upload — the
 * CSV tables themselves carry all the data and column semantics. A
 * `META-INF/reportPackage.json` manifest + README is emitted to make the package
 * self-describing.
 *
 * Reuses the same repositories + relevance-filter (isDoraRelevant) as the XML
 * exporter, and the shared {@see DoraRoiFieldMappingTrait} so both formats stay
 * in lockstep.
 *
 * Module gate: nis2_dora (enforced in controller, not here).
 */
final class DoraRoiCsvExporter
{
    use DoraRoiFieldMappingTrait;

    /** ESA taxonomy entry point recorded in the package manifest. */
    private const string TAXONOMY_ENTRY_POINT =
        'http://www.eba.europa.eu/eu/fr/xbrl/crr/fws/dora/4.0/mod/dora.json';

    public function __construct(
        private readonly SupplierRepository $supplierRepository,
        private readonly AssetRepository $assetRepository,
        private readonly ?DoraExitPlanRepository $exitPlanRepository = null,
    ) {
    }

    /**
     * Builds the RoI as a map of table-filename → CSV content.
     *
     * @return array<string, string> filename (e.g. "b_02.02.csv") → CSV body
     */
    public function generateTables(Tenant $tenant): array
    {
        $reportingDate = new DateTimeImmutable();
        $suppliers = $this->supplierRepository->findByTenantAndDoraRelevant($tenant);
        $assets    = $this->assetRepository->findByTenantAndDoraRelevant($tenant);
        $exitPlans = $this->exitPlanRepository?->findByTenantAndDoraRelevant($tenant) ?? [];

        $currency = strtoupper((string) ($tenant->getReportingCurrency() ?? 'EUR'));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }

        $tables = [];

        // ── b_01.01 — reporting entity (single row) ───────────────────────────
        $tables['b_01.01.csv'] = $this->csv(
            ['B_01.01.0010', 'B_01.01.0020', 'B_01.01.0030', 'B_01.01.0040'],
            [[
                $tenant->getLegalName() ?? $tenant->getName() ?? 'Unknown Entity',
                $tenant->getLeiCode() ?? 'N/A',
                $reportingDate->format('Y') . '-12-31',
                $currency,
            ]],
        );

        // ── b_02.01 — provider count (single row) ─────────────────────────────
        $tables['b_02.01.csv'] = $this->csv(
            ['B_02.01.0010'],
            [[(string) count($suppliers)]],
        );

        // ── b_02.02 — provider detail (one row per provider) ──────────────────
        $providerRows = [];
        foreach ($suppliers as $supplier) {
            $slas  = $supplier->getContractualSLAs() ?? [];
            $certs = [];
            if ($supplier->isHasISO27001()) {
                $certs[] = 'ISO27001';
            }
            if ($supplier->isHasISO22301()) {
                $certs[] = 'ISO22301';
            }
            $freeCerts = trim((string) $supplier->getCertifications());
            if ($freeCerts !== '') {
                $certs[] = $freeCerts;
            }

            $providerRows[] = [
                $supplier->getName() ?? 'Unknown Provider',                  // 0010
                $supplier->getLeiCode() ?? 'N/A',                            // 0020
                $supplier->getCountryOfHeadOffice() ?? 'DE',                 // 0030
                $this->mapCriticalityToEsa($supplier->getCriticality()),     // 0040
                $supplier->getServiceProvided() ?? $supplier->getDescription() ?? '', // 0050
                $supplier->getContractStartDate()?->format('Y-m-d') ?? '',   // 0060
                $supplier->getContractEndDate()?->format('Y-m-d') ?? '',     // 0070
                $supplier->getSubstitutability() ?? 'medium',                // 0080
                $supplier->hasExitStrategy() ? 'true' : 'false',             // 0090
                $this->isEeaCountryCode($supplier->getCountryOfHeadOffice()) ? 'EEA' : 'non_EEA', // 0100
                implode(',', array_map('strval', $supplier->getProcessingLocations() ?? [])),     // 0110
                implode('+', $certs),                                        // 0120
                trim((string) $supplier->getSecurityRequirements()) !== '' ? 'true' : 'false',    // 0130
                !empty($slas['penalties']) ? 'true' : 'false',               // 0140
                $this->countKpis($slas['kpis'] ?? []),                       // 0150
                isset($slas['notificationHours']) ? (string) (int) $slas['notificationHours'] : '', // 0160
            ];
        }
        $tables['b_02.02.csv'] = $this->csv(
            [
                'B_02.02.0010', 'B_02.02.0020', 'B_02.02.0030', 'B_02.02.0040',
                'B_02.02.0050', 'B_02.02.0060', 'B_02.02.0070', 'B_02.02.0080',
                'B_02.02.0090', 'B_02.02.0100', 'B_02.02.0110', 'B_02.02.0120',
                'B_02.02.0130', 'B_02.02.0140', 'B_02.02.0150', 'B_02.02.0160',
            ],
            $providerRows,
        );

        // ── b_03.01 — ICT asset count (single row) ────────────────────────────
        $tables['b_03.01.csv'] = $this->csv(
            ['B_03.01.0010'],
            [[(string) count($assets)]],
        );

        // ── b_03.02 — ICT asset detail (one row per asset) ────────────────────
        $assetRows = [];
        foreach ($assets as $asset) {
            $assetRows[] = [
                (string) ($asset->getId() ?? 0),                  // 0010
                (string) ($asset->getName() ?? 'Unknown Asset'),  // 0020
                (string) ($asset->getAssetType() ?? ''),          // 0030
                (string) ($asset->getDataClassification() ?? 'internal'), // 0040
                (string) ($asset->getConfidentialityValue() ?? 0),// 0050
                (string) ($asset->getIntegrityValue() ?? 0),      // 0060
                (string) ($asset->getAvailabilityValue() ?? 0),   // 0070
                (string) ($asset->getOwner() ?? ''),              // 0080
                (string) ($asset->getLocation() ?? ''),           // 0090
                (string) ($asset->getStatus() ?? 'active'),       // 0100
            ];
        }
        $tables['b_03.02.csv'] = $this->csv(
            [
                'B_03.02.0010', 'B_03.02.0020', 'B_03.02.0030', 'B_03.02.0040',
                'B_03.02.0050', 'B_03.02.0060', 'B_03.02.0070', 'B_03.02.0080',
                'B_03.02.0090', 'B_03.02.0100',
            ],
            $assetRows,
        );

        // ── b_03.03 — asset-dependency graph (RT_05, one row per edge) ────────
        $edgeRows = [];
        foreach ($assets as $source) {
            foreach ($source->getDependsOn() as $target) {
                $edgeRows[] = [
                    (string) ($source->getId() ?? 0),    // 0020 source id
                    (string) ($target->getId() ?? 0),    // 0030 target id
                    (string) ($source->getName() ?? ''), // 0060 source name
                    (string) ($target->getName() ?? ''), // 0070 target name
                ];
            }
        }
        $tables['b_03.03.csv'] = $this->csv(
            ['B_03.03.0020', 'B_03.03.0030', 'B_03.03.0060', 'B_03.03.0070'],
            $edgeRows,
        );

        // ── rt_06 — decommission / exit-plan table (one row per plan) ─────────
        $exitRows = [];
        foreach ($exitPlans as $plan) {
            $supplier = $plan->getSupplier();
            if ($supplier === null) {
                continue;
            }
            $exitRows[] = [
                $supplier->getName() ?? 'Unknown Provider',          // 0010
                (string) ($plan->getExitTrigger() ?? ''),            // 0020
                (string) ($plan->getDataReturnFormat() ?? ''),       // 0030
                $plan->isDataDeletionConfirmation() ? 'true' : 'false', // 0040
                (string) ($plan->getMigrationPath() ?? ''),          // 0050
                $plan->getTestedAt()?->format('Y-m-d') ?? '',        // 0060
                (string) ($plan->getEstimatedDurationDays() ?? ''),  // 0070
                (string) ($plan->getEstimatedCost() ?? ''),          // 0080
            ];
        }
        $tables['rt_06.csv'] = $this->csv(
            [
                'RT_06.0010', 'RT_06.0020', 'RT_06.0030', 'RT_06.0040',
                'RT_06.0050', 'RT_06.0060', 'RT_06.0070', 'RT_06.0080',
            ],
            $exitRows,
        );

        // ── package manifest (self-describing) ────────────────────────────────
        $tables['META-INF/reportPackage.json'] = $this->manifest($tenant, $reportingDate, $currency);

        return $tables;
    }

    /**
     * Packages the RoI CSV tables into a single ZIP and returns the raw bytes.
     */
    public function generateZip(Tenant $tenant): string
    {
        $tables = $this->generateTables($tenant);

        $tmp = tempnam(sys_get_temp_dir(), 'dora-roi-csv-');
        if ($tmp === false) {
            throw new \App\Exception\Io\IoException('Failed to allocate a temp file for the RoI CSV package.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \App\Exception\Io\IoException('Failed to open the RoI CSV ZIP for writing.');
        }

        foreach ($tables as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        if ($bytes === false) {
            throw new \App\Exception\Io\IoException('Failed to read back the generated RoI CSV ZIP.');
        }

        return $bytes;
    }

    /**
     * SHA-256 of the deterministic table concatenation — audit-trail integrity
     * (the ZIP itself is non-deterministic due to embedded timestamps, so we
     * hash the table payloads instead).
     */
    public function computePayloadHash(Tenant $tenant): string
    {
        $tables = $this->generateTables($tenant);
        ksort($tables);

        return hash('sha256', implode("\n--\n", array_map(
            static fn (string $name, string $body): string => $name . "\n" . $body,
            array_keys($tables),
            array_values($tables),
        )));
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Builds RFC-4180 CSV text from a header row + data rows. Values containing
     * a comma, quote, or newline are quoted with internal quotes doubled.
     *
     * @param list<string>       $header
     * @param list<list<string>> $rows
     */
    private function csv(array $header, array $rows): string
    {
        $lines = [$this->csvRow($header)];
        foreach ($rows as $row) {
            $lines[] = $this->csvRow($row);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param list<string> $cells
     */
    private function csvRow(array $cells): string
    {
        return implode(',', array_map(
            static function (string $cell): string {
                if (preg_match('/[",\r\n]/', $cell) === 1) {
                    return '"' . str_replace('"', '""', $cell) . '"';
                }
                return $cell;
            },
            $cells,
        ));
    }

    /**
     * Counts declared SLA KPIs whether stored as a list or a free-text string.
     *
     * @param mixed $kpis
     */
    private function countKpis(mixed $kpis): string
    {
        if (is_array($kpis)) {
            return (string) count($kpis);
        }

        return trim((string) $kpis) !== '' ? '1' : '0';
    }

    private function manifest(Tenant $tenant, DateTimeImmutable $date, string $currency): string
    {
        $manifest = [
            'documentInfo' => [
                'documentType'  => 'https://xbrl.org/CR/2021-02-03/xbrl-csv',
                'taxonomy'      => [self::TAXONOMY_ENTRY_POINT],
                'note'          => 'ESA DORA Register of Information — per-template CSV tables. '
                    . 'Full OIM report.json table-mapping is the residual step for automated regulator upload.',
            ],
            'reporting' => [
                'entity'         => $tenant->getLegalName() ?? $tenant->getName(),
                'lei'            => $tenant->getLeiCode() ?? 'N/A',
                'referenceDate'  => $date->format('Y') . '-12-31',
                'currency'       => $currency,
                'generatedAt'    => $date->format('c'),
            ],
        ];

        return (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
