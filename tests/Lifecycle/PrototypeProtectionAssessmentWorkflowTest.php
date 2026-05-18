<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\PrototypeProtectionAssessment;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle Sprint Y.5 PR B — Entity-shape tests for the
 * `prototype_protection_assessment_lifecycle` Symfony Workflow state-machine.
 *
 * TISAX / VDA-ISA 6.0 Kapitel 8 (Prototype Protection). The approved
 * assessment is the audit-evidence that underpins the TISAX prototype-
 * protection label claim — `approve` is the load-bearing transition.
 *
 * Places (5): draft → in_review → approved | rejected; approved → expired;
 * rejected → draft (reopen); expired → draft (renew).
 * Four-eyes on: `approve` (label-issuance trigger).
 */
final class PrototypeProtectionAssessmentWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new PrototypeProtectionAssessment();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsDraft(): void
    {
        $entity = new PrototypeProtectionAssessment();
        $this->assertSame('draft', $entity->getStatus(), 'Default must match workflow initial_marking');
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new PrototypeProtectionAssessment();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasTenantMethodRequiredByTenantGuard(): void
    {
        $entity = new PrototypeProtectionAssessment();
        $this->assertTrue(method_exists($entity, 'getTenant'));
    }

    #[Test]
    public function statusAcceptsAllFiveWorkflowPlaces(): void
    {
        $entity = new PrototypeProtectionAssessment();
        $places = ['draft', 'in_review', 'approved', 'rejected', 'expired'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('prototype-protection-assessment');
        $this->assertNotNull($entry, "'prototype-protection-assessment' slug must be registered");
        $this->assertSame(PrototypeProtectionAssessment::class, $entry['class']);
        $this->assertSame('prototype_protection_assessment_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesPrototypeProtectionAssessment(): void
    {
        $registry = new EntityTypeRegistry();
        $this->assertContains('prototype-protection-assessment', $registry->knownSlugs());
    }

    #[Test]
    public function workflowYamlExistsForPrototypeProtectionAssessmentLifecycle(): void
    {
        $yamlPath = dirname(__DIR__, 2) . '/config/workflows/prototype_protection_assessment.yaml';
        $this->assertFileExists($yamlPath, 'Workflow YAML must exist');
        $contents = (string) file_get_contents($yamlPath);
        $this->assertStringContainsString('prototype_protection_assessment_lifecycle:', $contents);
        $this->assertStringContainsString('App\\Entity\\PrototypeProtectionAssessment', $contents);
        $this->assertStringContainsString('initial_marking: draft', $contents);
    }

    #[Test]
    public function workflowYamlDeclaresFourEyesOnApprove(): void
    {
        $yamlPath = dirname(__DIR__, 2) . '/config/workflows/prototype_protection_assessment.yaml';
        $yaml = (string) file_get_contents($yamlPath);
        // TISAX label-issuance trigger → 4-eyes required.
        $approvePos = strpos($yaml, 'approve:');
        $this->assertNotFalse($approvePos, 'approve transition must exist');
        $approveBlock = substr($yaml, $approvePos, 400);
        $this->assertStringContainsString('four_eyes: true', $approveBlock, 'approve must require four_eyes');
    }
}
