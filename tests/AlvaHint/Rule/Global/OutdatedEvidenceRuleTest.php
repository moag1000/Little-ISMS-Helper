<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\OutdatedEvidenceRule;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ControlRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F4 — OutdatedEvidenceRule Alva-Hint unit tests.
 *
 * Covers: threshold trigger (≥3), below-threshold suppression,
 * action route, action method (GET), priority tier, and required roles.
 */
#[AllowMockObjectsWithoutExpectations]
final class OutdatedEvidenceRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    #[Test]
    public function returnsHintWhenThreeOrMoreControlsOutdated(): void
    {
        $controls = array_fill(0, 3, new Control());

        $repo = $this->createMock(ControlRepository::class);
        $repo->method('findEvidenceOutdated')->willReturn($controls);

        $rule = new OutdatedEvidenceRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.outdated_evidence', $hint->key);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame('app_evidence_reverification_index', $hint->actionRoute);
        self::assertSame([], $hint->actionRouteParams);
        self::assertSame(2, $hint->priorityTier);
        self::assertSame('warning', $hint->variant);
        self::assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame('%count%', array_key_first($hint->bodyTranslationParams));
        self::assertSame('3', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function returnsNullWhenFewerThanThreeControlsOutdated(): void
    {
        $controls = array_fill(0, 2, new Control());

        $repo = $this->createMock(ControlRepository::class);
        $repo->method('findEvidenceOutdated')->willReturn($controls);

        $rule = new OutdatedEvidenceRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNull($hint);
    }

    #[Test]
    public function returnsNullWhenZeroOutdatedControls(): void
    {
        $repo = $this->createMock(ControlRepository::class);
        $repo->method('findEvidenceOutdated')->willReturn([]);

        $rule = new OutdatedEvidenceRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNull($hint);
    }

    #[Test]
    public function keyIsCorrect(): void
    {
        $repo = $this->createMock(ControlRepository::class);
        $rule = new OutdatedEvidenceRule($repo);
        self::assertSame('global.outdated_evidence', $rule->key());
    }

    #[Test]
    public function priorityTierIsTier2(): void
    {
        $repo = $this->createMock(ControlRepository::class);
        $rule = new OutdatedEvidenceRule($repo);
        self::assertSame(2, $rule->priorityTier());
    }

    #[Test]
    public function requiredModulesIsEmpty(): void
    {
        $repo = $this->createMock(ControlRepository::class);
        $rule = new OutdatedEvidenceRule($repo);
        self::assertSame([], $rule->requiredModules());
    }

    #[Test]
    public function appliesToAllPages(): void
    {
        $repo = $this->createMock(ControlRepository::class);
        $rule = new OutdatedEvidenceRule($repo);
        self::assertSame([], $rule->appliesToPages());
    }

    #[Test]
    public function hintCountParamIsCorrectForHighCount(): void
    {
        $controls = array_fill(0, 7, new Control());

        $repo = $this->createMock(ControlRepository::class);
        $repo->method('findEvidenceOutdated')->willReturn($controls);

        $rule = new OutdatedEvidenceRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('7', $hint->bodyTranslationParams['%count%']);
    }
}
