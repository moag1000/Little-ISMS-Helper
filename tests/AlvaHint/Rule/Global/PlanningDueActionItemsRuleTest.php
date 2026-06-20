<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\PlanningDueActionItemsRule;
use App\Entity\ActionItem;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ActionItemRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PlanningDueActionItemsRule.
 *
 * Covers: null when no due items, hint returned when due items exist,
 * module gating, page scoping, action route + filter param, required roles,
 * count param, dismissibility, and priority tier.
 */
#[AllowMockObjectsWithoutExpectations]
final class PlanningDueActionItemsRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenNoOpenItemsExist(): void
    {
        $repo = $this->createMock(ActionItemRepository::class);
        $repo->method('findOpenByTenant')->willReturn([]);

        $rule = new PlanningDueActionItemsRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenOpenItemsAreAllBeyond14Days(): void
    {
        $item = $this->makeActionItem(new DateTimeImmutable('+30 days'));

        $repo = $this->createMock(ActionItemRepository::class);
        $repo->method('findOpenByTenant')->willReturn([$item]);

        $rule = new PlanningDueActionItemsRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsHintWhenAtLeastOneItemDueWithin14Days(): void
    {
        $due   = $this->makeActionItem(new DateTimeImmutable('+5 days'));
        $later = $this->makeActionItem(new DateTimeImmutable('+30 days'));

        $repo = $this->createMock(ActionItemRepository::class);
        $repo->method('findOpenByTenant')->willReturn([$due, $later]);

        $rule = new PlanningDueActionItemsRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.planning_due_action_items', $hint->key);
    }

    #[Test]
    public function hintCountParamReflectsOnlyDueItems(): void
    {
        $due1  = $this->makeActionItem(new DateTimeImmutable('+1 day'));
        $due2  = $this->makeActionItem(new DateTimeImmutable('+10 days'));
        $later = $this->makeActionItem(new DateTimeImmutable('+20 days'));

        $repo = $this->createMock(ActionItemRepository::class);
        $repo->method('findOpenByTenant')->willReturn([$due1, $due2, $later]);

        $rule = new PlanningDueActionItemsRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['%count%' => '2'], $hint->bodyTranslationParams);
    }

    #[Test]
    public function hintPointsToDueFilterOnActionItemIndex(): void
    {
        $repo = $this->createMock(ActionItemRepository::class);
        $repo->method('findOpenByTenant')->willReturn([$this->makeActionItem(new DateTimeImmutable('+1 day'))]);

        $rule = new PlanningDueActionItemsRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_planning_action_item_index', $hint->actionRoute);
        self::assertSame(['filter' => 'due'], $hint->actionRouteParams);
        self::assertSame('GET', $hint->actionMethod);
    }

    #[Test]
    public function requiresResourcePlanningModule(): void
    {
        $rule = new PlanningDueActionItemsRule($this->createMock(ActionItemRepository::class));
        self::assertSame(['resource_planning'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToPlanningIndexPage(): void
    {
        $rule = new PlanningDueActionItemsRule($this->createMock(ActionItemRepository::class));
        self::assertContains('planning_index', $rule->appliesToPages());
    }

    #[Test]
    public function isPriorityTier2(): void
    {
        $rule = new PlanningDueActionItemsRule($this->createMock(ActionItemRepository::class));
        self::assertSame(2, $rule->priorityTier());
    }

    #[Test]
    public function hintIsDismissible(): void
    {
        $repo = $this->createMock(ActionItemRepository::class);
        $repo->method('findOpenByTenant')->willReturn([$this->makeActionItem(new DateTimeImmutable('+1 day'))]);

        $rule = new PlanningDueActionItemsRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertTrue($hint->dismissible);
    }

    #[Test]
    public function hintRequiresRoleUser(): void
    {
        $repo = $this->createMock(ActionItemRepository::class);
        $repo->method('findOpenByTenant')->willReturn([$this->makeActionItem(new DateTimeImmutable('+1 day'))]);

        $rule = new PlanningDueActionItemsRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['ROLE_USER'], $hint->requiredRoles);
    }

    #[Test]
    public function hintUsesAlvaTranslationDomain(): void
    {
        $repo = $this->createMock(ActionItemRepository::class);
        $repo->method('findOpenByTenant')->willReturn([$this->makeActionItem(new DateTimeImmutable('+1 day'))]);

        $rule = new PlanningDueActionItemsRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame('global.planning_due_action_items.title', $hint->titleTranslationKey);
        self::assertSame('global.planning_due_action_items.body', $hint->bodyTranslationKey);
        self::assertSame('global.planning_due_action_items.action', $hint->actionLabelTranslationKey);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function makeActionItem(DateTimeImmutable $dueDate): ActionItem
    {
        $item = new ActionItem();
        $item->setTitle('Test item');
        $item->setDueDate($dueDate);

        return $item;
    }
}
