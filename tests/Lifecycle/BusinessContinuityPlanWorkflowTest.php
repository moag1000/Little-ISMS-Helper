<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\BusinessContinuityPlan;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle Sprint Y.5 PR B — Entity-shape tests for the
 * `business_continuity_plan_lifecycle` Symfony Workflow state-machine.
 *
 * ISO 22301 Cl. 8.4 (Business continuity plans and procedures) + BSI 200-4.
 *
 * Places (4): draft → under_review → active → archived
 * Four-eyes on: `activate`, `archive` (operational decisions affecting
 * recovery readiness — auditable per BSI 200-4).
 *
 * Behavioural transition tests (guards, RBAC) live in
 * `LifecycleControllerTest` (HTTP layer). This suite pins the entity-shape
 * assumptions the workflow infrastructure depends on.
 */
final class BusinessContinuityPlanWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new BusinessContinuityPlan();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsDraft(): void
    {
        $entity = new BusinessContinuityPlan();
        $this->assertSame('draft', $entity->getStatus(), 'Default must match workflow initial_marking');
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new BusinessContinuityPlan();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasTenantMethodRequiredByTenantGuard(): void
    {
        $entity = new BusinessContinuityPlan();
        $this->assertTrue(method_exists($entity, 'getTenant'));
    }

    #[Test]
    public function statusAcceptsAllFourWorkflowPlaces(): void
    {
        $entity = new BusinessContinuityPlan();
        $places = ['draft', 'under_review', 'active', 'archived'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('business-continuity-plan');
        $this->assertNotNull($entry, "'business-continuity-plan' slug must be registered");
        $this->assertSame(BusinessContinuityPlan::class, $entry['class']);
        $this->assertSame('business_continuity_plan_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesBusinessContinuityPlan(): void
    {
        $registry = new EntityTypeRegistry();
        $this->assertContains('business-continuity-plan', $registry->knownSlugs());
    }

    #[Test]
    public function workflowYamlExistsForBusinessContinuityPlanLifecycle(): void
    {
        $yamlPath = dirname(__DIR__, 2) . '/config/workflows/business_continuity_plan.yaml';
        $this->assertFileExists($yamlPath, 'Workflow YAML must exist');
        $contents = (string) file_get_contents($yamlPath);
        $this->assertStringContainsString('business_continuity_plan_lifecycle:', $contents);
        $this->assertStringContainsString('App\\Entity\\BusinessContinuityPlan', $contents);
        $this->assertStringContainsString('initial_marking: draft', $contents);
    }

    #[Test]
    public function workflowYamlDeclaresFourEyesOnActivateAndArchive(): void
    {
        $yamlPath = dirname(__DIR__, 2) . '/config/workflows/business_continuity_plan.yaml';
        $yaml = (string) file_get_contents($yamlPath);
        // BSI 200-4 requires 4-eyes for going-live and retiring a plan.
        $activatePos = strpos($yaml, 'activate:');
        $this->assertNotFalse($activatePos, 'activate transition must exist');
        $activateBlock = substr($yaml, $activatePos, 400);
        $this->assertStringContainsString('four_eyes: true', $activateBlock, 'activate must require four_eyes');

        $archivePos = strpos($yaml, 'archive:');
        $this->assertNotFalse($archivePos, 'archive transition must exist');
        $archiveBlock = substr($yaml, $archivePos, 400);
        $this->assertStringContainsString('four_eyes: true', $archiveBlock, 'archive must require four_eyes');
    }
}
