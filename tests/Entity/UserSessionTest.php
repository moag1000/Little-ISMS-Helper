<?php

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserSession;
use PHPUnit\Framework\TestCase;

class UserSessionTest extends TestCase
{
    public function testConstructor(): void
    {
        $session = new UserSession();

        $this->assertNotNull($session->getCreatedAt());
        $this->assertNotNull($session->getLastActivityAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->getLastActivityAt());
        $this->assertTrue($session->isActive());
    }

    public function testBasicGettersAndSetters(): void
    {
        $session = new UserSession();

        $user = new User();
        $session->setUser($user);
        $this->assertSame($user, $session->getUser());

        $session->setSessionId('sess_123456');
        $this->assertEquals('sess_123456', $session->getSessionId());

        $session->setIpAddress('192.168.1.1');
        $this->assertEquals('192.168.1.1', $session->getIpAddress());

        $session->setUserAgent('Mozilla/5.0');
        $this->assertEquals('Mozilla/5.0', $session->getUserAgent());
    }

    public function testIsActive(): void
    {
        $session = new UserSession();

        $this->assertTrue($session->isActive());

        $session->setIsActive(false);
        $this->assertFalse($session->isActive());
    }

    public function testTimestamps(): void
    {
        $session = new UserSession();

        $now = new \DateTimeImmutable();
        $session->setCreatedAt($now);
        $this->assertEquals($now, $session->getCreatedAt());

        $session->setLastActivityAt($now);
        $this->assertEquals($now, $session->getLastActivityAt());

        $session->setEndedAt($now);
        $this->assertEquals($now, $session->getEndedAt());
    }

    public function testUpdateActivity(): void
    {
        $session = new UserSession();
        $originalActivity = $session->getLastActivityAt();

        sleep(1); // Ensure time difference
        $session->updateActivity();

        $this->assertGreaterThan(
            $originalActivity->getTimestamp(),
            $session->getLastActivityAt()->getTimestamp()
        );
    }

    public function testEndReason(): void
    {
        $session = new UserSession();

        $this->assertNull($session->getEndReason());

        $session->setEndReason('logout');
        $this->assertEquals('logout', $session->getEndReason());
    }

    public function testTerminatedBy(): void
    {
        $session = new UserSession();

        $this->assertNull($session->getTerminatedBy());

        $session->setTerminatedBy('admin@example.com');
        $this->assertEquals('admin@example.com', $session->getTerminatedBy());
    }

    public function testTerminate(): void
    {
        $session = new UserSession();
        $session->setIsActive(true);

        $this->assertTrue($session->isActive());
        $this->assertNull($session->getEndedAt());
        $this->assertNull($session->getEndReason());

        $session->terminate('forced', 'admin@example.com');

        $this->assertFalse($session->isActive());
        $this->assertNotNull($session->getEndedAt());
        $this->assertEquals('forced', $session->getEndReason());
        $this->assertEquals('admin@example.com', $session->getTerminatedBy());
    }

    public function testTerminateWithDefaultReason(): void
    {
        $session = new UserSession();
        $session->terminate();

        $this->assertFalse($session->isActive());
        $this->assertEquals('forced', $session->getEndReason());
        $this->assertNull($session->getTerminatedBy());
    }

    public function testIsExpiredWhenInactive(): void
    {
        $session = new UserSession();
        $session->setIsActive(false);

        $this->assertTrue($session->isExpired());
    }

    public function testIsExpiredWithRecentActivity(): void
    {
        $session = new UserSession();
        $session->setIsActive(true);
        $session->setLastActivityAt(new \DateTimeImmutable());

        $this->assertFalse($session->isExpired(3600)); // 1 hour timeout
    }

    public function testIsExpiredWithOldActivity(): void
    {
        $session = new UserSession();
        $session->setIsActive(true);
        $oldTime = (new \DateTimeImmutable())->modify('-2 hours');
        $session->setLastActivityAt($oldTime);

        $this->assertTrue($session->isExpired(3600)); // 1 hour timeout
    }

    public function testGetDurationForActiveSession(): void
    {
        $session = new UserSession();
        $createdAt = (new \DateTimeImmutable())->modify('-1 hour');
        $session->setCreatedAt($createdAt);

        $duration = $session->getDuration();
        $this->assertGreaterThanOrEqual(3600, $duration); // At least 1 hour
        $this->assertLessThanOrEqual(3605, $duration); // Allow 5 seconds tolerance
    }

    public function testGetDurationForEndedSession(): void
    {
        $session = new UserSession();
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $endedAt = new \DateTimeImmutable('2024-01-01 11:30:00');
        $session->setCreatedAt($createdAt);
        $session->setEndedAt($endedAt);

        $duration = $session->getDuration();
        $this->assertEquals(5400, $duration); // 1.5 hours = 5400 seconds
    }

    public function testGetFormattedDurationInHours(): void
    {
        $session = new UserSession();
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $endedAt = new \DateTimeImmutable('2024-01-01 12:30:00');
        $session->setCreatedAt($createdAt);
        $session->setEndedAt($endedAt);

        $formatted = $session->getFormattedDuration();
        $this->assertEquals('2h 30m', $formatted);
    }

    public function testGetFormattedDurationInMinutes(): void
    {
        $session = new UserSession();
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $endedAt = new \DateTimeImmutable('2024-01-01 10:45:00');
        $session->setCreatedAt($createdAt);
        $session->setEndedAt($endedAt);

        $formatted = $session->getFormattedDuration();
        $this->assertEquals('45m', $formatted);
    }
}
