<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\OverdueAuthorityReportRule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Authority\AuthorityHubService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OverdueAuthorityReportRule.
 *
 * Covers: overdue fires, no-overdue suppression, tier-1 non-dismissible,
 * module gating, page scoping, required roles.
 */
#[AllowMockObjectsWithoutExpectations]
final class OverdueAuthorityReportRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user   = new User();
    }

    #[Test]
    public function returnsHintWhenOverdueObligationExists(): void
    {
        $hubService = $this->createMock(AuthorityHubService::class);
        $hubService->method('hasOverdueObligation')->willReturn(true);
        $hubService->method('getStatusSummary')->willReturn([
            'current' => 2, 'due_soon' => 0, 'overdue' => 1,
            'available' => 1, 'not_configured' => 0,
        ]);

        $rule = new OverdueAuthorityReportRule($hubService);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.overdue_authority_report', $hint->key);
    }

    #[Test]
    public function returnsNullWhenNoOverdueObligations(): void
    {
        $hubService = $this->createMock(AuthorityHubService::class);
        $hubService->method('hasOverdueObligation')->willReturn(false);

        $rule = new OverdueAuthorityReportRule($hubService);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function hintIsTierOneNonDismissible(): void
    {
        $hubService = $this->createMock(AuthorityHubService::class);
        $hubService->method('hasOverdueObligation')->willReturn(true);
        $hubService->method('getStatusSummary')->willReturn([
            'current' => 0, 'due_soon' => 0, 'overdue' => 2,
            'available' => 0, 'not_configured' => 0,
        ]);

        $rule = new OverdueAuthorityReportRule($hubService);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(1, $hint->priorityTier);
        self::assertFalse($hint->dismissible, 'Tier-1 regulatory hint must be non-dismissible');
    }

    #[Test]
    public function hintActionRouteIsAuthorityHubIndex(): void
    {
        $hubService = $this->createMock(AuthorityHubService::class);
        $hubService->method('hasOverdueObligation')->willReturn(true);
        $hubService->method('getStatusSummary')->willReturn([
            'current' => 0, 'due_soon' => 0, 'overdue' => 1,
            'available' => 0, 'not_configured' => 0,
        ]);

        $rule = new OverdueAuthorityReportRule($hubService);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('authority_hub_index', $hint->actionRoute);
        self::assertSame('GET', $hint->actionMethod);
    }

    #[Test]
    public function hintVariantIsDanger(): void
    {
        $hubService = $this->createMock(AuthorityHubService::class);
        $hubService->method('hasOverdueObligation')->willReturn(true);
        $hubService->method('getStatusSummary')->willReturn([
            'current' => 0, 'due_soon' => 0, 'overdue' => 1,
            'available' => 0, 'not_configured' => 0,
        ]);

        $rule = new OverdueAuthorityReportRule($hubService);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('danger', $hint->variant);
    }

    #[Test]
    public function requiresEuAuthorityReportingModule(): void
    {
        $rule = new OverdueAuthorityReportRule($this->createMock(AuthorityHubService::class));
        self::assertSame(['eu_authority_reporting'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToComplianceDashboardPages(): void
    {
        $rule = new OverdueAuthorityReportRule($this->createMock(AuthorityHubService::class));
        $pages = $rule->appliesToPages();

        self::assertContains('dashboard_ciso', $pages);
        self::assertContains('dashboard_compliance_manager', $pages);
        self::assertContains('inbox', $pages);
    }

    #[Test]
    public function hintBodyContainsOverdueCountParameter(): void
    {
        $hubService = $this->createMock(AuthorityHubService::class);
        $hubService->method('hasOverdueObligation')->willReturn(true);
        $hubService->method('getStatusSummary')->willReturn([
            'current' => 0, 'due_soon' => 0, 'overdue' => 3,
            'available' => 0, 'not_configured' => 0,
        ]);

        $rule = new OverdueAuthorityReportRule($hubService);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['%count%' => '3'], $hint->bodyTranslationParams);
    }
}
