<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\MissedSlaDeadlineRule;
use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MissedSlaDeadlineRule.
 *
 * The hint deep-links to the underlying entity when exactly one deadline is
 * missed and that entity type has a known show route; otherwise it falls back
 * to the monitor overview. Never links to a route that may not exist.
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
        $rule = new MissedSlaDeadlineRule($this->makeRepo([]));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function singleMissedIncidentDeepLinksToThatIncident(): void
    {
        $rule = new MissedSlaDeadlineRule($this->makeRepo([['Incident', 21]]));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_incident_show', $hint->actionRoute);
        self::assertSame(['id' => 21], $hint->actionRouteParams);
        self::assertSame(['%count%' => '1'], $hint->bodyTranslationParams);
    }

    #[Test]
    public function singleMissedUnmappedTypeFallsBackToOverview(): void
    {
        $rule = new MissedSlaDeadlineRule($this->makeRepo([['AccessReviewCampaign', 5]]));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('admin_notification_rule_index', $hint->actionRoute);
        self::assertSame([], $hint->actionRouteParams);
    }

    #[Test]
    public function severalMissedFallBackToOverviewTierOneNonDismissible(): void
    {
        $rule = new MissedSlaDeadlineRule($this->makeRepo([
            ['Incident', 1],
            ['Document', 2],
            ['AuditFinding', 3],
        ]));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('admin_notification_rule_index', $hint->actionRoute);
        self::assertSame('global.missed_sla_deadline', $hint->key);
        self::assertSame(1, $hint->priorityTier);
        self::assertFalse($hint->dismissible);
        self::assertSame('danger', $hint->variant);
        self::assertSame('GET', $hint->actionMethod);
        self::assertContains('ROLE_MANAGER', $hint->requiredRoles);
        self::assertSame(['%count%' => '3'], $hint->bodyTranslationParams);
    }

    #[Test]
    public function ruleConventions(): void
    {
        $rule = new MissedSlaDeadlineRule($this->makeRepo([]));
        self::assertSame('global.missed_sla_deadline', $rule->key());
        self::assertSame(1, $rule->priorityTier());
        self::assertEmpty($rule->requiredModules());
        self::assertContains('dashboard_ciso', $rule->appliesToPages());
    }

    /**
     * @param array<int, array{0: string, 1: int}> $monitors [entityType, entityId] pairs
     */
    private function makeRepo(array $monitors): SlaDeadlineMonitorRepository
    {
        $entities = [];
        foreach ($monitors as [$type, $id]) {
            $monitor = $this->createMock(SlaDeadlineMonitor::class);
            $monitor->method('getEntityType')->willReturn($type);
            $monitor->method('getEntityId')->willReturn($id);
            $entities[] = $monitor;
        }

        $repo = $this->createMock(SlaDeadlineMonitorRepository::class);
        $repo->method('findMissedDeadlines')->willReturn($entities);

        return $repo;
    }
}
