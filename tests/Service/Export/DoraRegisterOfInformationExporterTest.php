<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Entity\Document;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\Export\DoraRegisterOfInformationExporter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DORA ITS Register-of-Information CSV exporter (MINOR-6).
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraRegisterOfInformationExporterTest extends TestCase
{
    private SupplierRepository $repository;
    private DoraRegisterOfInformationExporter $exporter;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SupplierRepository::class);
        $this->exporter = new DoraRegisterOfInformationExporter($this->repository);
        $this->tenant = new Tenant();
        $this->tenant->setName('Test Bank AG');
        $this->tenant->setCode('test-bank');
    }

    public function testCsvBeginsWithUtf8Bom(): void
    {
        $this->repository->method('findByTenant')->willReturn([]);
        $csv = $this->exporter->export($this->tenant);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'CSV must start with UTF-8 BOM.');
    }

    public function testHeaderRowMatchesItsSpec(): void
    {
        $this->repository->method('findByTenant')->willReturn([]);
        $csv = $this->exporter->export($this->tenant);
        $withoutBom = substr($csv, 3);
        $header = strtok($withoutBom, "\n");

        $expected = 'entity_lei,ict_third_party_service_provider_lei,ict_third_party_service_provider_name,'
            . 'nace_code,country_of_head_office,ict_function_type,ict_criticality,substitutability,'
            . 'data_processing_locations,has_subcontractors,subcontractor_chain_depth,subcontractor_chain,'
            . 'has_exit_strategy,exit_strategy_document_ref,last_dora_audit_date,gdpr_processor_status,'
            . 'gdpr_transfer_mechanism,gdpr_av_contract_signed,gdpr_av_contract_date';

        self::assertSame($expected, rtrim((string) $header, "\r"));
        self::assertCount(19, DoraRegisterOfInformationExporter::COLUMNS);
    }

    public function testRowCountMatchesSupplierCount(): void
    {
        $this->repository->method('findByTenant')->willReturn([
            $this->buildSupplierFull(),
            $this->buildSupplierMinimal(),
            $this->buildSupplierEmpty(),
        ]);

        $csv = $this->exporter->export($this->tenant);
        $withoutBom = substr($csv, 3);
        $rows = array_values(array_filter(explode("\n", rtrim($withoutBom, "\n")), static fn(string $r): bool => $r !== ''));

        self::assertCount(4, $rows, 'Expect 1 header + 3 data rows.');
    }

    public function testFieldFormattingRules(): void
    {
        $this->repository->method('findByTenant')->willReturn([$this->buildSupplierFull()]);

        $csv = $this->exporter->export($this->tenant);
        $withoutBom = substr($csv, 3);
        $lines = explode("\n", rtrim($withoutBom, "\n"));
        self::assertCount(2, $lines);

        // Parse data row with fgetcsv-equivalent so quoted fields survive.
        $row = str_getcsv($lines[1], ',', '"', '\\');

        // entity_lei empty (no Tenant::getLeiCode yet).
        self::assertSame('', $row[0]);
        self::assertSame('529900T8BM49AURSDO55', $row[1]); // supplier LEI
        self::assertSame('Acme Cloud Services Ltd', $row[2]);
        self::assertSame('62.01', $row[3]); // nace_code
        self::assertSame('IE', $row[4]); // country_of_head_office
        self::assertSame('Cloud', $row[5]);
        self::assertSame('critical', $row[6]);
        self::assertSame('hard', $row[7]);
        self::assertSame('DE|IE|US', $row[8]); // pipe-joined locations
        self::assertSame('Y', $row[9]); // has_subcontractors
        self::assertSame('2', $row[10]); // subcontractor_chain_depth = count()
        self::assertSame('Sub A|Sub B', $row[11]); // pipe-joined chain
        self::assertSame('Y', $row[12]); // has_exit_strategy
        self::assertSame('2024-11-01', $row[14]); // last_dora_audit_date ISO-8601
        self::assertSame('processor', $row[15]);
        self::assertSame('SCC', $row[16]);
        self::assertSame('Y', $row[17]); // gdpr_av_contract_signed
        self::assertSame('2024-02-15', $row[18]);
    }

    public function testNullsRenderAsEmptyAndBoolsAsN(): void
    {
        $this->repository->method('findByTenant')->willReturn([$this->buildSupplierEmpty()]);

        $csv = $this->exporter->export($this->tenant);
        $lines = explode("\n", rtrim(substr($csv, 3), "\n"));
        $row = str_getcsv($lines[1], ',', '"', '\\');

        self::assertSame('', $row[1]); // no LEI
        self::assertSame('', $row[3]); // no NACE
        self::assertSame('', $row[4]); // no country
        self::assertSame('', $row[8]); // no locations
        self::assertSame('N', $row[9]); // has_subcontractors = false
        self::assertSame('0', $row[10]); // depth = 0
        self::assertSame('', $row[11]); // empty chain
        self::assertSame('N', $row[12]); // has_exit_strategy
        self::assertSame('', $row[14]); // no last audit
        self::assertSame('N', $row[17]); // gdpr_av_contract_signed
    }

    private function buildSupplierFull(): Supplier
    {
        $supplier = new Supplier();
        $supplier->setName('Acme Cloud Services Ltd');
        $supplier->setLeiCode('529900T8BM49AURSDO55');
        $supplier->setNaceCode('62.01');
        $supplier->setCountryOfHeadOffice('IE');
        $supplier->setIctFunctionType('Cloud');
        $supplier->setIctCriticality('critical');
        $supplier->setSubstitutability('hard');
        $supplier->setProcessingLocations(['DE', 'IE', 'US']);
        $supplier->setHasSubcontractors(true);
        $supplier->setSubcontractorChain(['Sub A', 'Sub B']);
        $supplier->setHasExitStrategy(true);
        $supplier->setLastDoraAuditDate(new \DateTimeImmutable('2024-11-01'));
        $supplier->setGdprProcessorStatus('processor');
        $supplier->setGdprTransferMechanism('SCC');
        $supplier->setGdprAvContractSigned(true);
        $supplier->setGdprAvContractDate(new \DateTimeImmutable('2024-02-15'));
        return $supplier;
    }

    private function buildSupplierMinimal(): Supplier
    {
        $supplier = new Supplier();
        $supplier->setName('Small Vendor GmbH');
        $supplier->setLeiCode('5299003DGCJQ7L6U8V17');
        $supplier->setCountryOfHeadOffice('DE');
        $supplier->setIctCriticality('important');
        return $supplier;
    }

    private function buildSupplierEmpty(): Supplier
    {
        $supplier = new Supplier();
        $supplier->setName('Legacy Supplier');
        return $supplier;
    }
}
