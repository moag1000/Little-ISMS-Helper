<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\NonconformityAutoTaskTipRule;
use App\Entity\AuditFinding;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditFindingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NonconformityAutoTaskTipRule (Sprint-3 Alva-Hint Rule 7).
 *
 * Covers:
 *  - Fires when > 5 open findings without linkedRequirements
 *  - Suppressed when ≤ 5 unlinked findings
 *  - Tier-3 info, GET action, deep-links to the filtered index
 *  - Module: audits
 *  - ROLE_MANAGER required
 */
#[AllowMockObjectsWithoutExpectations]
final class NonconformityAutoTaskTipRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenFewUnlinkedFindings(): void
    {
        $rule = new NonconformityAutoTaskTipRule($this->makeRepo(3));
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsHintDeepLinkedToFilteredIndexWhenManyUnlinked(): void
    {
        $rule = new NonconformityAutoTaskTipRule($this->makeRepo(8));
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.nonconformity_auto_task_tip', $hint->key);
        self::assertSame(3, $hint->priorityTier);
        self::assertSame('info', $hint->variant);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_audit_finding_index', $hint->actionRoute);
        self::assertSame(['focus' => 'nc_unreferenced'], $hint->actionRouteParams);
        self::assertSame('8', $hint->bodyTranslationParams['%count%']);
        self::assertContains('ROLE_MANAGER', $hint->requiredRoles);
        self::assertTrue($hint->dismissible);
    }

    #[Test]
    public function requiresAuditsModule(): void
    {
        $rule = new NonconformityAutoTaskTipRule($this->makeRepo(0));
        self::assertContains('audits', $rule->requiredModules());
    }

    #[Test]
    public function appliesToAuditFindingIndex(): void
    {
        $rule = new NonconformityAutoTaskTipRule($this->makeRepo(0));
        self::assertContains('app_audit_finding_index', $rule->appliesToPages());
    }

    private function makeRepo(int $unreferencedCount): AuditFindingRepository
    {
        $findings = [];
        for ($i = 0; $i < $unreferencedCount; ++$i) {
            $findings[] = new AuditFinding();
        }

        $repo = $this->createMock(AuditFindingRepository::class);
        $repo->method('findOpenWithoutRequirements')->willReturn($findings);

        return $repo;
    }
}
