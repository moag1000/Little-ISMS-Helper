<?php

namespace App\Tests\Entity;

use App\Entity\DataBreach;
use App\Entity\Asset;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class DataBreachTest extends TestCase
{
    public function testNewDataBreachHasDefaultValues(): void
    {
        $dataBreach = new DataBreach();

        $this->assertNull($dataBreach->getId());
        $this->assertNull($dataBreach->getTitle());
        $this->assertNull($dataBreach->getDescription());
        $this->assertNull($dataBreach->getBreachDate());
        $this->assertNull($dataBreach->getDiscoveryDate());
        $this->assertNull($dataBreach->getSeverity());
        $this->assertNull($dataBreach->getStatus());
        $this->assertNull($dataBreach->getDataType());
        $this->assertNull($dataBreach->getAffectedRecordsCount());
        $this->assertNull($dataBreach->getReporter());
        $this->assertNull($dataBreach->getResponsiblePerson());
        $this->assertFalse($dataBreach->isNotificationRequired());
        $this->assertNull($dataBreach->getNotificationDate());
        $this->assertInstanceOf(\DateTimeImmutable::class, $dataBreach->getCreatedAt());
        $this->assertNull($dataBreach->getUpdatedAt());
        $this->assertCount(0, $dataBreach->getAffectedAssets());
    }

    public function testSetAndGetTitle(): void
    {
        $dataBreach = new DataBreach();
        $dataBreach->setTitle('Customer Database Exposure');

        $this->assertEquals('Customer Database Exposure', $dataBreach->getTitle());
    }

    public function testSetAndGetDescription(): void
    {
        $dataBreach = new DataBreach();
        $dataBreach->setDescription('Unauthorized access to customer records');

        $this->assertEquals('Unauthorized access to customer records', $dataBreach->getDescription());
    }

    public function testSetAndGetBreachDate(): void
    {
        $dataBreach = new DataBreach();
        $date = new \DateTimeImmutable('2024-01-15');

        $dataBreach->setBreachDate($date);

        $this->assertEquals($date, $dataBreach->getBreachDate());
    }

    public function testSetAndGetDiscoveryDate(): void
    {
        $dataBreach = new DataBreach();
        $date = new \DateTimeImmutable('2024-01-16');

        $dataBreach->setDiscoveryDate($date);

        $this->assertEquals($date, $dataBreach->getDiscoveryDate());
    }

    public function testSetAndGetSeverity(): void
    {
        $dataBreach = new DataBreach();
        $dataBreach->setSeverity('high');

        $this->assertEquals('high', $dataBreach->getSeverity());
    }

    public function testSetAndGetStatus(): void
    {
        $dataBreach = new DataBreach();
        $dataBreach->setStatus('investigating');

        $this->assertEquals('investigating', $dataBreach->getStatus());
    }

    public function testSetAndGetDataType(): void
    {
        $dataBreach = new DataBreach();
        $dataBreach->setDataType('Personal Data');

        $this->assertEquals('Personal Data', $dataBreach->getDataType());
    }

    public function testSetAndGetAffectedRecordsCount(): void
    {
        $dataBreach = new DataBreach();
        $dataBreach->setAffectedRecordsCount(1500);

        $this->assertEquals(1500, $dataBreach->getAffectedRecordsCount());
    }

    public function testSetAndGetReporter(): void
    {
        $dataBreach = new DataBreach();
        $user = new User();
        $user->setEmail('reporter@example.com');

        $dataBreach->setReporter($user);

        $this->assertSame($user, $dataBreach->getReporter());
    }

    public function testSetAndGetResponsiblePerson(): void
    {
        $dataBreach = new DataBreach();
        $user = new User();
        $user->setEmail('responsible@example.com');

        $dataBreach->setResponsiblePerson($user);

        $this->assertSame($user, $dataBreach->getResponsiblePerson());
    }

    public function testSetAndGetNotificationRequired(): void
    {
        $dataBreach = new DataBreach();

        $this->assertFalse($dataBreach->isNotificationRequired());

        $dataBreach->setNotificationRequired(true);
        $this->assertTrue($dataBreach->isNotificationRequired());

        $dataBreach->setNotificationRequired(false);
        $this->assertFalse($dataBreach->isNotificationRequired());
    }

    public function testSetAndGetNotificationDate(): void
    {
        $dataBreach = new DataBreach();
        $date = new \DateTimeImmutable('2024-01-17');

        $dataBreach->setNotificationDate($date);

        $this->assertEquals($date, $dataBreach->getNotificationDate());
    }

    public function testAddAndRemoveAffectedAsset(): void
    {
        $dataBreach = new DataBreach();
        $asset = new Asset();
        $asset->setName('Customer Database');

        $this->assertCount(0, $dataBreach->getAffectedAssets());

        $dataBreach->addAffectedAsset($asset);
        $this->assertCount(1, $dataBreach->getAffectedAssets());
        $this->assertTrue($dataBreach->getAffectedAssets()->contains($asset));

        $dataBreach->removeAffectedAsset($asset);
        $this->assertCount(0, $dataBreach->getAffectedAssets());
        $this->assertFalse($dataBreach->getAffectedAssets()->contains($asset));
    }

    public function testAddAffectedAssetDoesNotDuplicate(): void
    {
        $dataBreach = new DataBreach();
        $asset = new Asset();
        $asset->setName('Customer Database');

        $dataBreach->addAffectedAsset($asset);
        $dataBreach->addAffectedAsset($asset); // Add same asset again

        $this->assertCount(1, $dataBreach->getAffectedAssets());
    }

    public function testSetUpdatedAt(): void
    {
        $dataBreach = new DataBreach();
        $now = new \DateTimeImmutable();

        $dataBreach->setUpdatedAt($now);

        $this->assertEquals($now, $dataBreach->getUpdatedAt());
    }
}
