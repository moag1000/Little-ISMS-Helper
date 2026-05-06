<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Dpia\SdmCoverageIncompleteRule;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SdmCoverageIncompleteRuleTest extends TestCase
{
    private SdmCoverageIncompleteRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new SdmCoverageIncompleteRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForReviewDpiaWithIncompleteCoverage(): void
    {
        $dpia = $this->buildDpia('in_review', ['verfuegbarkeit' => ['risk_level' => 'medium']]);
        $this->assertTrue($this->rule->appliesTo($dpia, $this->user));
    }

    #[Test]
    public function doesNotApplyForDraft(): void
    {
        $dpia = $this->buildDpia('draft', []);
        $this->assertFalse($this->rule->appliesTo($dpia, $this->user));
    }

    #[Test]
    public function doesNotApplyAtFullCoverage(): void
    {
        $assessment = [];
        foreach (DataProtectionImpactAssessment::SDM_PROTECTION_GOALS as $goal) {
            $assessment[$goal] = ['risk_level' => 'low'];
        }
        $dpia = $this->buildDpia('approved', $assessment);
        $this->assertFalse($this->rule->appliesTo($dpia, $this->user));
    }

    private function buildDpia(string $status, array $assessment): DataProtectionImpactAssessment
    {
        $dpia = new DataProtectionImpactAssessment();
        $dpia->setStatus($status);
        $dpia->setSdmAssessment($assessment === [] ? null : $assessment);
        return $dpia;
    }
}
