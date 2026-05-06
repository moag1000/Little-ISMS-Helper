<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\Export\LksgAnnualReportExporter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LksgAnnualReportExporterTest extends TestCase
{
    #[Test]
    public function csvHeaderAndRowsRenderForReportingObligationSuppliers(): void
    {
        $tenant = new Tenant();
        $supplier = $this->buildSupplier(7, 'Acme Logistics', 'high', 80, 65);

        $repo = $this->createMock(SupplierRepository::class);
        $repo->expects($this->once())
            ->method('findLksgRelevantSuppliers')
            ->with($tenant, null)
            ->willReturn([$supplier]);

        $exporter = new LksgAnnualReportExporter($repo);
        $csv = $exporter->export($tenant);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('supplier_id,name,country_of_head_office', $csv);
        $this->assertStringContainsString('7,"Acme Logistics"', $csv);
        $this->assertStringContainsString(',high,80,65,80,', $csv); // aggregate = max(80,65)
    }

    #[Test]
    public function minimumRiskFilterPropagatedToRepository(): void
    {
        $tenant = new Tenant();

        $repo = $this->createMock(SupplierRepository::class);
        $repo->expects($this->once())
            ->method('findLksgRelevantSuppliers')
            ->with($tenant, 'high')
            ->willReturn([]);

        $exporter = new LksgAnnualReportExporter($repo);
        $csv = $exporter->export($tenant, 'high');

        $this->assertStringContainsString('supplier_id', $csv);
    }

    private function buildSupplier(int $id, string $name, string $cat, int $hr, int $env): Supplier
    {
        $supplier = new Supplier();
        $reflection = new \ReflectionClass($supplier);
        $reflection->getProperty('id')->setValue($supplier, $id);
        $supplier->setName($name);
        $supplier->setLksgRiskCategory($cat);
        $supplier->setLksgHumanRightsRiskScore($hr);
        $supplier->setLksgEnvironmentalRiskScore($env);
        $supplier->setLksgReportingObligation(true);

        return $supplier;
    }
}
