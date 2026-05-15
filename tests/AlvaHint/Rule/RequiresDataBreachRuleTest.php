<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Incident\RequiresDataBreachRule;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\User;
use App\Repository\DataBreachRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequiresDataBreachRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenIncidentFlagsBreachAndNoLinkedRecordExists(): void
    {
        $repo = $this->createMock(DataBreachRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $rule = new RequiresDataBreachRule($repo);

        $incident = new Incident();
        $incident->setDataBreachOccurred(true);

        $this->assertTrue($rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyIfFlagNotSet(): void
    {
        $rule = new RequiresDataBreachRule();

        $incident = new Incident();
        $incident->setDataBreachOccurred(false);

        $this->assertFalse($rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyOnceDataBreachIsLinked(): void
    {
        $repo = $this->createMock(DataBreachRepository::class);
        $repo->method('findOneBy')->willReturn(new DataBreach());
        $rule = new RequiresDataBreachRule($repo);

        $incident = new Incident();
        $incident->setDataBreachOccurred(true);

        $this->assertFalse($rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function buildEmitsTier1NonDismissibleDangerHint(): void
    {
        $rule = new RequiresDataBreachRule();
        $incident = new Incident();
        $incident->setDataBreachOccurred(true);

        $hint = $rule->build($incident, $this->user);

        $this->assertSame(1, $hint->priorityTier);
        $this->assertFalse($hint->dismissible);
        $this->assertSame('danger', $hint->variant);
        $this->assertSame('Incident', $hint->entityType);
        $this->assertSame('app_data_breach_new', $hint->actionRoute);
        $this->assertSame('GET', $hint->actionMethod);
        $this->assertSame('warning', $hint->mood);
    }

    #[Test]
    public function requiredModulesGateIncidentsAndPrivacy(): void
    {
        $rule = new RequiresDataBreachRule();
        $this->assertSame(['incidents', 'privacy'], $rule->requiredModules());
    }

    #[Test]
    public function ignoresUnrelatedEntities(): void
    {
        $rule = new RequiresDataBreachRule();
        $this->assertFalse($rule->appliesTo(new \stdClass(), $this->user));
    }
}
