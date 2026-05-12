<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\AuthorityTemplateOverdueRule;
use App\Entity\DataBreach;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataBreachRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuthorityTemplateOverdueRule (Sprint-3 Alva-Hint Rule 5).
 *
 * Covers:
 *  - Fires when high/critical breach detected > 24h without export
 *  - Suppressed when no breaches exist
 *  - Suppressed when breach is low/medium severity
 *  - Suppressed when authority already notified
 *  - Suppressed when export event found within 72h
 *  - Module requires privacy + eu_authority_reporting
 *  - Tier-2 warning, GET action method, ROLE_DPO + ROLE_MANAGER
 */
#[AllowMockObjectsWithoutExpectations]
final class AuthorityTemplateOverdueRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsNullWhenNoBreachesExist(): void
    {
        $repo = $this->createMock(DataBreachRepository::class);
        $repo->method('findByTenant')->willReturn([]);
        $em = $this->buildEm(0);

        $rule = new AuthorityTemplateOverdueRule($repo, $em);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullForLowSeverityBreach(): void
    {
        $breach = $this->buildBreach('low', new DateTimeImmutable('-30 hours'));
        $repo = $this->createMock(DataBreachRepository::class);
        $repo->method('findByTenant')->willReturn([$breach]);
        $em = $this->buildEm(0);

        $rule = new AuthorityTemplateOverdueRule($repo, $em);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenAuthorityAlreadyNotified(): void
    {
        $breach = $this->buildBreach('critical', new DateTimeImmutable('-30 hours'));
        $breach->setSupervisoryAuthorityNotifiedAt(new DateTimeImmutable());
        $repo = $this->createMock(DataBreachRepository::class);
        $repo->method('findByTenant')->willReturn([$breach]);
        $em = $this->buildEm(0);

        $rule = new AuthorityTemplateOverdueRule($repo, $em);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenBreachDetectedLessThan24HoursAgo(): void
    {
        $breach = $this->buildBreach('high', new DateTimeImmutable('-10 hours'));
        $repo = $this->createMock(DataBreachRepository::class);
        $repo->method('findByTenant')->willReturn([$breach]);
        $em = $this->buildEm(0);

        $rule = new AuthorityTemplateOverdueRule($repo, $em);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsHintForCriticalBreachOver24HoursWithoutExport(): void
    {
        $breach = $this->buildBreach('critical', new DateTimeImmutable('-30 hours'));
        $repo = $this->createMock(DataBreachRepository::class);
        $repo->method('findByTenant')->willReturn([$breach]);
        $em = $this->buildEm(0);

        $rule = new AuthorityTemplateOverdueRule($repo, $em);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.authority_template_overdue', $hint->key);
        self::assertSame(2, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_authority_notification_index', $hint->actionRoute);
        self::assertContains('ROLE_DPO', $hint->requiredRoles);
        self::assertTrue($hint->dismissible);
        self::assertSame('eu_authorities', $hint->translationDomain);
    }

    #[Test]
    public function returnsNullWhenExportEventFoundInLast72Hours(): void
    {
        $breach = $this->buildBreach('critical', new DateTimeImmutable('-30 hours'));
        $repo = $this->createMock(DataBreachRepository::class);
        $repo->method('findByTenant')->willReturn([$breach]);
        $em = $this->buildEm(1); // Export event found

        $rule = new AuthorityTemplateOverdueRule($repo, $em);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function requiresPrivacyAndEuAuthorityReportingModules(): void
    {
        $repo = $this->createMock(DataBreachRepository::class);
        $em = $this->buildEm(0);
        $rule = new AuthorityTemplateOverdueRule($repo, $em);

        self::assertContains('privacy', $rule->requiredModules());
        self::assertContains('eu_authority_reporting', $rule->requiredModules());
    }

    #[Test]
    public function appliesToDashboardPages(): void
    {
        $repo = $this->createMock(DataBreachRepository::class);
        $em = $this->buildEm(0);
        $rule = new AuthorityTemplateOverdueRule($repo, $em);

        self::assertContains('dashboard_ciso', $rule->appliesToPages());
        self::assertContains('inbox', $rule->appliesToPages());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildBreach(string $severity, DateTimeImmutable $detectedAt): DataBreach
    {
        $breach = new DataBreach();
        $breach->setSeverity($severity);
        $breach->setDetectedAt($detectedAt);
        $breach->setReferenceNumber('BREACH-TEST-001');
        $breach->setTitle('Test Breach');
        $breach->setBreachNature('Test');
        $breach->setLikelyConsequences('Test');
        $breach->setMeasuresTaken('Test');
        $breach->setDataCategories(['PII']);
        $breach->setDataSubjectCategories(['employees']);
        return $breach;
    }

    private function buildEm(int $exportCount): EntityManagerInterface
    {
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($exportCount);

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
