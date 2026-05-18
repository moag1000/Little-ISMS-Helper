<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\RiskTreatmentPlan;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle Y.5 PR-A — Unit tests for RiskTreatmentPlan lifecycle.
 *
 * ISO 27001 Cl. 6.1.3 — Information Security Risk Treatment.
 *
 * Places: planned → in_progress → completed | cancelled | on_hold
 * Resume: on_hold → in_progress
 *
 * 4-eyes + reason_required on: complete, cancel
 */
final class RiskTreatmentPlanWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new RiskTreatmentPlan();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPlanned(): void
    {
        $entity = new RiskTreatmentPlan();
        $this->assertSame('planned', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new RiskTreatmentPlan();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllFiveWorkflowPlaces(): void
    {
        $entity = new RiskTreatmentPlan();
        $places = ['planned', 'in_progress', 'completed', 'cancelled', 'on_hold'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('risk-treatment-plan');
        $this->assertNotNull($entry, "'risk-treatment-plan' slug must be registered in EntityTypeRegistry");
        $this->assertSame(RiskTreatmentPlan::class, $entry['class']);
        $this->assertSame('risk_treatment_plan_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesRiskTreatmentPlan(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('risk-treatment-plan', $slugs);
    }

    #[Test]
    public function workflowYamlDefinesFourEyesAndReasonRequiredOnCompleteAndCancel(): void
    {
        $yamlPath = \dirname(__DIR__, 2) . '/config/workflows/risk_treatment_plan.yaml';
        $this->assertFileExists($yamlPath);
        $contents = (string) file_get_contents($yamlPath);

        foreach (['complete', 'cancel'] as $transition) {
            $this->assertMatchesRegularExpression(
                '/' . $transition . ':[\s\S]*?four_eyes:\s*true/',
                $contents,
                "Transition '$transition' must have four_eyes: true"
            );
            $this->assertMatchesRegularExpression(
                '/' . $transition . ':[\s\S]*?reason_required:\s*true/',
                $contents,
                "Transition '$transition' must require a reason"
            );
        }
    }
}
