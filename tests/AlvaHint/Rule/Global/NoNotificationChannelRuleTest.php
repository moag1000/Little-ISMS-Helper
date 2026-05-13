<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\NoNotificationChannelRule;
use App\Entity\Notification\NotificationChannel;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Notification\NotificationChannelRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NoNotificationChannelRule.
 *
 * Covers: hint fires when 0 active channels, suppressed when ≥1 active,
 * tier, variant, role requirements, and action route.
 */
#[AllowMockObjectsWithoutExpectations]
final class NoNotificationChannelRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user   = new User();
    }

    #[Test]
    public function returnsHintWhenNoActiveChannels(): void
    {
        $repo = $this->createMock(NotificationChannelRepository::class);
        $repo->method('findBy')->willReturn([]);

        $rule = new NoNotificationChannelRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.no_notification_channel', $hint->key);
        self::assertSame(2, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
        self::assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
        self::assertSame('admin_notification_channel_index', $hint->actionRoute);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('alva', $hint->translationDomain);
    }

    #[Test]
    public function returnsNullWhenActiveChannelExists(): void
    {
        $channel = new NotificationChannel();
        $repo    = $this->createMock(NotificationChannelRepository::class);
        $repo->method('findBy')->willReturn([$channel]);

        $rule = new NoNotificationChannelRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNull($hint);
    }

    #[Test]
    public function isRegisteredForAllPages(): void
    {
        $repo = $this->createMock(NotificationChannelRepository::class);
        $rule = new NoNotificationChannelRule($repo);

        self::assertSame([], $rule->appliesToPages());
    }

    #[Test]
    public function requiresNotificationsModule(): void
    {
        $repo = $this->createMock(NotificationChannelRepository::class);
        $rule = new NoNotificationChannelRule($repo);

        self::assertSame(['notifications'], $rule->requiredModules());
    }

    #[Test]
    public function isVersion1(): void
    {
        $repo = $this->createMock(NotificationChannelRepository::class);
        $repo->method('findBy')->willReturn([]);

        $rule = new NoNotificationChannelRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(1, $hint->version);
    }
}
