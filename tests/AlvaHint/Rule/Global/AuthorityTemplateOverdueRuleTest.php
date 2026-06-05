<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\AuthorityTemplateOverdueRule;
use App\Entity\DataBreach;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Authority\OverdueAuthorityNotificationResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The overdue-authority-notification hint must deep-link to EXACTLY the breaches
 * it counts. The notification index is the action surface (per-breach export
 * buttons), so the hint targets that index pre-filtered to focus=overdue.
 *
 * The detailed overdue-detection logic lives in (and is tested via)
 * {@see OverdueAuthorityNotificationResolver}.
 */
#[AllowMockObjectsWithoutExpectations]
final class AuthorityTemplateOverdueRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenNoneOverdue(): void
    {
        $rule = new AuthorityTemplateOverdueRule($this->makeResolver(0));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function deepLinksToFilteredNotificationIndexWhenOverdue(): void
    {
        $rule = new AuthorityTemplateOverdueRule($this->makeResolver(3));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.authority_template_overdue', $hint->key);
        self::assertSame('app_authority_notification_index', $hint->actionRoute);
        self::assertSame(['focus' => 'overdue'], $hint->actionRouteParams);
        self::assertSame('3', $hint->bodyTranslationParams['%count%']);
        self::assertSame(2, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
        self::assertSame('GET', $hint->actionMethod);
        self::assertContains('ROLE_DPO', $hint->requiredRoles);
        self::assertSame('eu_authorities', $hint->translationDomain);
    }

    #[Test]
    public function requiresPrivacyAndEuAuthorityReportingModules(): void
    {
        $rule = new AuthorityTemplateOverdueRule($this->makeResolver(0));
        self::assertContains('privacy', $rule->requiredModules());
        self::assertContains('eu_authority_reporting', $rule->requiredModules());
    }

    private function makeResolver(int $overdueCount): OverdueAuthorityNotificationResolver
    {
        $breaches = [];
        for ($i = 0; $i < $overdueCount; ++$i) {
            $breaches[] = $this->createMock(DataBreach::class);
        }

        $resolver = $this->createMock(OverdueAuthorityNotificationResolver::class);
        $resolver->method('findOverdueBreaches')->willReturn($breaches);

        return $resolver;
    }
}
