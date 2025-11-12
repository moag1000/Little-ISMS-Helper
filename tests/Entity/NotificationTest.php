<?php

namespace App\Tests\Entity;

use App\Entity\Notification;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
    public function testNewNotificationHasDefaultValues(): void
    {
        $notification = new Notification();

        $this->assertNull($notification->getId());
        $this->assertNull($notification->getRecipient());
        $this->assertNull($notification->getType());
        $this->assertNull($notification->getTitle());
        $this->assertNull($notification->getMessage());
        $this->assertNull($notification->getRelatedEntityType());
        $this->assertNull($notification->getRelatedEntityId());
        $this->assertFalse($notification->isRead());
        $this->assertInstanceOf(\DateTimeImmutable::class, $notification->getCreatedAt());
        $this->assertNull($notification->getReadAt());
    }

    public function testSetAndGetRecipient(): void
    {
        $notification = new Notification();
        $user = new User();
        $user->setEmail('recipient@example.com');

        $notification->setRecipient($user);

        $this->assertSame($user, $notification->getRecipient());
    }

    public function testSetAndGetType(): void
    {
        $notification = new Notification();
        $notification->setType('risk_alert');

        $this->assertEquals('risk_alert', $notification->getType());
    }

    public function testSetAndGetTitle(): void
    {
        $notification = new Notification();
        $notification->setTitle('High Risk Detected');

        $this->assertEquals('High Risk Detected', $notification->getTitle());
    }

    public function testSetAndGetMessage(): void
    {
        $notification = new Notification();
        $notification->setMessage('A high-risk item requires your attention');

        $this->assertEquals('A high-risk item requires your attention', $notification->getMessage());
    }

    public function testSetAndGetRelatedEntityType(): void
    {
        $notification = new Notification();
        $notification->setRelatedEntityType('Risk');

        $this->assertEquals('Risk', $notification->getRelatedEntityType());
    }

    public function testSetAndGetRelatedEntityId(): void
    {
        $notification = new Notification();
        $notification->setRelatedEntityId(42);

        $this->assertEquals(42, $notification->getRelatedEntityId());
    }

    public function testSetAndGetIsRead(): void
    {
        $notification = new Notification();

        $this->assertFalse($notification->isRead());

        $notification->setIsRead(true);
        $this->assertTrue($notification->isRead());

        $notification->setIsRead(false);
        $this->assertFalse($notification->isRead());
    }

    public function testSetAndGetReadAt(): void
    {
        $notification = new Notification();
        $readAt = new \DateTimeImmutable('2024-01-15 14:30:00');

        $notification->setReadAt($readAt);

        $this->assertEquals($readAt, $notification->getReadAt());
    }

    public function testMarkAsRead(): void
    {
        $notification = new Notification();

        $this->assertFalse($notification->isRead());
        $this->assertNull($notification->getReadAt());

        $notification->setIsRead(true);
        $notification->setReadAt(new \DateTimeImmutable());

        $this->assertTrue($notification->isRead());
        $this->assertInstanceOf(\DateTimeImmutable::class, $notification->getReadAt());
    }

    public function testConstructorSetsCreatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $notification = new Notification();
        $after = new \DateTimeImmutable();

        $createdAt = $notification->getCreatedAt();

        $this->assertGreaterThanOrEqual($before, $createdAt);
        $this->assertLessThanOrEqual($after, $createdAt);
    }
}
