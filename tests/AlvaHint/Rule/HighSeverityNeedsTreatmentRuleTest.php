<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Incident\HighSeverityNeedsTreatmentRule;
use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HighSeverityNeedsTreatmentRuleTest extends TestCase
{
    private HighSeverityNeedsTreatmentRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new HighSeverityNeedsTreatmentRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForCriticalIncidentOnHighCiaAsset(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2);

        $incident = $this->buildIncident(
            IncidentSeverity::Critical,
            IncidentStatus::Resolved,
            [$asset],
            [],
        );

        $this->assertTrue($this->rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyForLowSeverity(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(5);
        $asset->setAvailabilityValue(5);

        $incident = $this->buildIncident(
            IncidentSeverity::Low,
            IncidentStatus::Resolved,
            [$asset],
            [],
        );

        $this->assertFalse($this->rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyDuringInvestigation(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(5);
        $asset->setAvailabilityValue(5);

        $incident = $this->buildIncident(
            IncidentSeverity::High,
            IncidentStatus::InInvestigation,
            [$asset],
            [],
        );

        $this->assertFalse($this->rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRealizedRisksExist(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(5);

        $incident = $this->buildIncident(
            IncidentSeverity::High,
            IncidentStatus::Resolved,
            [$asset],
            [new \stdClass()],
        );

        $this->assertFalse($this->rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenAllAssetsLowCia(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2);

        $incident = $this->buildIncident(
            IncidentSeverity::High,
            IncidentStatus::Resolved,
            [$asset],
            [],
        );

        $this->assertFalse($this->rule->appliesTo($incident, $this->user));
    }

    private function buildIncident(IncidentSeverity $severity, IncidentStatus $status, array $assets, array $realizedRisks): Incident
    {
        $incident = new Incident();
        $incident->setSeverity($severity);
        $incident->setStatus($status);
        $reflection = new \ReflectionClass($incident);
        $reflection->getProperty('affectedAssets')->setValue($incident, new ArrayCollection($assets));
        $reflection->getProperty('realizedRisks')->setValue($incident, new ArrayCollection($realizedRisks));
        return $incident;
    }
}
