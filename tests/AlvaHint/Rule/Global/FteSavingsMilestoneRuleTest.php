<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\FteSavingsMilestoneRule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Fte\FteTrackingMetricRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FteSavingsMilestoneRuleTest extends TestCase
{
    private FteTrackingMetricRepository $metricRepo;
    private FteSavingsMilestoneRule $rule;

    protected function setUp(): void
    {
        $this->metricRepo = $this->createMock(FteTrackingMetricRepository::class);
        $this->rule = new FteSavingsMilestoneRule($this->metricRepo);
    }

    #[Test]
    public function keyIsCorrect(): void
    {
        $this->assertSame('global.fte_savings_milestone', $this->rule->key());
    }

    #[Test]
    public function priorityTierIsThree(): void
    {
        $this->assertSame(3, $this->rule->priorityTier());
    }

    #[Test]
    public function requiredModulesIncludesAnalytics(): void
    {
        $this->assertContains('analytics', $this->rule->requiredModules());
    }

    #[Test]
    public function appliesToCorrectPages(): void
    {
        $pages = $this->rule->appliesToPages();
        $this->assertContains('analytics_fte_index', $pages);
        $this->assertContains('app_dashboard', $pages);
    }

    #[Test]
    public function evaluateReturnsNullWhenBelowThreshold(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $user = $this->createStub(User::class);

        $this->metricRepo->method('getSavingsAggregate')->willReturn(999);

        $hint = $this->rule->evaluate($tenant, $user);

        $this->assertNull($hint);
    }

    #[Test]
    public function evaluateReturnsHintWhenAtThreshold(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $user = $this->createStub(User::class);

        $this->metricRepo->method('getSavingsAggregate')->willReturn(1000);

        $hint = $this->rule->evaluate($tenant, $user);

        $this->assertNotNull($hint);
        $this->assertSame('global.fte_savings_milestone', $hint->key);
        $this->assertSame('alva', $hint->translationDomain);
        $this->assertTrue($hint->dismissible);
        $this->assertSame('analytics_fte_index', $hint->actionRoute);
        $this->assertSame('GET', $hint->actionMethod);
        $this->assertContains('ROLE_MANAGER', $hint->requiredRoles);
    }

    #[Test]
    public function evaluateReturnsHintWithCorrectHoursParams(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $user = $this->createStub(User::class);

        $this->metricRepo->method('getSavingsAggregate')->willReturn(1200);

        $hint = $this->rule->evaluate($tenant, $user);

        $this->assertNotNull($hint);
        $this->assertArrayHasKey('%minutes%', $hint->bodyTranslationParams);
        $this->assertArrayHasKey('%hours%', $hint->bodyTranslationParams);
        $this->assertSame('1200', $hint->bodyTranslationParams['%minutes%']);
        // round(1200/60, 1) = 20.0, PHP casts to '20' in string context
        $this->assertSame((string) round(1200 / 60, 1), $hint->bodyTranslationParams['%hours%']);
    }
}
