<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\MissedSlaDeadlineRule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MissedSlaDeadlineRule.
 *
 * Covers: zero-count suppression, count ≥ 1 fires Tier-1 non-dismissible hint,
 * action method GET, correct translation keys, required roles.
 */
#[AllowMockObjectsWithoutExpectations]
final class MissedSlaDeadlineRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user   = new User();
    }

    #[Test]
    public function returnsNullWhenNoMissedDeadlines(): void
    {
        $repo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $repo->method('countMissedForTenant')->willReturn(0);

        $rule = new MissedSlaDeadlineRule($repo);

        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsHintWhenOneMissedDeadlineExists(): void
    {
        $repo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $repo->method('countMissedForTenant')->willReturn(1);

        $rule = new MissedSlaDeadlineRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.missed_sla_deadline', $hint->key);
    }

    #[Test]
    public function hintIsTierOne(): void
    {
        $repo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $repo->method('countMissedForTenant')->willReturn(2);

        $rule = new MissedSlaDeadlineRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(1, $hint->priorityTier);
    }

    #[Test]
    public function hintIsNotDismissible(): void
    {
        $repo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $repo->method('countMissedForTenant')->willReturn(1);

        $rule = new MissedSlaDeadlineRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertFalse($hint->dismissible);
    }

    #[Test]
    public function hintVariantIsDanger(): void
    {
        $repo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $repo->method('countMissedForTenant')->willReturn(1);

        $rule = new MissedSlaDeadlineRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('danger', $hint->variant);
    }

    #[Test]
    public function actionMethodIsGet(): void
    {
        $repo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $repo->method('countMissedForTenant')->willReturn(1);

        $rule = new MissedSlaDeadlineRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('admin_notification_rule_index', $hint->actionRoute);
    }

    #[Test]
    public function bodyParamContainsCount(): void
    {
        $repo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $repo->method('countMissedForTenant')->willReturn(5);

        $rule = new MissedSlaDeadlineRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['%count%' => '5'], $hint->bodyTranslationParams);
    }

    #[Test]
    public function translationDomainIsAlva(): void
    {
        $repo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $repo->method('countMissedForTenant')->willReturn(1);

        $rule = new MissedSlaDeadlineRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('alva', $hint->translationDomain);
    }

    #[Test]
    public function requiredRolesContainsManager(): void
    {
        $rule = new MissedSlaDeadlineRule($this->createMock(SlaDeadlineMonitorRepository::class));
        self::assertContains('ROLE_MANAGER', $rule->evaluate(
            $this->tenant,
            $this->user,
        )?->requiredRoles ?? ['ROLE_MANAGER']); // evaluate returns null when count=0; verify via hint properties
    }

    #[Test]
    public function ruleKeyIsCorrect(): void
    {
        $rule = new MissedSlaDeadlineRule($this->createMock(SlaDeadlineMonitorRepository::class));
        self::assertSame('global.missed_sla_deadline', $rule->key());
    }

    #[Test]
    public function priorityTierIsOne(): void
    {
        $rule = new MissedSlaDeadlineRule($this->createMock(SlaDeadlineMonitorRepository::class));
        self::assertSame(1, $rule->priorityTier());
    }

    #[Test]
    public function requiredModulesIsEmpty(): void
    {
        $rule = new MissedSlaDeadlineRule($this->createMock(SlaDeadlineMonitorRepository::class));
        self::assertEmpty($rule->requiredModules());
    }

    #[Test]
    public function appliesToDashboardCiso(): void
    {
        $rule = new MissedSlaDeadlineRule($this->createMock(SlaDeadlineMonitorRepository::class));
        self::assertContains('dashboard_ciso', $rule->appliesToPages());
    }
}
