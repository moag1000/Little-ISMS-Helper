<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\DoraRoiExportDueRule;
use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Authority\DoraRegisterOfInformationRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DoraRoiExportDueRule.
 *
 * Covers: fires when no record exists, fires when record not submitted,
 * suppressed when record is submitted, tier-2 dismissible, module gating,
 * suppressed for non-DORA-obligated tenants (doraEntityCategory = 'none').
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraRoiExportDueRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        // Satisfy the isDoraObligated() precondition: tenant must not be 'none'.
        $this->tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);
        $this->user = new User();
    }

    #[Test]
    public function returnsHintWhenNoRecordExistsForCurrentYear(): void
    {
        $repo = $this->createMock(DoraRegisterOfInformationRepository::class);
        $repo->method('findCurrentYearForTenant')->willReturn(null);

        $rule = new DoraRoiExportDueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.dora_roi_export_due', $hint->key);
    }

    #[Test]
    public function returnsHintWhenRecordExistsButNotSubmitted(): void
    {
        $record = new DoraRegisterOfInformation();
        // No submittedAt set → isSubmitted() = false

        $repo = $this->createMock(DoraRegisterOfInformationRepository::class);
        $repo->method('findCurrentYearForTenant')->willReturn($record);

        $rule = new DoraRoiExportDueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint, 'Must fire when record exists but is not submitted');
    }

    #[Test]
    public function returnsNullWhenRecordIsSubmitted(): void
    {
        $record = new DoraRegisterOfInformation();
        $record->setSubmittedAt(new DateTimeImmutable());

        $repo = $this->createMock(DoraRegisterOfInformationRepository::class);
        $repo->method('findCurrentYearForTenant')->willReturn($record);

        $rule = new DoraRoiExportDueRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function noHintWhenTenantNotDoraObligated(): void
    {
        // Tenant with doraEntityCategory = 'none' must never see DORA hints.
        $tenant = new Tenant();
        // default doraEntityCategory is DORA_NONE — no explicit set needed,
        // but we set it explicitly here to make the intent clear.
        $tenant->setDoraEntityCategory(Tenant::DORA_NONE);

        $repo = $this->createMock(DoraRegisterOfInformationRepository::class);
        // Repository must NOT be called at all when precondition fails.
        $repo->expects(self::never())->method('findCurrentYearForTenant');

        $rule = new DoraRoiExportDueRule($repo);
        self::assertNull($rule->evaluate($tenant, $this->user), 'Non-DORA tenant must not receive a DORA RoI hint');
    }

    #[Test]
    public function hintIsTierTwoDismissible(): void
    {
        $repo = $this->createMock(DoraRegisterOfInformationRepository::class);
        $repo->method('findCurrentYearForTenant')->willReturn(null);

        $rule = new DoraRoiExportDueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(2, $hint->priorityTier);
        self::assertTrue($hint->dismissible, 'Tier-2 warning hint must be dismissible');
    }

    #[Test]
    public function hintActionRouteIsDoraRoiIndex(): void
    {
        $repo = $this->createMock(DoraRegisterOfInformationRepository::class);
        $repo->method('findCurrentYearForTenant')->willReturn(null);

        $rule = new DoraRoiExportDueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('dora_roi_index', $hint->actionRoute);
        self::assertSame('GET', $hint->actionMethod);
    }

    #[Test]
    public function requiresNis2DoraModule(): void
    {
        $rule = new DoraRoiExportDueRule($this->createMock(DoraRegisterOfInformationRepository::class));
        self::assertSame(['nis2_dora'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToComplianceDashboardPages(): void
    {
        $rule = new DoraRoiExportDueRule($this->createMock(DoraRegisterOfInformationRepository::class));
        $pages = $rule->appliesToPages();

        self::assertContains('dashboard_ciso', $pages);
        self::assertContains('dashboard_compliance_manager', $pages);
        self::assertContains('inbox', $pages);
    }

    #[Test]
    public function hintBodyContainsYearParameter(): void
    {
        $repo = $this->createMock(DoraRegisterOfInformationRepository::class);
        $repo->method('findCurrentYearForTenant')->willReturn(null);

        $rule = new DoraRoiExportDueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertArrayHasKey('%year%', $hint->bodyTranslationParams);
        self::assertSame((string) (int) (new DateTimeImmutable())->format('Y'), $hint->bodyTranslationParams['%year%']);
    }

    #[Test]
    public function hintVariantIsWarning(): void
    {
        $repo = $this->createMock(DoraRegisterOfInformationRepository::class);
        $repo->method('findCurrentYearForTenant')->willReturn(null);

        $rule = new DoraRoiExportDueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('warning', $hint->variant);
    }
}
