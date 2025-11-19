<?php

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\Patch;
use App\Entity\Tenant;
use App\Entity\Vulnerability;
use PHPUnit\Framework\TestCase;

class PatchTest extends TestCase
{
    public function testConstructor(): void
    {
        $patch = new Patch();

        $this->assertNotNull($patch->getCreatedAt());
        $this->assertNotNull($patch->getReleaseDate());
        $this->assertInstanceOf(\DateTimeImmutable::class, $patch->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $patch->getReleaseDate());
        $this->assertEquals(0, $patch->getAffectedAssets()->count());
        $this->assertEquals([], $patch->getDependencies());
        $this->assertEquals('pending', $patch->getStatus());
    }

    public function testBasicGettersAndSetters(): void
    {
        $patch = new Patch();

        $patch->setPatchId('KB5012345');
        $this->assertEquals('KB5012345', $patch->getPatchId());

        $patch->setTitle('Windows Security Update');
        $this->assertEquals('Windows Security Update', $patch->getTitle());

        $patch->setDescription('Critical security patch for Windows');
        $this->assertEquals('Critical security patch for Windows', $patch->getDescription());

        $patch->setVendor('Microsoft');
        $this->assertEquals('Microsoft', $patch->getVendor());

        $patch->setProduct('Windows 11');
        $this->assertEquals('Windows 11', $patch->getProduct());

        $patch->setVersion('22H2');
        $this->assertEquals('22H2', $patch->getVersion());
    }

    public function testPatchTypeAndPriority(): void
    {
        $patch = new Patch();

        $this->assertEquals('security', $patch->getPatchType()); // Default

        $patch->setPatchType('hotfix');
        $this->assertEquals('hotfix', $patch->getPatchType());

        $this->assertEquals('medium', $patch->getPriority()); // Default

        $patch->setPriority('critical');
        $this->assertEquals('critical', $patch->getPriority());
    }

    public function testVulnerabilityRelationship(): void
    {
        $patch = new Patch();
        $vulnerability = new Vulnerability();

        $this->assertNull($patch->getVulnerability());

        $patch->setVulnerability($vulnerability);
        $this->assertSame($vulnerability, $patch->getVulnerability());
    }

    public function testAddAndRemoveAffectedAsset(): void
    {
        $patch = new Patch();
        $asset = new Asset();

        $this->assertEquals(0, $patch->getAffectedAssets()->count());

        $patch->addAffectedAsset($asset);
        $this->assertEquals(1, $patch->getAffectedAssets()->count());
        $this->assertTrue($patch->getAffectedAssets()->contains($asset));

        $patch->removeAffectedAsset($asset);
        $this->assertEquals(0, $patch->getAffectedAssets()->count());
    }

    public function testStatus(): void
    {
        $patch = new Patch();

        $this->assertEquals('pending', $patch->getStatus());

        $patch->setStatus('deployed');
        $this->assertEquals('deployed', $patch->getStatus());
    }

    public function testDates(): void
    {
        $patch = new Patch();

        $releaseDate = new \DateTimeImmutable('2024-01-15');
        $patch->setReleaseDate($releaseDate);
        $this->assertEquals($releaseDate, $patch->getReleaseDate());

        $deadline = new \DateTimeImmutable('2024-02-15');
        $patch->setDeploymentDeadline($deadline);
        $this->assertEquals($deadline, $patch->getDeploymentDeadline());

        $deployedDate = new \DateTimeImmutable('2024-02-10');
        $patch->setDeployedDate($deployedDate);
        $this->assertEquals($deployedDate, $patch->getDeployedDate());
    }

    public function testResponsiblePerson(): void
    {
        $patch = new Patch();

        $this->assertNull($patch->getResponsiblePerson());

        $patch->setResponsiblePerson('John Doe');
        $this->assertEquals('John Doe', $patch->getResponsiblePerson());
    }

    public function testNotes(): void
    {
        $patch = new Patch();

        $patch->setTestingNotes('Tested in staging environment');
        $this->assertEquals('Tested in staging environment', $patch->getTestingNotes());

        $patch->setDeploymentNotes('Deployed during maintenance window');
        $this->assertEquals('Deployed during maintenance window', $patch->getDeploymentNotes());

        $patch->setRollbackPlan('Uninstall KB5012345');
        $this->assertEquals('Uninstall KB5012345', $patch->getRollbackPlan());
    }

    public function testDowntimeRequirements(): void
    {
        $patch = new Patch();

        $this->assertFalse($patch->isRequiresDowntime()); // Default

        $patch->setRequiresDowntime(true);
        $this->assertTrue($patch->isRequiresDowntime());

        $patch->setEstimatedDowntimeMinutes(30);
        $this->assertEquals(30, $patch->getEstimatedDowntimeMinutes());
    }

    public function testRebootRequirement(): void
    {
        $patch = new Patch();

        $this->assertFalse($patch->isRequiresReboot()); // Default

        $patch->setRequiresReboot(true);
        $this->assertTrue($patch->isRequiresReboot());
    }

    public function testKnownIssues(): void
    {
        $patch = new Patch();

        $this->assertNull($patch->getKnownIssues());

        $patch->setKnownIssues('May cause network connectivity issues');
        $this->assertEquals('May cause network connectivity issues', $patch->getKnownIssues());
    }

    public function testDependencies(): void
    {
        $patch = new Patch();

        $this->assertEquals([], $patch->getDependencies());

        $dependencies = ['KB5010000', 'KB5011000'];
        $patch->setDependencies($dependencies);
        $this->assertEquals($dependencies, $patch->getDependencies());
    }

    public function testUrls(): void
    {
        $patch = new Patch();

        $patch->setDownloadUrl('https://download.microsoft.com/patch.msu');
        $this->assertEquals('https://download.microsoft.com/patch.msu', $patch->getDownloadUrl());

        $patch->setDocumentationUrl('https://support.microsoft.com/kb5012345');
        $this->assertEquals('https://support.microsoft.com/kb5012345', $patch->getDocumentationUrl());
    }

    public function testTenantRelationship(): void
    {
        $patch = new Patch();
        $tenant = new Tenant();

        $this->assertNull($patch->getTenant());

        $patch->setTenant($tenant);
        $this->assertSame($tenant, $patch->getTenant());
    }

    public function testIsOverdueWhenNoDeadline(): void
    {
        $patch = new Patch();
        $patch->setDeploymentDeadline(null);

        $this->assertFalse($patch->isOverdue());
    }

    public function testIsOverdueWhenAlreadyDeployed(): void
    {
        $patch = new Patch();
        $pastDate = (new \DateTimeImmutable())->modify('-5 days');
        $patch->setDeploymentDeadline($pastDate);
        $patch->setStatus('deployed');

        $this->assertFalse($patch->isOverdue());
    }

    public function testIsOverdueWithPastDeadline(): void
    {
        $patch = new Patch();
        $pastDate = (new \DateTimeImmutable())->modify('-5 days');
        $patch->setDeploymentDeadline($pastDate);
        $patch->setStatus('pending');

        $this->assertTrue($patch->isOverdue());
    }

    public function testIsOverdueWithFutureDeadline(): void
    {
        $patch = new Patch();
        $futureDate = (new \DateTimeImmutable())->modify('+5 days');
        $patch->setDeploymentDeadline($futureDate);
        $patch->setStatus('pending');

        $this->assertFalse($patch->isOverdue());
    }

    public function testGetPriorityBadgeClass(): void
    {
        $patch = new Patch();

        $patch->setPriority('critical');
        $this->assertEquals('danger', $patch->getPriorityBadgeClass());

        $patch->setPriority('high');
        $this->assertEquals('warning', $patch->getPriorityBadgeClass());

        $patch->setPriority('medium');
        $this->assertEquals('info', $patch->getPriorityBadgeClass());

        $patch->setPriority('low');
        $this->assertEquals('secondary', $patch->getPriorityBadgeClass());
    }

    public function testCalculateDeploymentDeadline(): void
    {
        $releaseDate = new \DateTimeImmutable('2024-01-01');
        $patch = new Patch();
        $patch->setReleaseDate($releaseDate);

        $patch->setPriority('critical');
        $deadline = $patch->calculateDeploymentDeadline();
        $this->assertEquals('2024-01-04', $deadline->format('Y-m-d')); // +3 days

        $patch->setPriority('high');
        $deadline = $patch->calculateDeploymentDeadline();
        $this->assertEquals('2024-01-08', $deadline->format('Y-m-d')); // +7 days

        $patch->setPriority('medium');
        $deadline = $patch->calculateDeploymentDeadline();
        $this->assertEquals('2024-01-31', $deadline->format('Y-m-d')); // +30 days

        $patch->setPriority('low');
        $deadline = $patch->calculateDeploymentDeadline();
        $this->assertEquals('2024-03-31', $deadline->format('Y-m-d')); // +90 days
    }

    public function testGetDaysUntilDeadlineWhenNoDeadline(): void
    {
        $patch = new Patch();
        $patch->setDeploymentDeadline(null);

        $this->assertNull($patch->getDaysUntilDeadline());
    }

    public function testGetDaysUntilDeadlineWithFutureDeadline(): void
    {
        $patch = new Patch();
        $futureDate = (new \DateTimeImmutable())->modify('+10 days');
        $patch->setDeploymentDeadline($futureDate);

        $days = $patch->getDaysUntilDeadline();
        // Allow for timing differences (9 or 10 days depending on execution time)
        $this->assertGreaterThanOrEqual(9, $days);
        $this->assertLessThanOrEqual(10, $days);
    }

    public function testGetDaysUntilDeadlineWithPastDeadline(): void
    {
        $patch = new Patch();
        $pastDate = (new \DateTimeImmutable())->modify('-5 days');
        $patch->setDeploymentDeadline($pastDate);

        $days = $patch->getDaysUntilDeadline();
        $this->assertEquals(-5, $days);
    }

    public function testTimestamps(): void
    {
        $patch = new Patch();

        // createdAt set in constructor
        $this->assertNotNull($patch->getCreatedAt());

        // updatedAt initially null
        $this->assertNull($patch->getUpdatedAt());

        $now = new \DateTimeImmutable();
        $patch->setUpdatedAt($now);
        $this->assertEquals($now, $patch->getUpdatedAt());
    }
}
