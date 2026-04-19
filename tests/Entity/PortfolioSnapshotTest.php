<?php

namespace App\Tests\Entity;

use App\Entity\PortfolioSnapshot;
use App\Entity\Tenant;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Entity-level unit tests for PortfolioSnapshot (CM-3).
 *
 * Multi-tenant + uniqueness-by-(tenant, day, framework, category) is enforced
 * by the database — we cover the setter/getter surface plus the clamping
 * behaviour of the numeric fields here.
 */
class PortfolioSnapshotTest extends TestCase
{
    public function testNewSnapshotHasDefaults(): void
    {
        $snapshot = new PortfolioSnapshot();

        $this->assertNull($snapshot->getId());
        $this->assertSame(0, $snapshot->getFulfillmentPercentage());
        $this->assertSame(0, $snapshot->getRequirementCount());
        $this->assertSame(0, $snapshot->getGapCount());
        $this->assertInstanceOf(DateTimeImmutable::class, $snapshot->getCreatedAt());
    }

    public function testSettersAndGetters(): void
    {
        $tenant = new Tenant();
        $date = new DateTimeImmutable('2026-01-15');

        $snapshot = new PortfolioSnapshot();
        $snapshot->setTenant($tenant);
        $snapshot->setSnapshotDate($date);
        $snapshot->setFrameworkCode('ISO27001');
        $snapshot->setNistCsfCategory('Protect');
        $snapshot->setFulfillmentPercentage(72);
        $snapshot->setRequirementCount(23);
        $snapshot->setGapCount(4);

        $this->assertSame($tenant, $snapshot->getTenant());
        $this->assertEquals($date, $snapshot->getSnapshotDate());
        $this->assertSame('ISO27001', $snapshot->getFrameworkCode());
        $this->assertSame('Protect', $snapshot->getNistCsfCategory());
        $this->assertSame(72, $snapshot->getFulfillmentPercentage());
        $this->assertSame(23, $snapshot->getRequirementCount());
        $this->assertSame(4, $snapshot->getGapCount());
    }

    public function testFulfillmentPercentageIsClampedToRange(): void
    {
        $snapshot = new PortfolioSnapshot();

        $snapshot->setFulfillmentPercentage(-10);
        $this->assertSame(0, $snapshot->getFulfillmentPercentage(), 'Lower bound 0 must be enforced');

        $snapshot->setFulfillmentPercentage(999);
        $this->assertSame(150, $snapshot->getFulfillmentPercentage(), 'Upper bound 150 must be enforced');

        $snapshot->setFulfillmentPercentage(85);
        $this->assertSame(85, $snapshot->getFulfillmentPercentage());
    }

    public function testCountsCannotGoNegative(): void
    {
        $snapshot = new PortfolioSnapshot();

        $snapshot->setRequirementCount(-5);
        $snapshot->setGapCount(-3);

        $this->assertSame(0, $snapshot->getRequirementCount());
        $this->assertSame(0, $snapshot->getGapCount());
    }

    /**
     * The unique-constraint (tenant, date, framework, category) is a database-level
     * guarantee. This test documents the semantic key shape: all four components
     * must be set before persist() is meaningful.
     */
    public function testUniqueKeyShapeIsRepresentedOnEntity(): void
    {
        $snapshot = new PortfolioSnapshot();
        $snapshot->setTenant(new Tenant());
        $snapshot->setSnapshotDate(new DateTimeImmutable('2026-03-01'));
        $snapshot->setFrameworkCode('NIS2');
        $snapshot->setNistCsfCategory('Respond');

        $this->assertNotNull($snapshot->getTenant());
        $this->assertNotNull($snapshot->getSnapshotDate());
        $this->assertNotEmpty($snapshot->getFrameworkCode());
        $this->assertNotEmpty($snapshot->getNistCsfCategory());
    }
}
