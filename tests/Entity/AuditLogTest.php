<?php

namespace App\Tests\Entity;

use App\Entity\AuditLog;
use PHPUnit\Framework\TestCase;

class AuditLogTest extends TestCase
{
    public function testNewAuditLogHasDefaultValues(): void
    {
        $auditLog = new AuditLog();

        $this->assertNull($auditLog->getId());
        $this->assertNull($auditLog->getUserName());
        $this->assertNull($auditLog->getAction());
        $this->assertNull($auditLog->getEntityType());
        $this->assertNull($auditLog->getEntityId());
        $this->assertNull($auditLog->getOldValues());
        $this->assertNull($auditLog->getNewValues());
        $this->assertNull($auditLog->getDescription());
        $this->assertNull($auditLog->getIpAddress());
        $this->assertNull($auditLog->getUserAgent());
        $this->assertInstanceOf(\DateTimeImmutable::class, $auditLog->getCreatedAt());
    }

    public function testSetAndGetUserName(): void
    {
        $auditLog = new AuditLog();
        $auditLog->setUserName('test@example.com');

        $this->assertEquals('test@example.com', $auditLog->getUserName());
    }

    public function testSetAndGetAction(): void
    {
        $auditLog = new AuditLog();
        $auditLog->setAction('create');

        $this->assertEquals('create', $auditLog->getAction());
    }

    public function testSetAndGetEntityType(): void
    {
        $auditLog = new AuditLog();
        $auditLog->setEntityType('Risk');

        $this->assertEquals('Risk', $auditLog->getEntityType());
    }

    public function testSetAndGetEntityId(): void
    {
        $auditLog = new AuditLog();
        $auditLog->setEntityId(42);

        $this->assertEquals(42, $auditLog->getEntityId());
    }

    public function testSetAndGetOldValues(): void
    {
        $auditLog = new AuditLog();
        $oldValues = json_encode(['status' => 'pending', 'priority' => 'low']);

        $auditLog->setOldValues($oldValues);

        $this->assertEquals($oldValues, $auditLog->getOldValues());
    }

    public function testSetAndGetNewValues(): void
    {
        $auditLog = new AuditLog();
        $newValues = json_encode(['status' => 'approved', 'priority' => 'high']);

        $auditLog->setNewValues($newValues);

        $this->assertEquals($newValues, $auditLog->getNewValues());
    }

    public function testGetOldValuesArrayDecodesJson(): void
    {
        $auditLog = new AuditLog();
        $values = ['status' => 'pending', 'priority' => 'low'];
        $auditLog->setOldValues(json_encode($values));

        $this->assertEquals($values, $auditLog->getOldValuesArray());
    }

    public function testGetNewValuesArrayDecodesJson(): void
    {
        $auditLog = new AuditLog();
        $values = ['status' => 'approved', 'priority' => 'high'];
        $auditLog->setNewValues(json_encode($values));

        $this->assertEquals($values, $auditLog->getNewValuesArray());
    }

    public function testSetAndGetDescription(): void
    {
        $auditLog = new AuditLog();
        $description = 'Risk status changed by admin';

        $auditLog->setDescription($description);

        $this->assertEquals($description, $auditLog->getDescription());
    }

    public function testSetAndGetIpAddress(): void
    {
        $auditLog = new AuditLog();
        $auditLog->setIpAddress('192.168.1.1');

        $this->assertEquals('192.168.1.1', $auditLog->getIpAddress());
    }

    public function testSetAndGetUserAgent(): void
    {
        $auditLog = new AuditLog();
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

        $auditLog->setUserAgent($userAgent);

        $this->assertEquals($userAgent, $auditLog->getUserAgent());
    }

    public function testSetCreatedAt(): void
    {
        $auditLog = new AuditLog();
        $createdAt = new \DateTimeImmutable('2024-01-15 10:30:00');

        $auditLog->setCreatedAt($createdAt);

        $this->assertEquals($createdAt, $auditLog->getCreatedAt());
    }

    public function testConstructorSetsCreatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $auditLog = new AuditLog();
        $after = new \DateTimeImmutable();

        $createdAt = $auditLog->getCreatedAt();

        $this->assertGreaterThanOrEqual($before, $createdAt);
        $this->assertLessThanOrEqual($after, $createdAt);
    }

    public function testCanStoreCompleteAuditTrail(): void
    {
        $auditLog = new AuditLog();

        $auditLog->setUserName('admin@example.com');
        $auditLog->setAction('update');
        $auditLog->setEntityType('Risk');
        $auditLog->setEntityId(42);
        $auditLog->setOldValues(json_encode(['status' => 'identified']));
        $auditLog->setNewValues(json_encode(['status' => 'mitigated']));
        $auditLog->setDescription('Risk mitigation approved');
        $auditLog->setIpAddress('192.168.1.100');
        $auditLog->setUserAgent('Mozilla/5.0');

        $this->assertEquals('admin@example.com', $auditLog->getUserName());
        $this->assertEquals('update', $auditLog->getAction());
        $this->assertEquals('Risk', $auditLog->getEntityType());
        $this->assertEquals(42, $auditLog->getEntityId());
        $this->assertEquals(['status' => 'identified'], $auditLog->getOldValuesArray());
        $this->assertEquals(['status' => 'mitigated'], $auditLog->getNewValuesArray());
        $this->assertEquals('Risk mitigation approved', $auditLog->getDescription());
        $this->assertEquals('192.168.1.100', $auditLog->getIpAddress());
        $this->assertEquals('Mozilla/5.0', $auditLog->getUserAgent());
    }
}
