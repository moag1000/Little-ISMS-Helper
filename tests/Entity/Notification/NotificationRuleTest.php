<?php

declare(strict_types=1);

namespace App\Tests\Entity\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationRuleTest extends TestCase
{
    #[Test]
    public function testDefaultValues(): void
    {
        $rule = new NotificationRule();

        self::assertSame('', $rule->getName());
        self::assertSame('', $rule->getEventType());
        self::assertSame([], $rule->getConditions());
        self::assertNull($rule->getSeverityFilter());
        self::assertTrue($rule->isActive());
        self::assertSame(0, $rule->getEvaluationCount());
        self::assertNull($rule->getLastEvaluatedAt());
        self::assertNull($rule->getCreatedBy());
        self::assertNull($rule->getId());
        self::assertCount(0, $rule->getChannels());
    }

    #[Test]
    public function testAccessors(): void
    {
        $tenant  = new Tenant();
        $user    = new User();
        $channel = new NotificationChannel();
        $now     = new DateTimeImmutable();

        $rule = new NotificationRule();
        $rule->setTenant($tenant);
        $rule->setName('CISO Alert');
        $rule->setEventType('data_breach.created');
        $rule->setConditions([['field' => 'severity', 'op' => '>=', 'value' => 'high']]);
        $rule->setSeverityFilter('high');
        $rule->setIsActive(false);
        $rule->setEvaluationCount(42);
        $rule->setLastEvaluatedAt($now);
        $rule->setCreatedBy($user);

        self::assertSame($tenant, $rule->getTenant());
        self::assertSame('CISO Alert', $rule->getName());
        self::assertSame('data_breach.created', $rule->getEventType());
        self::assertSame([['field' => 'severity', 'op' => '>=', 'value' => 'high']], $rule->getConditions());
        self::assertSame('high', $rule->getSeverityFilter());
        self::assertFalse($rule->isActive());
        self::assertSame(42, $rule->getEvaluationCount());
        self::assertSame($now, $rule->getLastEvaluatedAt());
        self::assertSame($user, $rule->getCreatedBy());
    }

    #[Test]
    public function testIncrementEvaluationCount(): void
    {
        $rule = new NotificationRule();
        $rule->setEvaluationCount(5);
        $rule->incrementEvaluationCount();
        self::assertSame(6, $rule->getEvaluationCount());
    }

    #[Test]
    public function testAddAndRemoveChannel(): void
    {
        $rule    = new NotificationRule();
        $channel = new NotificationChannel();

        $rule->addChannel($channel);
        self::assertCount(1, $rule->getChannels());

        // Adding same channel twice is idempotent
        $rule->addChannel($channel);
        self::assertCount(1, $rule->getChannels());

        $rule->removeChannel($channel);
        self::assertCount(0, $rule->getChannels());
    }
}
