<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\RisksLinkedToOpenIncidentsRule;
use App\Entity\RiskIncidentLink;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskIncidentLinkRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RisksLinkedToOpenIncidentsRule.
 * Sprint 9B / F16 — 18th Alva-Hint criterion.
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
    public function returnsNullWhenNoLinksToOpenIncidentsExist(): void
    {
        $repo = $this->createMock(RiskIncidentLinkRepository::class);
        $repo->method('findLinksToOpenIncidents')->willReturn([]);

        $rule = new RisksLinkedToOpenIncidentsRule($repo);

        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenAllLinksAreFresh(): void
    {
        $recentLink = new RiskIncidentLink();
        $recentLink->setLinkedAt(new DateTimeImmutable('-5 days')); // under 30-day threshold

        $repo = $this->createMock(RiskIncidentLinkRepository::class);
        $repo->method('findLinksToOpenIncidents')->willReturn([$recentLink]);

        $rule = new RisksLinkedToOpenIncidentsRule($repo);

        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsHintWhenStaleLinksExist(): void
    {
        $staleLink = new RiskIncidentLink();
        $staleLink->setLinkedAt(new DateTimeImmutable('-35 days')); // older than 30 days

        $repo = $this->createMock(RiskIncidentLinkRepository::class);
        $repo->method('findLinksToOpenIncidents')->willReturn([$staleLink]);

        $rule = new RisksLinkedToOpenIncidentsRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.risks_linked_to_open_incidents', $hint->key);
        self::assertSame(2, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_risk_index', $hint->actionRoute);
        self::assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
        self::assertTrue($hint->dismissible);
        self::assertArrayHasKey('%count%', $hint->bodyTranslationParams);
        self::assertSame('1', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function countReflectsOnlyStaleLinks(): void
    {
        $stale1 = new RiskIncidentLink();
        $stale1->setLinkedAt(new DateTimeImmutable('-40 days'));

        $stale2 = new RiskIncidentLink();
        $stale2->setLinkedAt(new DateTimeImmutable('-31 days'));

        $fresh = new RiskIncidentLink();
        $fresh->setLinkedAt(new DateTimeImmutable('-10 days'));

        $repo = $this->createMock(RiskIncidentLinkRepository::class);
        $repo->method('findLinksToOpenIncidents')->willReturn([$stale1, $stale2, $fresh]);

        $rule = new RisksLinkedToOpenIncidentsRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('2', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function ruleMetadataIsCorrect(): void
    {
        $repo = $this->createMock(RiskIncidentLinkRepository::class);
        $rule = new RisksLinkedToOpenIncidentsRule($repo);

        self::assertSame('global.risks_linked_to_open_incidents', $rule->key());
        self::assertSame(2, $rule->priorityTier());
        self::assertSame(['risks'], $rule->requiredModules());
        self::assertSame(['app_risk_index'], $rule->appliesToPages());
    }
}
