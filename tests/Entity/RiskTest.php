<?php

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Risk;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class RiskTest extends TestCase
{
    public function testGetInherentRiskLevel(): void
    {
        $risk = new Risk();
        $risk->setProbability(3);
        $risk->setImpact(5);

        $this->assertEquals(15, $risk->getInherentRiskLevel());
    }

    public function testGetResidualRiskLevel(): void
    {
        $risk = new Risk();
        $risk->setResidualProbability(2);
        $risk->setResidualImpact(3);

        $this->assertEquals(6, $risk->getResidualRiskLevel());
    }

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

    public function testGetRiskReductionWithZeroInherent(): void
    {
        $risk = new Risk();
        $risk->setProbability(0);
        $risk->setImpact(0);
        $risk->setResidualProbability(0);
        $risk->setResidualImpact(0);

        $this->assertEquals(0.0, $risk->getRiskReduction());
    }

    public function testIsHighRisk(): void
    {
        $risk = new Risk();

        // High risk (inherent >= 15)
        $risk->setProbability(5);
        $risk->setImpact(5); // 25
        $this->assertTrue($risk->isHighRisk());

        // Medium risk
        $risk->setProbability(3);
        $risk->setImpact(4); // 12
        $this->assertFalse($risk->isHighRisk());

        // Boundary case: exactly 15
        $risk->setProbability(3);
        $risk->setImpact(5); // 15
        $this->assertTrue($risk->isHighRisk());
    }

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

    public function testGetIncidentCount(): void
    {
        $risk = new Risk();
        $this->assertEquals(0, $risk->getIncidentCount());

        $incident1 = new Incident();
        $incident2 = new Incident();

        $risk->addIncident($incident1);
        $risk->addIncident($incident2);

        $this->assertEquals(2, $risk->getIncidentCount());
    }

    public function testGetRealizationCount(): void
    {
        $risk = new Risk();

        $incident1 = new Incident();
        $incident2 = new Incident();
        $incident3 = new Incident();

        $risk->addIncident($incident1);
        $risk->addIncident($incident2);
        $risk->addIncident($incident3);

        $this->assertEquals(3, $risk->getRealizationCount());
    }

    public function testWasAssessmentAccurateWithNoIncidents(): void
    {
        $risk = new Risk();
        $risk->setProbability(4);
        $risk->setImpact(5);

        // No incidents = cannot validate
        $this->assertNull($risk->isAssessmentAccurate());
    }

    public function testWasAssessmentAccurateWithHighRiskAndIncidents(): void
    {
        $risk = new Risk();
        $risk->setProbability(4);
        $risk->setImpact(5); // inherent = 20 (high risk)

        $incident1 = new Incident();
        $incident1->setSeverity('critical');
        $incident2 = new Incident();
        $incident2->setSeverity('high');

        $risk->addIncident($incident1);
        $risk->addIncident($incident2);

        // High risk assessment was accurate (incidents did occur)
        $this->assertTrue($risk->isAssessmentAccurate());
    }

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

    public function testWasAssessmentAccurateWithLowRiskButCriticalIncident(): void
    {
        $risk = new Risk();
        $risk->setProbability(1);
        $risk->setImpact(2); // inherent = 2 (low risk)

        $incident = new Incident();
        $incident->setSeverity('critical');

        $risk->addIncident($incident);

        // Low risk assessment was NOT accurate (critical incident occurred)
        $this->assertFalse($risk->isAssessmentAccurate());
    }

    public function testWasAssessmentAccurateWithHighRiskButOnlyLowIncidents(): void
    {
        $risk = new Risk();
        $risk->setProbability(5);
        $risk->setImpact(5); // inherent = 25 (high risk)

        $incident = new Incident();
        $incident->setSeverity('low');

        $risk->addIncident($incident);

        // High risk but only low-severity incident = overassessed
        $this->assertFalse($risk->isAssessmentAccurate());
    }

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

    public function testAddAndRemoveIncident(): void
    {
        $risk = new Risk();
        $incident = new Incident();

        $this->assertEquals(0, $risk->getIncidents()->count());

        $risk->addIncident($incident);
        $this->assertEquals(1, $risk->getIncidents()->count());
        $this->assertTrue($risk->getIncidents()->contains($incident));

        $risk->removeIncident($incident);
        $this->assertEquals(0, $risk->getIncidents()->count());
        $this->assertFalse($risk->getIncidents()->contains($incident));
    }
}
