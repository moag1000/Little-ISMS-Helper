<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\NonconformityAutoTaskTipRule;
use App\Entity\AuditFinding;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditFindingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NonconformityAutoTaskTipRule (Sprint-3 Alva-Hint Rule 7).
 *
 * Covers:
 *  - Fires when > 5 open findings without linkedRequirements
 *  - Suppressed when ≤ 5 unlinked findings
 *  - Suppressed when all findings have linkedRequirements
 *  - Tier-3 info, GET action, app_audit_finding_index
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
        $findings = $this->buildFindings(3, false);
        $repo = $this->createMock(AuditFindingRepository::class);
        $repo->method('findOpenByTenant')->willReturn($findings);

        $rule = new NonconformityAutoTaskTipRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenAllFindingsLinked(): void
    {
        $findings = $this->buildFindings(10, true);
        $repo = $this->createMock(AuditFindingRepository::class);
        $repo->method('findOpenByTenant')->willReturn($findings);

        $rule = new NonconformityAutoTaskTipRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsHintWhenManyUnlinkedFindings(): void
    {
        $findings = $this->buildFindings(8, false);
        $repo = $this->createMock(AuditFindingRepository::class);
        $repo->method('findOpenByTenant')->willReturn($findings);

        $rule = new NonconformityAutoTaskTipRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.nonconformity_auto_task_tip', $hint->key);
        self::assertSame(3, $hint->priorityTier);
        self::assertSame('info', $hint->variant);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_audit_finding_index', $hint->actionRoute);
        self::assertContains('ROLE_MANAGER', $hint->requiredRoles);
        self::assertTrue($hint->dismissible);
    }

    #[Test]
    public function requiresAuditsModule(): void
    {
        $rule = new NonconformityAutoTaskTipRule($this->createMock(AuditFindingRepository::class));
        self::assertContains('audits', $rule->requiredModules());
    }

    #[Test]
    public function appliesToAuditFindingIndex(): void
    {
        $rule = new NonconformityAutoTaskTipRule($this->createMock(AuditFindingRepository::class));
        self::assertContains('app_audit_finding_index', $rule->appliesToPages());
    }

    /**
     * @return AuditFinding[]
     */
    private function buildFindings(int $count, bool $withRequirements): array
    {
        $findings = [];
        for ($i = 0; $i < $count; $i++) {
            $finding = new AuditFinding();
            if ($withRequirements) {
                $req = new ComplianceRequirement();
                $finding->addLinkedRequirement($req);
            }
            $findings[] = $finding;
        }
        return $findings;
    }
}
