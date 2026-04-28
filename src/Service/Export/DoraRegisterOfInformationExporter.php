<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;

/**
 * DORA Register of Information (ROI) CSV exporter.
 *
 * Implements the EBA/EIOPA/ESMA Final Draft ITS on the Register of Information
 * (DORA Art. 28). Produces a supervisory-grade CSV with the exact 19 ITS-
 * mandated columns, RFC 4180 quoting, UTF-8 BOM for Excel, and deterministic
 * field formatting (ISO-8601 dates, Y/N booleans, pipe-joined lists).
 *
 * Tracks MINOR-6 in docs/DATA_REUSE_PLAN_REVIEW_ISB.md.
 */
final class DoraRegisterOfInformationExporter
{
    /**
     * UTF-8 byte-order mark. Written as the first three bytes so Excel detects
     * the encoding and renders umlauts / non-ASCII characters correctly.
     */
    public const string UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * ITS-mandated column order. Do not reorder — the sequence is part of the
     * regulatory contract and is validated byte-exact by the golden-file test.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'entity_lei',
        'ict_third_party_service_provider_lei',
        'ict_third_party_service_provider_name',
        'nace_code',
        'country_of_head_office',
        'ict_function_type',
        'ict_criticality',
        'substitutability',
        'data_processing_locations',
        'has_subcontractors',
        'subcontractor_chain_depth',
        'subcontractor_chain',
        'has_exit_strategy',
        'exit_strategy_document_ref',
        'last_dora_audit_date',
        'gdpr_processor_status',
        'gdpr_transfer_mechanism',
        'gdpr_av_contract_signed',
        'gdpr_av_contract_date',
    ];

    public function __construct(
        private readonly SupplierRepository $supplierRepository,
    ) {}

    /**
     * Build the ITS-conformant CSV body for a tenant's suppliers.
     *
     * @return string CSV with UTF-8 BOM prefix, RFC 4180 quoting, comma separator.
     */
    public function export(Tenant $tenant): string
    {
        $suppliers = $this->supplierRepository->findByTenant($tenant);
        $entityLei = $this->resolveEntityLei($tenant);

        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open in-memory stream for CSV export.');
        }

        fputcsv($handle, self::COLUMNS, ',', '"', '\\');

        foreach ($suppliers as $supplier) {
            fputcsv($handle, array_map([$this, 'sanitizeCsvValue'], $this->buildRow($supplier, $entityLei)), ',', '"', '\\');
        }

        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        if ($body === false) {
            throw new \RuntimeException('Failed to read CSV body from stream.');
        }

        return self::UTF8_BOM . $body;
    }

    /**
     * Resolve the reporting entity's own LEI.
     *
     * TODO(MINOR-6): Tenant entity currently exposes no LEI field. Once the
     * reporting entity's LEI is captured (e.g. Tenant::getLeiCode()), plug it
     * in here. Until then the `entity_lei` column is emitted as an empty
     * string — the ITS allows blank fields but flags them for review.
     */
    private function resolveEntityLei(Tenant $tenant): string
    {
        if (method_exists($tenant, 'getLeiCode')) {
            $lei = $tenant->getLeiCode();
            if (is_string($lei) && $lei !== '') {
                return $lei;
            }
        }
        return '';
    }

    /**
     * @return list<string> One value per column in COLUMNS order.
     */
    private function buildRow(Supplier $supplier, string $entityLei): array
    {
        $chain = $supplier->getSubcontractorChain() ?? [];
        $locations = $supplier->getProcessingLocations() ?? [];
        $exitDoc = $supplier->getExitStrategyDocument();

        return [
            $entityLei,
            $this->nullableString($supplier->getLeiCode()),
            $this->nullableString($supplier->getName()),
            $this->nullableString($supplier->getNaceCode()),
            $this->nullableString($supplier->getCountryOfHeadOffice()),
            $this->nullableString($supplier->getIctFunctionType()),
            $this->nullableString($supplier->getIctCriticality()),
            $this->nullableString($supplier->getSubstitutability()),
            $this->joinPipe($locations),
            $this->boolYn($supplier->hasSubcontractors()),
            (string) count($chain),
            $this->joinPipe($chain),
            $this->boolYn($supplier->hasExitStrategy()),
            $exitDoc !== null ? (string) $exitDoc->getId() : '',
            $this->formatDate($supplier->getLastDoraAuditDate()),
            $this->nullableString($supplier->getGdprProcessorStatus()),
            $this->nullableString($supplier->getGdprTransferMechanism()),
            $this->boolYn($supplier->getGdprAvContractSigned()),
            $this->formatDate($supplier->getGdprAvContractDate()),
        ];
    }

    private function nullableString(?string $value): string
    {
        return $value ?? '';
    }

    private function boolYn(bool $value): string
    {
        return $value ? 'Y' : 'N';
    }

    private function formatDate(?\DateTimeInterface $date): string
    {
        return $date?->format('Y-m-d') ?? '';
    }

    /**
     * @param array<int|string, mixed> $values
     */
    private function joinPipe(array $values): string
    {
        $normalized = array_values(array_filter(
            array_map(
                static fn(mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
                $values,
            ),
            static fn(string $v): bool => $v !== '',
        ));
        return implode('|', $normalized);
    }

    /**
     * Sanitize a CSV cell value to prevent formula injection (OWASP - Injection).
     * Prefixes values starting with =, +, -, @, TAB or CR with a single quote.
     */
    private function sanitizeCsvValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
