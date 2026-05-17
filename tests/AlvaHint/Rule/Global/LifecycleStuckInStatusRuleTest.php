<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\LifecycleStuckInStatusRule;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LifecycleStuckInStatusRule.
 *
 * Covers:
 * - Returns null when no stuck documents exist (count = 0)
 * - Returns AlvaHint with correct key/tier/variant when stuck docs found
 * - Hint is dismissible, tier-3, warning variant
 * - Body translation params contain %count% and %days%
 * - Required roles contains ROLE_MANAGER
 * - Action method is GET
 * - key() convention, requiredModules(), appliesToPages()
 */
#[AllowMockObjectsWithoutExpectations]
final class LifecycleStuckInStatusRuleTest extends TestCase
{
    private Tenant $tenant;
    private ?User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenNoStuckDocuments(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(0));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsHintWhenStuckDocumentsExist(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(3));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.lifecycle.stuck_in_status', $hint->key);
    }

    #[Test]
    public function hintIsTier3Warning(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(1));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(3, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
    }

    #[Test]
    public function hintIsDismissible(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(2));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertTrue($hint->dismissible);
    }

    #[Test]
    public function hintBodyParamsContainCountAndDays(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(5));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertArrayHasKey('%count%', $hint->bodyTranslationParams);
        self::assertArrayHasKey('%days%', $hint->bodyTranslationParams);
        self::assertSame('5', $hint->bodyTranslationParams['%count%']);
        self::assertSame('14', $hint->bodyTranslationParams['%days%']);
    }

    #[Test]
    public function hintRequiresManagerRole(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(1));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertContains('ROLE_MANAGER', $hint->requiredRoles);
    }

    #[Test]
    public function hintActionMethodIsGet(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(1));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('GET', $hint->actionMethod);
    }

    #[Test]
    public function keyMatchesConvention(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(0));
        self::assertSame('global.lifecycle.stuck_in_status', $rule->key());
    }

    #[Test]
    public function ruleHasNoRequiredModules(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(0));
        self::assertSame([], $rule->requiredModules());
    }

    #[Test]
    public function ruleAppliesToAllPages(): void
    {
        $rule = new LifecycleStuckInStatusRule($this->makeEm(0));
        self::assertSame([], $rule->appliesToPages());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeEm(int $countResult): EntityManagerInterface
    {
        // Query is not final, so it can be mocked. QueryBuilder::getQuery() returns Query.
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($countResult);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        return $em;
    }
}
