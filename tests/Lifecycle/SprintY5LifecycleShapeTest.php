<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\ChangeRequest;
use App\Entity\ManagementReview;
use App\Entity\Patch;
use App\Entity\PrototypeProtectionAssessment;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Supplier;
use App\Entity\ThreatIntelligence;
use App\Entity\Training;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sprint Y.5 — Shape tests for the 10 newly lifecycle-managed entities.
 *
 * Pins entity-shape assumptions the Symfony Workflow infrastructure depends on:
 *   1. Entity has getStatus()/setStatus() (Workflow marking-store: method).
 *   2. Entity has getLockVersion() returning int (optimistic-locking).
 *   3. Entity has getTenant() (TenantGuard precondition).
 *   4. EntityTypeRegistry slug is registered → LifecycleController routing works.
 *   5. Status default at construction matches the YAML `initial_marking`.
 *
 * Behaviour-level transition tests (guard enforcement, 4-eyes denial,
 * reason validation) live in LifecycleControllerTest / FourEyesValidatorTest
 * which exercise the actual Symfony Workflow Registry — these here pin the
 * data-shape so the workflow can attach at all.
 */
final class SprintY5LifecycleShapeTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: string}>
     *               [entity-fqcn, slug, workflow-name, initial-marking]
     */
    public static function entityCases(): array
    {
        return [
            'Training' => [
                Training::class, 'training', 'training_lifecycle', 'planned',
            ],
            'RiskTreatmentPlan' => [
                RiskTreatmentPlan::class, 'risk-treatment-plan', 'risk_treatment_plan_lifecycle', 'planned',
            ],
            'Supplier' => [
                Supplier::class, 'supplier', 'supplier_lifecycle', 'evaluation',
            ],
            'PrototypeProtectionAssessment' => [
                PrototypeProtectionAssessment::class, 'prototype-protection-assessment',
                'prototype_protection_assessment_lifecycle', 'draft',
            ],
            'BusinessContinuityPlan' => [
                BusinessContinuityPlan::class, 'business-continuity-plan',
                'business_continuity_plan_lifecycle', 'draft',
            ],
            'Patch' => [
                Patch::class, 'patch', 'patch_lifecycle', 'pending',
            ],
            'ManagementReview' => [
                ManagementReview::class, 'management-review', 'management_review_lifecycle', 'planned',
            ],
            'ChangeRequest' => [
                ChangeRequest::class, 'change-request', 'change_request_lifecycle', 'draft',
            ],
            'ThreatIntelligence' => [
                ThreatIntelligence::class, 'threat-intelligence', 'threat_intelligence_lifecycle', 'new',
            ],
            'BCExercise' => [
                BCExercise::class, 'bc-exercise', 'bc_exercise_lifecycle', 'planned',
            ],
        ];
    }

    /**
     * @param class-string $fqcn
     */
    #[Test]
    #[DataProvider('entityCases')]
    public function entityHasStatusFieldAndAccessors(string $fqcn, string $slug, string $workflow, string $initial): void
    {
        $entity = new $fqcn();
        $this->assertTrue(method_exists($entity, 'getStatus'),
            "$fqcn must have getStatus() — Workflow marking-store: method");
        $this->assertTrue(method_exists($entity, 'setStatus'),
            "$fqcn must have setStatus() — Workflow marking-store: method");
    }

    /**
     * @param class-string $fqcn
     */
    #[Test]
    #[DataProvider('entityCases')]
    public function entityHasLockVersionAccessor(string $fqcn, string $slug, string $workflow, string $initial): void
    {
        $entity = new $fqcn();
        $this->assertTrue(method_exists($entity, 'getLockVersion'),
            "$fqcn must expose getLockVersion() for optimistic-locking");
        $this->assertSame(0, $entity->getLockVersion(),
            "$fqcn fresh instance must start at lock_version = 0");
    }

    /**
     * @param class-string $fqcn
     */
    #[Test]
    #[DataProvider('entityCases')]
    public function entityHasTenantAccessor(string $fqcn, string $slug, string $workflow, string $initial): void
    {
        $entity = new $fqcn();
        $this->assertTrue(method_exists($entity, 'getTenant'),
            "$fqcn must expose getTenant() — TenantGuard precondition");
    }

    /**
     * @param class-string $fqcn
     */
    #[Test]
    #[DataProvider('entityCases')]
    public function entityInitialStatusMatchesYamlInitialMarking(
        string $fqcn,
        string $slug,
        string $workflow,
        string $initial,
    ): void {
        $entity = new $fqcn();
        $status = $entity->getStatus();
        // Tolerant comparison: status may be string or backed-enum case.
        $statusValue = is_object($status) && property_exists($status, 'value')
            ? $status->value
            : (string) $status;
        $this->assertSame(
            $initial,
            $statusValue,
            "$fqcn default status must equal YAML initial_marking '$initial' so Workflow can attach"
        );
    }

    /**
     * @param class-string $fqcn
     */
    #[Test]
    #[DataProvider('entityCases')]
    public function entitySlugIsRegisteredInEntityTypeRegistry(
        string $fqcn,
        string $slug,
        string $workflow,
        string $initial,
    ): void {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup($slug);
        $this->assertNotNull($entry, "Slug '$slug' must be registered in EntityTypeRegistry");
        $this->assertSame($fqcn, $entry['class'], "Slug '$slug' must map to $fqcn");
        $this->assertSame($workflow, $entry['workflow'], "Slug '$slug' must map to workflow '$workflow'");
    }

    #[Test]
    public function allTenSlugsArePresentInKnownSlugs(): void
    {
        $registry = new EntityTypeRegistry();
        $knownSlugs = $registry->knownSlugs();
        $expectedSlugs = [
            'training',
            'risk-treatment-plan',
            'supplier',
            'prototype-protection-assessment',
            'business-continuity-plan',
            'patch',
            'management-review',
            'change-request',
            'threat-intelligence',
            'bc-exercise',
        ];
        foreach ($expectedSlugs as $slug) {
            $this->assertContains(
                $slug,
                $knownSlugs,
                "Slug '$slug' must appear in EntityTypeRegistry::knownSlugs()"
            );
        }
    }
}
