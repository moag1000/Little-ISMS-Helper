<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\VvtExportTipRule;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ProcessingActivityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VvtExportTipRule.
 *
 * Covers: threshold trigger, below-threshold suppression, recent-export
 * suppression, module gating, page scoping, and GET action method.
 */
#[AllowMockObjectsWithoutExpectations]
final class VvtExportTipRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsHintWhenThresholdMetAndNoRecentExport(): void
    {
        $activities = array_fill(0, 5, new ProcessingActivity());
        $paRepo = $this->createMock(ProcessingActivityRepository::class);
        $paRepo->method('findByTenant')->willReturn($activities);

        $em = $this->buildEmWithExportCount(0);

        $rule = new VvtExportTipRule($paRepo, $em);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.vvt_export_tip', $hint->key);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_vvt_export_xlsx', $hint->actionRoute);
        self::assertSame(3, $hint->priorityTier);
        self::assertSame('info', $hint->variant);
        self::assertSame(['ROLE_DPO'], $hint->requiredRoles);
    }

    #[Test]
    public function returnsNullWhenBelowThreshold(): void
    {
        $activities = array_fill(0, 4, new ProcessingActivity());
        $paRepo = $this->createMock(ProcessingActivityRepository::class);
        $paRepo->method('findByTenant')->willReturn($activities);

        $em = $this->createMock(EntityManagerInterface::class);

        $rule = new VvtExportTipRule($paRepo, $em);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenRecentExportExists(): void
    {
        $activities = array_fill(0, 6, new ProcessingActivity());
        $paRepo = $this->createMock(ProcessingActivityRepository::class);
        $paRepo->method('findByTenant')->willReturn($activities);

        $em = $this->buildEmWithExportCount(1);

        $rule = new VvtExportTipRule($paRepo, $em);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function requiresPrivacyModule(): void
    {
        $rule = new VvtExportTipRule(
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
        self::assertSame(['privacy'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToProcessingActivityIndexPage(): void
    {
        $rule = new VvtExportTipRule(
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
        self::assertContains('app_processing_activity_index', $rule->appliesToPages());
    }

    #[Test]
    public function hintBodyContainsCountParameter(): void
    {
        $activities = array_fill(0, 8, new ProcessingActivity());
        $paRepo = $this->createMock(ProcessingActivityRepository::class);
        $paRepo->method('findByTenant')->willReturn($activities);

        $em = $this->buildEmWithExportCount(0);

        $rule = new VvtExportTipRule($paRepo, $em);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['%count%' => '8'], $hint->bodyTranslationParams);
    }

    /**
     * Build a minimal EntityManager mock that returns the given count for
     * the VVT-export audit-log COUNT query.
     */
    private function buildEmWithExportCount(int $count): EntityManagerInterface
    {
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($count);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        return $em;
    }
}
