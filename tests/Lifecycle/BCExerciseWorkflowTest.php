<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\BCExercise;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle Sprint Y.5 PR B — Entity-shape tests for the
 * `bc_exercise_lifecycle` Symfony Workflow state-machine.
 *
 * ISO 22301 Cl. 8.5 (Exercise programme). Results of completed exercises are
 * the primary audit-evidence sampled at ISO 22301 surveillance audits.
 *
 * Places (4): planned → in_progress → completed; cancelled is terminal from
 * planned or in_progress; reopen completed → in_progress is admin-only.
 * Four-eyes on: `complete` (completion locks the results record).
 */
final class BCExerciseWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new BCExercise();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPlanned(): void
    {
        $entity = new BCExercise();
        $this->assertSame('planned', $entity->getStatus(), 'Default must match workflow initial_marking');
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new BCExercise();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasTenantMethodRequiredByTenantGuard(): void
    {
        $entity = new BCExercise();
        $this->assertTrue(method_exists($entity, 'getTenant'));
    }

    #[Test]
    public function statusAcceptsAllFourWorkflowPlaces(): void
    {
        $entity = new BCExercise();
        $places = ['planned', 'in_progress', 'completed', 'cancelled'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('bc-exercise');
        $this->assertNotNull($entry, "'bc-exercise' slug must be registered");
        $this->assertSame(BCExercise::class, $entry['class']);
        $this->assertSame('bc_exercise_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesBcExercise(): void
    {
        $registry = new EntityTypeRegistry();
        $this->assertContains('bc-exercise', $registry->knownSlugs());
    }

    #[Test]
    public function workflowYamlExistsForBcExerciseLifecycle(): void
    {
        $yamlPath = dirname(__DIR__, 2) . '/config/workflows/bc_exercise.yaml';
        $this->assertFileExists($yamlPath, 'Workflow YAML must exist');
        $contents = (string) file_get_contents($yamlPath);
        $this->assertStringContainsString('bc_exercise_lifecycle:', $contents);
        $this->assertStringContainsString('App\\Entity\\BCExercise', $contents);
        $this->assertStringContainsString('initial_marking: planned', $contents);
    }

    #[Test]
    public function workflowYamlDeclaresFourEyesOnComplete(): void
    {
        $yamlPath = dirname(__DIR__, 2) . '/config/workflows/bc_exercise.yaml';
        $yaml = (string) file_get_contents($yamlPath);
        // ISO 22301 Cl. 8.5 — completion locks results → 4-eyes required.
        $completePos = strpos($yaml, 'complete:');
        $this->assertNotFalse($completePos, 'complete transition must exist');
        $completeBlock = substr($yaml, $completePos, 400);
        $this->assertStringContainsString('four_eyes: true', $completeBlock, 'complete must require four_eyes');
    }
}
