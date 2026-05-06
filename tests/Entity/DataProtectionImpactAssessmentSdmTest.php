<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DataProtectionImpactAssessment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DataProtectionImpactAssessmentSdmTest extends TestCase
{
    #[Test]
    public function sdmCoverageIsZeroWhenNoGoalsAssessed(): void
    {
        $dpia = new DataProtectionImpactAssessment();

        $this->assertSame(0, $dpia->getSdmCoveragePercent());
        $this->assertNull($dpia->getSdmHighestRiskLevel());
    }

    #[Test]
    public function sdmCoverageReflectsAssessedGoals(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $dpia->setSdmAssessment([
            'verfuegbarkeit' => ['risk_level' => 'low', 'rationale' => 'Redundancy.'],
            'integritaet' => ['risk_level' => 'medium'],
            'vertraulichkeit' => ['risk_level' => 'high'],
        ]);

        $this->assertSame(43, $dpia->getSdmCoveragePercent()); // 3/7 → 42.85 rounded
        $this->assertSame('high', $dpia->getSdmHighestRiskLevel());
    }

    #[Test]
    public function sdmFullAssessmentReachesHundredPercent(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $assessment = [];
        foreach (DataProtectionImpactAssessment::SDM_PROTECTION_GOALS as $goal) {
            $assessment[$goal] = ['risk_level' => 'low'];
        }
        $dpia->setSdmAssessment($assessment);

        $this->assertSame(100, $dpia->getSdmCoveragePercent());
        $this->assertSame('low', $dpia->getSdmHighestRiskLevel());
    }

    #[Test]
    public function sdmIgnoresInvalidEntries(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $dpia->setSdmAssessment([
            'verfuegbarkeit' => ['risk_level' => ''],
            'integritaet' => ['risk_level' => 'invalid'],
            'vertraulichkeit' => ['risk_level' => 'medium'],
        ]);

        $this->assertSame(14, $dpia->getSdmCoveragePercent()); // only vertraulichkeit counts
        $this->assertSame('medium', $dpia->getSdmHighestRiskLevel());
    }
}
