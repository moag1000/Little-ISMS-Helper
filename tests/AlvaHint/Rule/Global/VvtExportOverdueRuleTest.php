<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\VvtExportOverdueRule;
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
 * Unit tests for VvtExportOverdueRule.
 *
 * Covers: fires when last export > 365d, fires when never exported,
 * suppressed when last export < 365d, suppressed when no PAs,
 * module gating, page scoping, warning tier, and GET action method.
 */
#[AllowMockObjectsWithoutExpectations]
final class VvtExportOverdueRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsHintWhenLastExportOlderThan365Days(): void
    {
        $activities = [new ProcessingActivity()];
        $paRepo = $this->createMock(ProcessingActivityRepository::class);
        $paRepo->method('findByTenant')->willReturn($activities);

        $lastExport = (new \DateTimeImmutable())->modify('-400 days')->format('Y-m-d H:i:s');
        $em = $this->buildEmWithLastExport($lastExport);

        $rule = new VvtExportOverdueRule($paRepo, $em);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.vvt_export_overdue', $hint->key);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_vvt_export_xlsx', $hint->actionRoute);
        self::assertSame(2, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
        self::assertSame(['ROLE_DPO'], $hint->requiredRoles);
        self::assertTrue($hint->dismissible);
    }

    #[Test]
    public function returnsHintWhenNeverExported(): void
    {
        $activities = [new ProcessingActivity()];
        $paRepo = $this->createMock(ProcessingActivityRepository::class);
        $paRepo->method('findByTenant')->willReturn($activities);

        $em = $this->buildEmWithLastExport(null);

        $rule = new VvtExportOverdueRule($paRepo, $em);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['%months%' => '12+'], $hint->bodyTranslationParams);
    }

    #[Test]
    public function returnsNullWhenLastExportWithin365Days(): void
    {
        $activities = [new ProcessingActivity()];
        $paRepo = $this->createMock(ProcessingActivityRepository::class);
        $paRepo->method('findByTenant')->willReturn($activities);

        $lastExport = (new \DateTimeImmutable())->modify('-30 days')->format('Y-m-d H:i:s');
        $em = $this->buildEmWithLastExport($lastExport);

        $rule = new VvtExportOverdueRule($paRepo, $em);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenNoProcessingActivities(): void
    {
        $paRepo = $this->createMock(ProcessingActivityRepository::class);
        $paRepo->method('findByTenant')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);

        $rule = new VvtExportOverdueRule($paRepo, $em);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function requiresPrivacyModule(): void
    {
        $rule = new VvtExportOverdueRule(
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
        self::assertSame(['privacy'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToDashboardAndInboxPages(): void
    {
        $rule = new VvtExportOverdueRule(
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
        $pages = $rule->appliesToPages();
        self::assertContains('dashboard_ciso', $pages);
        self::assertContains('dashboard_compliance_manager', $pages);
        self::assertContains('inbox', $pages);
    }

    #[Test]
    public function isTierTwo(): void
    {
        $rule = new VvtExportOverdueRule(
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
        self::assertSame(2, $rule->priorityTier());
    }

    /**
     * Build a minimal EntityManager mock that returns the given MAX(createdAt)
     * for the VVT-export audit-log query.
     *
     * @param string|null $lastExportDateString ISO-like date string, or null for "never exported"
     */
    private function buildEmWithLastExport(?string $lastExportDateString): EntityManagerInterface
    {
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($lastExportDateString);

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
