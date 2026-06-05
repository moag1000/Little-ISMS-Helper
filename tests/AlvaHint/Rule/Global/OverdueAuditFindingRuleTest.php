<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\OverdueAuditFindingRule;
use App\Entity\AuditFinding;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditFindingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hint must deep-link to EXACTLY the findings it counts: one overdue
 * finding → that finding's show page; several → the finding index pre-filtered
 * to focus=overdue.
 */
#[AllowMockObjectsWithoutExpectations]
final class OverdueAuditFindingRuleTest extends TestCase
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
        $rule = new OverdueAuditFindingRule($this->makeRepo(0));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function singleOverdueDeepLinksToThatFinding(): void
    {
        $rule = new OverdueAuditFindingRule($this->makeRepo(1, firstId: 42));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_audit_finding_show', $hint->actionRoute);
        self::assertSame(['id' => 42], $hint->actionRouteParams);
        self::assertSame('1', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function severalOverdueLinkToFilteredIndex(): void
    {
        $rule = new OverdueAuditFindingRule($this->makeRepo(4));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('app_audit_finding_index', $hint->actionRoute);
        self::assertSame(['focus' => 'overdue'], $hint->actionRouteParams);
        self::assertSame('4', $hint->bodyTranslationParams['%count%']);
        self::assertSame(2, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
        self::assertSame('GET', $hint->actionMethod);
        self::assertContains('ROLE_AUDITOR', $hint->requiredRoles);
        self::assertTrue($hint->dismissible);
    }

    private function makeRepo(int $count, int $firstId = 1): AuditFindingRepository
    {
        $findings = [];
        for ($i = 0; $i < $count; ++$i) {
            $finding = $this->createMock(AuditFinding::class);
            $finding->method('getId')->willReturn($i === 0 ? $firstId : $firstId + $i);
            $findings[] = $finding;
        }

        $repo = $this->createMock(AuditFindingRepository::class);
        $repo->method('findOverdue')->willReturn($findings);

        return $repo;
    }
}
