<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\Rule\PolicyWizard\OpenFindingReferenceRule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\WizardRunRepository;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W7-D — OpenFindingReferenceRule unit tests.
 */
#[AllowMockObjectsWithoutExpectations]
final class OpenFindingReferenceRuleTest extends TestCase
{
    private WizardRunRepository&MockObject $repository;
    private User $user;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(WizardRunRepository::class);
        $this->user = new User();
    }

    #[Test]
    public function testFiresWhenConditionsMet(): void
    {
        $tenant = $this->makeTenant(11);

        $stale = $this->makeRun('FIND-2025-001', $this->daysAgo(45));
        $this->repository->method('findOpenForTenant')->willReturn([$stale]);
        $this->repository->method('findBy')->willReturn([]);

        $rule = new OpenFindingReferenceRule($this->repository);
        self::assertTrue($rule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function testSkipsWhenConditionsNotMet(): void
    {
        $tenant = $this->makeTenant(11);

        // Case 1: open run inside the threshold window — must NOT fire.
        $fresh = $this->makeRun('FIND-2025-002', $this->daysAgo(5));
        $this->repository->method('findOpenForTenant')->willReturn([$fresh]);
        $this->repository->method('findBy')->willReturn([]);
        $rule = new OpenFindingReferenceRule($this->repository);
        self::assertFalse($rule->appliesTo($tenant, $this->user));

        // Case 2: stale open run, but a completed run consumed the same
        // finding reference — must NOT fire.
        $consumedRepo = $this->createMock(WizardRunRepository::class);
        $stale = $this->makeRun('FIND-2025-003', $this->daysAgo(60));
        $completed = $this->makeRun('FIND-2025-003', $this->daysAgo(2));
        $consumedRepo->method('findOpenForTenant')->willReturn([$stale]);
        $consumedRepo->method('findBy')->willReturn([$completed]);
        $consumedRule = new OpenFindingReferenceRule($consumedRepo);
        self::assertFalse($consumedRule->appliesTo($tenant, $this->user));

        // Case 3: open run without findingReference — must NOT fire.
        $noRefRepo = $this->createMock(WizardRunRepository::class);
        $blank = $this->makeRun(null, $this->daysAgo(60));
        $noRefRepo->method('findOpenForTenant')->willReturn([$blank]);
        $noRefRepo->method('findBy')->willReturn([]);
        $noRefRule = new OpenFindingReferenceRule($noRefRepo);
        self::assertFalse($noRefRule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function testSkipsWhenWrongRole(): void
    {
        $tenant = $this->makeTenant(11);

        $stale = $this->makeRun('FIND-2025-004', $this->daysAgo(60));
        $this->repository->method('findOpenForTenant')->willReturn([$stale]);
        $this->repository->method('findBy')->willReturn([]);

        $rule = new OpenFindingReferenceRule($this->repository);
        $hint = $rule->build($tenant, $this->user);

        self::assertSame(['ROLE_ADMIN', 'ROLE_GROUP_CISO'], $hint->requiredRoles);
        self::assertNotContains('ROLE_USER', $hint->requiredRoles);
        self::assertNotContains('ROLE_AUDITOR', $hint->requiredRoles);
    }

    #[Test]
    public function testSkipsWhenModuleDisabled(): void
    {
        $rule = new OpenFindingReferenceRule($this->repository);
        self::assertContains('policy_wizard', $rule->requiredModules());
    }

    #[Test]
    public function testRenderAndDismissTelemetry(): void
    {
        $tenant = $this->makeTenant(42);

        $stale = $this->makeRun('FIND-2025-099', $this->daysAgo(45));
        $this->repository->method('findOpenForTenant')->willReturn([$stale]);
        $this->repository->method('findBy')->willReturn([]);

        $rule = new OpenFindingReferenceRule($this->repository);
        $hint = $rule->build($tenant, $this->user);

        self::assertSame('policy_wizard.open_finding_reference', $hint->key);
        self::assertSame(OpenFindingReferenceRule::VERSION, $hint->version);
        self::assertSame(1, $hint->priorityTier);
        self::assertFalse($hint->dismissible, 'Tier-1 hints must not be dismissible');
        self::assertSame('Tenant', $hint->entityType);
        self::assertSame(42, $hint->entityId);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame('alva_hint.open_finding_reference.title', $hint->titleTranslationKey);
        self::assertSame('alva_hint.open_finding_reference.body', $hint->bodyTranslationKey);
        self::assertSame('alva_hint.open_finding_reference.cta_label', $hint->actionLabelTranslationKey);
        self::assertSame('app_policy_wizard_index', $hint->actionRoute);
        self::assertSame('targeted', $hint->actionRouteParams['mode'] ?? null);
        self::assertSame('FIND-2025-099', $hint->actionRouteParams['finding_ref'] ?? null);
        self::assertSame('FIND-2025-099', $hint->bodyTranslationParams['%finding_ref%'] ?? null);
        self::assertSame((string) OpenFindingReferenceRule::STALE_AFTER_DAYS, $hint->bodyTranslationParams['%threshold_days%'] ?? null);
    }

    private function makeTenant(int $id): Tenant&MockObject
    {
        $stub = $this->createMock(Tenant::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeRun(?string $findingRef, DateTimeImmutable $startedAt): WizardRun
    {
        $run = new WizardRun();
        $run->setFindingReference($findingRef);
        $run->setStartedAt($startedAt);
        return $run;
    }

    private function daysAgo(int $days): DateTimeImmutable
    {
        return (new DateTimeImmutable())->sub(new DateInterval('P' . $days . 'D'));
    }
}
