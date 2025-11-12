<?php

namespace App\Tests\Entity;

use App\Entity\AuditLog;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class AuditLogTest extends TestCase
{
    public function testNewAuditLogHasDefaultValues(): void
    {
        $auditLog = new AuditLog();

        $this->assertNull($auditLog->getId());
        $this->assertNull($auditLog->getUser());
        $this->assertNull($auditLog->getAction());
        $this->assertNull($auditLog->getEntityType());
        $this->assertNull($auditLog->getEntityId());
        $this->assertNull($auditLog->getChanges());
        $this->assertNull($auditLog->getIpAddress());
        $this->assertNull($auditLog->getUserAgent());
        $this->assertInstanceOf(\DateTimeImmutable::class, $auditLog->getTimestamp());
    }

    public function testSetAndGetUser(): void
    {
        $auditLog = new AuditLog();
        $user = new User();
        $user->setEmail('test@example.com');

        $auditLog->setUser($user);

        $this->assertSame($user, $auditLog->getUser());
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

    public function testSetAndGetChanges(): void
    {
        $auditLog = new AuditLog();
        $changes = ['status' => ['old' => 'pending', 'new' => 'approved']];

        $auditLog->setChanges($changes);

        $this->assertEquals($changes, $auditLog->getChanges());
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

    public function testSetTimestamp(): void
    {
        $auditLog = new AuditLog();
        $timestamp = new \DateTimeImmutable('2024-01-15 10:30:00');

        $auditLog->setTimestamp($timestamp);

        $this->assertEquals($timestamp, $auditLog->getTimestamp());
    }

    public function testConstructorSetsTimestamp(): void
    {
        $before = new \DateTimeImmutable();
        $auditLog = new AuditLog();
        $after = new \DateTimeImmutable();

        $timestamp = $auditLog->getTimestamp();

        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }
}
