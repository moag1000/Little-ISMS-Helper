<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Risk\RiskMatrixThresholds;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RiskTest extends TestCase
{
    #[Test]
    public function testGetInherentRiskLevel(): void
    {
        $risk = new Risk();
        $risk->setProbability(3);
        $risk->setImpact(5);

        $this->assertEquals(15, $risk->getInherentRiskLevel());
    }

    #[Test]
    public function testInherentSeverityBandUsesCanonicalThresholds(): void
    {
        // Canonical bands (App\Risk\RiskMatrixThresholds): critical≥20, high≥12,
        // medium≥6, low otherwise. Previously dashboards used divergent cutoffs.
        $cases = [
            [5, 5, 'critical'], // 25
            [4, 5, 'critical'], // 20
            [3, 5, 'high'],     // 15
            [3, 4, 'high'],     // 12
            [2, 5, 'medium'],   // 10
            [2, 3, 'medium'],   // 6
            [1, 5, 'low'],      // 5
            [1, 1, 'low'],      // 1
        ];

        foreach ($cases as [$p, $i, $expected]) {
            $risk = new Risk();
            $risk->setProbability($p);
            $risk->setImpact($i);
            $this->assertSame(
                $expected,
                $risk->getInherentSeverityBand(),
                sprintf('score %d (%dx%d)', $p * $i, $p, $i),
            );
        }
    }

    #[Test]
    public function testGetResidualRiskLevel(): void
    {
        $risk = new Risk();
        $risk->setResidualProbability(2);
        $risk->setResidualImpact(3);

        $this->assertEquals(6, $risk->getResidualRiskLevel());
    }

    #[Test]
    public function testGetRiskReduction(): void
    {
        $risk = new Risk();
        $risk->setProbability(4);
        $risk->setImpact(5); // inherent = 20
        $risk->setResidualProbability(2);
        $risk->setResidualImpact(2); // residual = 4

        // Reduction = ((20 - 4) / 20) * 100 = 80%
        $this->assertEquals(80.0, $risk->getRiskReduction());
    }

    #[Test]
    public function testGetRiskReductionWithZeroInherent(): void
    {
        $risk = new Risk();
        $risk->setProbability(0);
        $risk->setImpact(0);
        $risk->setResidualProbability(0);
        $risk->setResidualImpact(0);

        $this->assertEquals(0.0, $risk->getRiskReduction());
    }

    #[Test]
    public function testIsHighRisk(): void
    {
        $risk = new Risk();

        // Critical band is also "high risk"
        $risk->setProbability(5);
        $risk->setImpact(5); // 25
        $this->assertTrue($risk->isHighRisk());

        // Boundary: below RiskMatrixThresholds::DEFAULT_HIGH_MIN (12) -> medium (11 is not a 5x5 product, 10 is the nearest)
        $risk->setProbability(2);
        $risk->setImpact(5); // 10
        $this->assertFalse($risk->isHighRisk());
        $risk->setProbability(3);
        $risk->setImpact(4); // 12 == HIGH_MIN
        $this->assertSame(RiskMatrixThresholds::DEFAULT_HIGH_MIN, $risk->getInherentRiskLevel());
        $this->assertTrue($risk->isHighRisk());
    }

    #[Test]
    public function testGetControlCoverageCount(): void
    {
        $risk = new Risk();
        $this->assertEquals(0, $risk->getControlCoverageCount());

        $control1 = new Control();
        $control2 = new Control();
        $control3 = new Control();

        $risk->addControl($control1);
        $risk->addControl($control2);
        $risk->addControl($control3);

        $this->assertEquals(3, $risk->getControlCoverageCount());
    }

    #[Test]
    public function testGetIncidentCount(): void
    {
        $risk = new Risk();
        $this->assertEquals(0, $risk->getIncidentCount());

        $incident1 = new Incident();
        $incident2 = new Incident();

        $incident1->addRealizedRisk($risk);
        $incident2->addRealizedRisk($risk);

        $this->assertEquals(2, $risk->getIncidentCount());
    }

    #[Test]
    public function testGetRealizationCount(): void
    {
        $risk = new Risk();

        $incident1 = new Incident();
        $incident2 = new Incident();
        $incident3 = new Incident();

        $incident1->addRealizedRisk($risk);
        $incident2->addRealizedRisk($risk);
        $incident3->addRealizedRisk($risk);

        $this->assertEquals(3, $risk->getRealizationCount());
    }

    #[Test]
    public function testWasAssessmentAccurateWithNoIncidents(): void
    {
        $risk = new Risk();
        $risk->setProbability(4);
        $risk->setImpact(5);

        // No incidents = cannot validate
        $this->assertNull($risk->isAssessmentAccurate());
    }

    #[Test]
    public function testWasAssessmentAccurateWithHighRiskAndIncidents(): void
    {
        $risk = new Risk();
        $risk->setProbability(4);
        $risk->setImpact(5); // inherent = 20 (high risk)

        $incident1 = new Incident();
        $incident1->setSeverity(\App\Enum\IncidentSeverity::Critical);
        $incident2 = new Incident();
        $incident2->setSeverity(\App\Enum\IncidentSeverity::High);

        $incident1->addRealizedRisk($risk);
        $incident2->addRealizedRisk($risk);

        // High risk assessment was accurate (incidents did occur)
        $this->assertTrue($risk->isAssessmentAccurate());
    }

    #[Test]
    public function testWasAssessmentAccurateWithLowRiskAndNoIncidents(): void
    {
        $risk = new Risk();
        $risk->setProbability(2);
        $risk->setImpact(2); // inherent = 4 (low risk)

        // Low risk, no incidents = accurate assessment
        // But wait, we need at least one incident to evaluate
        // So this returns null
        $this->assertNull($risk->isAssessmentAccurate());
    }

    #[Test]
    public function testWasAssessmentAccurateWithLowRiskButCriticalIncident(): void
    {
        $risk = new Risk();
        $risk->setProbability(1);
        $risk->setImpact(2); // inherent = 2 (low risk)

        $incident = new Incident();
        $incident->setSeverity(\App\Enum\IncidentSeverity::Critical);

        $incident->addRealizedRisk($risk);

        // Low risk assessment was NOT accurate (critical incident occurred)
        $this->assertFalse($risk->isAssessmentAccurate());
    }

    #[Test]
    public function testWasAssessmentAccurateWithHighRiskButOnlyLowIncidents(): void
    {
        $risk = new Risk();
        $risk->setProbability(5);
        $risk->setImpact(5); // inherent = 25 (high risk)

        $incident = new Incident();
        $incident->setSeverity(\App\Enum\IncidentSeverity::Low);

        $incident->addRealizedRisk($risk);

        // High risk but only low-severity incident = overassessed
        $this->assertFalse($risk->isAssessmentAccurate());
    }

    #[Test]
    public function testAddAndRemoveControl(): void
    {
        $risk = new Risk();
        $control = new Control();

        $this->assertEquals(0, $risk->getControls()->count());

        $risk->addControl($control);
        $this->assertEquals(1, $risk->getControls()->count());
        $this->assertTrue($risk->getControls()->contains($control));

        $risk->removeControl($control);
        $this->assertEquals(0, $risk->getControls()->count());
        $this->assertFalse($risk->getControls()->contains($control));
    }

    #[Test]
    public function testAddAndRemoveIncident(): void
    {
        $risk = new Risk();
        $incident = new Incident();

        $this->assertEquals(0, $risk->getIncidents()->count());

        $incident->addRealizedRisk($risk);
        $this->assertEquals(1, $risk->getIncidents()->count());
        $this->assertTrue($risk->getIncidents()->contains($incident));

        $incident->removeRealizedRisk($risk);
        $this->assertEquals(0, $risk->getIncidents()->count());
        $this->assertFalse($risk->getIncidents()->contains($incident));
    }
}
