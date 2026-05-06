<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Vulnerability\CriticalUnpatchedRule;
use App\Entity\User;
use App\Entity\Vulnerability;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CriticalUnpatchedRuleTest extends TestCase
{
    private CriticalUnpatchedRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new CriticalUnpatchedRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesToHighScoreOpenVulnerabilityWithoutPatches(): void
    {
        $vuln = $this->buildVuln(score: '9.5', status: 'open');

        $this->assertTrue($this->rule->appliesTo($vuln, $this->user));
    }

    #[Test]
    public function doesNotApplyBelowCriticalThreshold(): void
    {
        $vuln = $this->buildVuln(score: '8.9', status: 'open');

        $this->assertFalse($this->rule->appliesTo($vuln, $this->user));
    }

    #[Test]
    public function doesNotApplyOnceRemediated(): void
    {
        $vuln = $this->buildVuln(score: '9.5', status: 'remediated');

        $this->assertFalse($this->rule->appliesTo($vuln, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenAtLeastOnePatchExists(): void
    {
        $vuln = $this->buildVuln(score: '9.5', status: 'open', hasPatch: true);

        $this->assertFalse($this->rule->appliesTo($vuln, $this->user));
    }

    #[Test]
    public function buildEmitsTier1NonDismissibleHint(): void
    {
        $vuln = $this->buildVuln(score: '9.8', status: 'open');
        $reflection = new \ReflectionClass($vuln);
        $reflection->getProperty('id')->setValue($vuln, 42);

        $hint = $this->rule->build($vuln, $this->user);

        $this->assertSame('vulnerability.critical_unpatched', $hint->key);
        $this->assertSame(1, $hint->priorityTier);
        $this->assertFalse($hint->dismissible);
        $this->assertSame('Vulnerability', $hint->entityType);
        $this->assertSame(42, $hint->entityId);
        $this->assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
    }

    private function buildVuln(string $score, string $status, bool $hasPatch = false): Vulnerability
    {
        $vuln = new Vulnerability();
        $vuln->setCvssScore($score);
        $vuln->setStatus($status);
        if ($hasPatch) {
            $reflection = new \ReflectionClass($vuln);
            $patches = new ArrayCollection([new \stdClass()]);
            $reflection->getProperty('patches')->setValue($vuln, $patches);
        }

        return $vuln;
    }
}
