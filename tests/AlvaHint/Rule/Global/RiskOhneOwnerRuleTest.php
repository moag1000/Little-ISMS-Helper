<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\RiskOhneOwnerRule;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hint must deep-link to EXACTLY the risks it counts: one unowned risk →
 * that risk's show page; several → the risk index pre-filtered to focus=no_owner.
 */
#[AllowMockObjectsWithoutExpectations]
final class RiskOhneOwnerRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenAllRisksOwned(): void
    {
        $rule = new RiskOhneOwnerRule($this->makeRepo(0));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function singleUnownedRiskDeepLinksToThatRisk(): void
    {
        $rule = new RiskOhneOwnerRule($this->makeRepo(1, firstId: 12));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_risk_show', $hint->actionRoute);
        self::assertSame(['id' => 12], $hint->actionRouteParams);
        self::assertSame('1', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function severalUnownedRisksLinkToFilteredIndex(): void
    {
        $rule = new RiskOhneOwnerRule($this->makeRepo(5));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_risk_index', $hint->actionRoute);
        self::assertSame(['focus' => 'no_owner'], $hint->actionRouteParams);
        self::assertSame('5', $hint->bodyTranslationParams['%count%']);
        self::assertSame(2, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
        self::assertContains('ROLE_MANAGER', $hint->requiredRoles);
    }

    private function makeRepo(int $count, int $firstId = 1): RiskRepository
    {
        $risks = [];
        for ($i = 0; $i < $count; ++$i) {
            $risk = $this->createMock(Risk::class);
            $risk->method('getId')->willReturn($i === 0 ? $firstId : $firstId + $i);
            $risks[] = $risk;
        }

        $repo = $this->createMock(RiskRepository::class);
        $repo->method('findWithoutOwner')->willReturn($risks);

        return $repo;
    }
}
