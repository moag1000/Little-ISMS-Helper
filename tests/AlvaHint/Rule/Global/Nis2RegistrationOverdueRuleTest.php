<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\Nis2RegistrationOverdueRule;
use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Nis2RegistrationOverdueRule.
 *
 * Covers: fires when nextDueAt is in the past, suppressed when not overdue,
 * suppressed when no profile exists, tier-1 non-dismissible, GET action method,
 * module gate, and page scoping.
 */
#[AllowMockObjectsWithoutExpectations]
final class Nis2RegistrationOverdueRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsHintWhenProfileIsOverdue(): void
    {
        $profile = $this->buildProfile();
        $profile->setNextDueAt(new DateTimeImmutable('-10 days'));

        $repo = $this->createMock(Nis2RegistrationProfileRepository::class);
        $repo->method('findForTenant')->willReturn($profile);

        $rule = new Nis2RegistrationOverdueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.nis2_registration_overdue', $hint->key);
        self::assertSame(1, $hint->priorityTier);
        self::assertFalse($hint->dismissible, 'Tier-1 hints must not be dismissible');
        self::assertSame('danger', $hint->variant);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('nis2_registration_index', $hint->actionRoute);
        self::assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
    }

    #[Test]
    public function returnsNullWhenProfileIsNotOverdue(): void
    {
        $profile = $this->buildProfile();
        $profile->setNextDueAt(new DateTimeImmutable('+60 days'));

        $repo = $this->createMock(Nis2RegistrationProfileRepository::class);
        $repo->method('findForTenant')->willReturn($profile);

        $rule = new Nis2RegistrationOverdueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNull($hint);
    }

    #[Test]
    public function returnsNullWhenNoProfileExists(): void
    {
        $repo = $this->createMock(Nis2RegistrationProfileRepository::class);
        $repo->method('findForTenant')->willReturn(null);

        $rule = new Nis2RegistrationOverdueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNull($hint);
    }

    #[Test]
    public function hasCorrectModuleRequirement(): void
    {
        $rule = new Nis2RegistrationOverdueRule(
            $this->createMock(Nis2RegistrationProfileRepository::class)
        );

        self::assertContains('nis2_dora', $rule->requiredModules());
    }

    #[Test]
    public function appliesToDashboardPages(): void
    {
        $rule = new Nis2RegistrationOverdueRule(
            $this->createMock(Nis2RegistrationProfileRepository::class)
        );

        $pages = $rule->appliesToPages();

        self::assertContains('dashboard_ciso', $pages);
        self::assertContains('dashboard_compliance_manager', $pages);
        self::assertContains('inbox', $pages);
    }

    #[Test]
    public function bodyTranslationParamsIncludeDate(): void
    {
        $profile = $this->buildProfile();
        $dueDate = new DateTimeImmutable('-5 days');
        $profile->setNextDueAt($dueDate);

        $repo = $this->createMock(Nis2RegistrationProfileRepository::class);
        $repo->method('findForTenant')->willReturn($profile);

        $rule = new Nis2RegistrationOverdueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertArrayHasKey('%date%', $hint->bodyTranslationParams);
        self::assertSame($dueDate->format('d.m.Y'), $hint->bodyTranslationParams['%date%']);
    }

    private function buildProfile(): Nis2RegistrationProfile
    {
        $profile = new Nis2RegistrationProfile();
        return $profile;
    }
}
