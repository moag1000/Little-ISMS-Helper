<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\AuditFinding\CriticalWithoutCapaRule;
use App\Entity\AuditFinding;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CriticalWithoutCapaRuleTest extends TestCase
{
    private CriticalWithoutCapaRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new CriticalWithoutCapaRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForMajorWithoutCapa(): void
    {
        $finding = $this->buildFinding('major', 0);
        $this->assertTrue($this->rule->appliesTo($finding, $this->user));
    }

    #[Test]
    public function appliesForCriticalWithoutCapa(): void
    {
        $finding = $this->buildFinding('critical', 0);
        $this->assertTrue($this->rule->appliesTo($finding, $this->user));
    }

    #[Test]
    public function doesNotApplyForMinor(): void
    {
        $finding = $this->buildFinding('minor', 0);
        $this->assertFalse($this->rule->appliesTo($finding, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenCapaExists(): void
    {
        $finding = $this->buildFinding('critical', 1);
        $this->assertFalse($this->rule->appliesTo($finding, $this->user));
    }

    private function buildFinding(string $severity, int $capaCount): AuditFinding
    {
        $finding = new AuditFinding();
        $finding->setSeverity($severity);
        $reflection = new \ReflectionClass($finding);
        $items = [];
        for ($i = 0; $i < $capaCount; $i++) {
            $items[] = new \stdClass();
        }
        $reflection->getProperty('correctiveActions')->setValue($finding, new ArrayCollection($items));
        return $finding;
    }
}
