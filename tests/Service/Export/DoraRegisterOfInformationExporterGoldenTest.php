<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\Export\DoraRegisterOfInformationExporter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file test for the DORA ITS Register-of-Information export (MINOR-6).
 *
 * Deterministic fixture → byte-exact comparison with
 * tests/Fixtures/dora/register_of_information_golden.csv. Any drift in column
 * order, formatting rules, BOM, or quoting breaks this test and flags the
 * export as non-ITS-conformant.
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraRegisterOfInformationExporterGoldenTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../../Fixtures/dora/register_of_information_golden.csv';

    public function testGeneratedCsvMatchesGoldenFixtureByteExact(): void
    {
        $repository = $this->createMock(SupplierRepository::class);
        $repository->method('findByTenant')->willReturn($this->buildDeterministicSuppliers());

        $exporter = new DoraRegisterOfInformationExporter($repository);
        $tenant = new Tenant();
        $tenant->setName('Deterministic Test Bank AG');
        $tenant->setCode('golden-bank');

        $generated = $exporter->export($tenant);

        self::assertFileExists(self::FIXTURE_PATH, 'Golden fixture missing: ' . self::FIXTURE_PATH);
        $golden = file_get_contents(self::FIXTURE_PATH);
        self::assertNotFalse($golden);

        self::assertSame($golden, $generated, 'Generated CSV diverges from ITS golden fixture.');
    }

    /**
     * @return list<Supplier>
     */
    private function buildDeterministicSuppliers(): array
    {
        // Supplier 1: fully populated critical ICT provider.
        $s1 = new Supplier();
        $s1->setName('Acme Cloud Services Ltd');
        $s1->setLeiCode('529900T8BM49AURSDO55');
        $s1->setNaceCode('62.01');
        $s1->setCountryOfHeadOffice('IE');
        $s1->setIctFunctionType('Cloud');
        $s1->setIctCriticality('critical');
        $s1->setSubstitutability('hard');
        $s1->setProcessingLocations(['DE', 'IE', 'US']);
        $s1->setHasSubcontractors(true);
        $s1->setSubcontractorChain(['Sub A', 'Sub B']);
        $s1->setHasExitStrategy(true);
        $s1->setLastDoraAuditDate(new \DateTimeImmutable('2024-11-01'));
        $s1->setGdprProcessorStatus('processor');
        $s1->setGdprTransferMechanism('SCC');
        $s1->setGdprAvContractSigned(true);
        $s1->setGdprAvContractDate(new \DateTimeImmutable('2024-02-15'));

        // Supplier 2: minimal but with DORA fields.
        $s2 = new Supplier();
        $s2->setName('Small Vendor GmbH');
        $s2->setLeiCode('5299003DGCJQ7L6U8V17');
        $s2->setNaceCode('62.02');
        $s2->setCountryOfHeadOffice('DE');
        $s2->setIctCriticality('important');
        $s2->setSubstitutability('medium');
        $s2->setGdprProcessorStatus('processor');

        // Supplier 3: no DORA data at all (legacy row).
        $s3 = new Supplier();
        $s3->setName('Legacy Supplier');

        return [$s1, $s2, $s3];
    }
}
