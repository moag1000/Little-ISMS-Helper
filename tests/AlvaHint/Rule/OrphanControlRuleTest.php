<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Control\OrphanControlRule;
use App\Entity\Control;
use App\Entity\User;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OrphanControlRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function appliesForApplicableOrphan(): void
    {
        $control = $this->buildControl(true, []);

        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $repo->method('findByControl')->willReturn([]);

        $rule = new OrphanControlRule($repo);
        $this->assertTrue($rule->appliesTo($control, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRisksLinked(): void
    {
        $control = $this->buildControl(true, [new \stdClass()]);

        $repo = $this->createMock(ComplianceRequirementRepository::class);

        $rule = new OrphanControlRule($repo);
        $this->assertFalse($rule->appliesTo($control, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRequirementsLinked(): void
    {
        $control = $this->buildControl(true, []);

        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $repo->method('findByControl')->willReturn([new \stdClass()]);

        $rule = new OrphanControlRule($repo);
        $this->assertFalse($rule->appliesTo($control, $this->user));
    }

    #[Test]
    public function doesNotApplyForNonApplicable(): void
    {
        $control = $this->buildControl(false, []);

        $repo = $this->createMock(ComplianceRequirementRepository::class);

        $rule = new OrphanControlRule($repo);
        $this->assertFalse($rule->appliesTo($control, $this->user));
    }

    private function buildControl(bool $applicable, array $risks): Control
    {
        $control = new Control();
        $control->setApplicable($applicable);
        $reflection = new \ReflectionClass($control);
        $reflection->getProperty('risks')->setValue($control, new ArrayCollection($risks));
        return $control;
    }
}
