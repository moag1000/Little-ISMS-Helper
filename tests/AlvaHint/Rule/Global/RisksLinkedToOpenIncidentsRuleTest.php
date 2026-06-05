<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\RisksLinkedToOpenIncidentsRule;
use App\Entity\Risk;
use App\Entity\RiskIncidentLink;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskIncidentLinkRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hint must deep-link to EXACTLY the risks it counts: one stale risk →
 * that risk's show page; several distinct risks → the risk index pre-filtered
 * to focus=incident_linked. Staleness is enforced by the repository finder.
 */
#[AllowMockObjectsWithoutExpectations]
final class RisksLinkedToOpenIncidentsRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user   = new User();
    }

    #[Test]
    public function returnsNullWhenNoStaleLinks(): void
    {
        $rule = new RisksLinkedToOpenIncidentsRule($this->makeRepo([]));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function singleStaleRiskDeepLinksToThatRisk(): void
    {
        $rule = new RisksLinkedToOpenIncidentsRule($this->makeRepo([55]));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_risk_show', $hint->actionRoute);
        self::assertSame(['id' => 55], $hint->actionRouteParams);
        self::assertSame('1', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function severalDistinctRisksLinkToFilteredIndex(): void
    {
        // Two links to the same risk (7) + one to risk 8 → DISTINCT count 2.
        $rule = new RisksLinkedToOpenIncidentsRule($this->makeRepo([7, 7, 8]));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_risk_index', $hint->actionRoute);
        self::assertSame(['focus' => 'incident_linked'], $hint->actionRouteParams);
        self::assertSame('2', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function ruleMetadataIsCorrect(): void
    {
        $rule = new RisksLinkedToOpenIncidentsRule($this->makeRepo([]));
        self::assertSame('global.risks_linked_to_open_incidents', $rule->key());
        self::assertSame(2, $rule->priorityTier());
        self::assertSame(['risks'], $rule->requiredModules());
        self::assertSame(['app_risk_index'], $rule->appliesToPages());
    }

    /**
     * @param int[] $riskIds one RiskIncidentLink per id (duplicates allowed)
     */
    private function makeRepo(array $riskIds): RiskIncidentLinkRepository
    {
        $links = [];
        foreach ($riskIds as $rid) {
            $risk = $this->createMock(Risk::class);
            $risk->method('getId')->willReturn($rid);
            $link = $this->createMock(RiskIncidentLink::class);
            $link->method('getRisk')->willReturn($risk);
            $links[] = $link;
        }

        $repo = $this->createMock(RiskIncidentLinkRepository::class);
        $repo->method('findStaleLinksToOpenIncidents')->willReturn($links);

        return $repo;
    }
}
