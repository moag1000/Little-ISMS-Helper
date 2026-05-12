<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\BusinessProcessBulkImportTipRule;
use App\Entity\BusinessProcess;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\BusinessProcessRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BusinessProcessBulkImportTipRule.
 *
 * Covers: threshold trigger, below-threshold suppression, module gating,
 * page scoping, action method, required roles, and body parameter.
 */
#[AllowMockObjectsWithoutExpectations]
final class BusinessProcessBulkImportTipRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsHintWhenProcessCountExceedsThreshold(): void
    {
        $processes = array_fill(0, 16, new BusinessProcess());
        $repo = $this->createMock(BusinessProcessRepository::class);
        $repo->method('findBy')->willReturn($processes);

        $rule = new BusinessProcessBulkImportTipRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.business_process_bulk_import_tip', $hint->key);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_bulk_import_index', $hint->actionRoute);
        self::assertSame(['entityType' => 'business_process'], $hint->actionRouteParams);
        self::assertSame(3, $hint->priorityTier);
        self::assertSame('info', $hint->variant);
        self::assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
    }

    #[Test]
    public function returnsNullWhenProcessCountAtThreshold(): void
    {
        $processes = array_fill(0, 15, new BusinessProcess());
        $repo = $this->createMock(BusinessProcessRepository::class);
        $repo->method('findBy')->willReturn($processes);

        $rule = new BusinessProcessBulkImportTipRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenNoProcesses(): void
    {
        $repo = $this->createMock(BusinessProcessRepository::class);
        $repo->method('findBy')->willReturn([]);

        $rule = new BusinessProcessBulkImportTipRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function requiresBcmModule(): void
    {
        $rule = new BusinessProcessBulkImportTipRule($this->createMock(BusinessProcessRepository::class));
        self::assertSame(['bcm'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToBusinessProcessIndexPage(): void
    {
        $rule = new BusinessProcessBulkImportTipRule($this->createMock(BusinessProcessRepository::class));
        self::assertContains('app_business_process_index', $rule->appliesToPages());
    }

    #[Test]
    public function hintBodyContainsCountParameter(): void
    {
        $processes = array_fill(0, 20, new BusinessProcess());
        $repo = $this->createMock(BusinessProcessRepository::class);
        $repo->method('findBy')->willReturn($processes);

        $rule = new BusinessProcessBulkImportTipRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['%count%' => '20'], $hint->bodyTranslationParams);
    }

    #[Test]
    public function hintIsDismissible(): void
    {
        $processes = array_fill(0, 16, new BusinessProcess());
        $repo = $this->createMock(BusinessProcessRepository::class);
        $repo->method('findBy')->willReturn($processes);

        $rule = new BusinessProcessBulkImportTipRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertTrue($hint->dismissible);
    }
}
