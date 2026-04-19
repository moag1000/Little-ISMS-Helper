<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\Export\DoraRegisterOfInformationExporter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DORA Register of Information (ROI) CSV importer — symmetric to
 * {@see DoraRegisterOfInformationExporter}.
 *
 * Consumes the same ITS-mandated 19-column format the exporter produces:
 * UTF-8 BOM optional, comma separator, RFC 4180 quoting. Rows are matched
 * against existing Suppliers by `leiCode`; matching rows get patched,
 * unknown LEIs become new Supplier records inside the given Tenant scope.
 *
 * Returns a structured result describing what changed — no silent state
 * mutations. The caller (command or controller) decides whether to flush
 * the EntityManager.
 */
final class DoraRegisterOfInformationImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SupplierRepository $supplierRepository,
    ) {
    }

    /**
     * Parse the CSV + build a dry-run summary. No flush.
     *
     * @return array{
     *     processed: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: list<array{row: int, lei: ?string, message: string}>,
     *     suppliers: list<Supplier>,
     * }
     */
    public function import(string $csv, Tenant $tenant, bool $persist = true): array
    {
        $csv = $this->stripBom($csv);
        $rows = $this->parseCsv($csv);
        if ($rows === []) {
            return $this->emptyResult();
        }

        $header = array_shift($rows);
        $headerMap = $this->headerMap($header);
        $missing = array_diff(DoraRegisterOfInformationExporter::COLUMNS, array_keys($headerMap));
        if ($missing !== []) {
            return [
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [[
                    'row' => 1,
                    'lei' => null,
                    'message' => 'Missing required columns: ' . implode(', ', $missing),
                ]],
                'suppliers' => [],
            ];
        }

        $result = $this->emptyResult();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data = $this->mapRow($row, $headerMap);
            $lei = $data['ict_third_party_service_provider_lei'] ?? '';
            $name = $data['ict_third_party_service_provider_name'] ?? '';

            if ($lei === '' && $name === '') {
                $result['skipped']++;
                continue;
            }

            try {
                $supplier = $this->findOrCreateSupplier($lei, $name, $tenant, $isNew);
                $this->applyRow($supplier, $data);

                if ($persist) {
                    if ($isNew) {
                        $this->entityManager->persist($supplier);
                    }
                }

                $isNew ? $result['created']++ : $result['updated']++;
                $result['suppliers'][] = $supplier;
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'lei' => $lei !== '' ? $lei : null,
                    'message' => $e->getMessage(),
                ];
                $result['skipped']++;
            }
            $result['processed']++;
        }

        return $result;
    }

    private function findOrCreateSupplier(string $lei, string $name, Tenant $tenant, ?bool &$isNew): Supplier
    {
        $isNew = false;
        if ($lei !== '') {
            $existing = $this->supplierRepository->findOneBy([
                'tenant' => $tenant,
                'leiCode' => $lei,
            ]);
            if ($existing instanceof Supplier) {
                return $existing;
            }
        }
        if ($name !== '') {
            $existing = $this->supplierRepository->findOneBy([
                'tenant' => $tenant,
                'name' => $name,
            ]);
            if ($existing instanceof Supplier) {
                return $existing;
            }
        }

        $supplier = new Supplier();
        $supplier->setTenant($tenant);
        if ($name !== '') {
            $supplier->setName($name);
        }
        if ($lei !== '') {
            $supplier->setLeiCode($lei);
        }
        $isNew = true;
        return $supplier;
    }

    /** @param array<string,string> $data */
    private function applyRow(Supplier $supplier, array $data): void
    {
        $map = [
            'ict_third_party_service_provider_lei'  => fn($v) => $v !== '' ? $supplier->setLeiCode($v) : null,
            'ict_third_party_service_provider_name' => fn($v) => $v !== '' ? $supplier->setName($v) : null,
            'nace_code'                             => fn($v) => $supplier->setNaceCode($v !== '' ? $v : null),
            'country_of_head_office'                => fn($v) => $supplier->setCountryOfHeadOffice($v !== '' ? strtoupper($v) : null),
            'ict_function_type'                     => fn($v) => $supplier->setIctFunctionType($v !== '' ? $v : null),
            'ict_criticality'                       => fn($v) => $supplier->setIctCriticality($v !== '' ? $v : null),
            'substitutability'                      => fn($v) => $supplier->setSubstitutability($v !== '' ? $v : null),
            'data_processing_locations'             => fn($v) => $supplier->setProcessingLocations($this->splitPipe($v)),
            'has_subcontractors'                    => fn($v) => $supplier->setHasSubcontractors($this->parseYn($v)),
            'subcontractor_chain'                   => fn($v) => $supplier->setSubcontractorChain($this->splitPipe($v)),
            'has_exit_strategy'                     => fn($v) => $supplier->setHasExitStrategy($this->parseYn($v)),
            'last_dora_audit_date'                  => fn($v) => $supplier->setLastDoraAuditDate($this->parseDate($v)),
            'gdpr_processor_status'                 => fn($v) => $supplier->setGdprProcessorStatus($v !== '' ? $v : null),
            'gdpr_transfer_mechanism'               => fn($v) => $supplier->setGdprTransferMechanism($v !== '' ? $v : null),
            'gdpr_av_contract_signed'               => fn($v) => $supplier->setGdprAvContractSigned($this->parseYn($v)),
            'gdpr_av_contract_date'                 => fn($v) => $supplier->setGdprAvContractDate($this->parseDate($v)),
        ];

        foreach ($map as $col => $fn) {
            if (array_key_exists($col, $data)) {
                $fn($data[$col]);
            }
        }
    }

    /** @return list<list<string>> */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open in-memory stream for CSV parsing.');
        }
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(
                static fn(mixed $v): string => is_string($v) ? $v : (string) $v,
                $row
            );
        }
        fclose($handle);
        return $rows;
    }

    /**
     * @param list<string> $header
     * @return array<string,int>
     */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $key = trim($name);
            if ($key !== '') {
                $map[$key] = $i;
            }
        }
        return $map;
    }

    /**
     * @param list<string> $row
     * @param array<string,int> $headerMap
     * @return array<string,string>
     */
    private function mapRow(array $row, array $headerMap): array
    {
        $out = [];
        foreach ($headerMap as $col => $idx) {
            $out[$col] = trim($row[$idx] ?? '');
        }
        return $out;
    }

    private function stripBom(string $s): string
    {
        if (str_starts_with($s, DoraRegisterOfInformationExporter::UTF8_BOM)) {
            return substr($s, strlen(DoraRegisterOfInformationExporter::UTF8_BOM));
        }
        return $s;
    }

    /** @return list<string> */
    private function splitPipe(string $v): array
    {
        if ($v === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode('|', $v)),
            static fn(string $x): bool => $x !== ''
        ));
    }

    private function parseYn(string $v): bool
    {
        $norm = strtoupper(trim($v));
        return in_array($norm, ['Y', 'YES', 'TRUE', '1'], true);
    }

    private function parseDate(string $v): ?DateTimeImmutable
    {
        if ($v === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($v);
        } catch (\Throwable) {
            return null;
        }
    }

    private function emptyResult(): array
    {
        return [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'suppliers' => [],
        ];
    }
}
