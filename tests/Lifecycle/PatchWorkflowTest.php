<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Patch;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle Y.5 PR-A — Unit tests for Patch lifecycle.
 *
 * ISO 27001 A.8.32 + A.8.8; NIS2 Art. 21(2)(e) — Vulnerability/patch management.
 *
 * Places: pending → testing → approved → deployed | failed | rolled_back
 * 4-eyes on: approve, deploy
 */
final class PatchWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new Patch();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPending(): void
    {
        $entity = new Patch();
        $this->assertSame('pending', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new Patch();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllSixWorkflowPlaces(): void
    {
        $entity = new Patch();
        $places = ['pending', 'testing', 'approved', 'deployed', 'failed', 'rolled_back'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('patch');
        $this->assertNotNull($entry, "'patch' slug must be registered in EntityTypeRegistry");
        $this->assertSame(Patch::class, $entry['class']);
        $this->assertSame('patch_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesPatch(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('patch', $slugs);
    }

    #[Test]
    public function workflowYamlDefinesFourEyesOnApproveAndDeploy(): void
    {
        $yamlPath = \dirname(__DIR__, 2) . '/config/workflows/patch.yaml';
        $this->assertFileExists($yamlPath);
        $contents = (string) file_get_contents($yamlPath);

        foreach (['approve', 'deploy'] as $transition) {
            $this->assertMatchesRegularExpression(
                '/^[ \t]+' . $transition . ':[\s\S]*?four_eyes:\s*true/m',
                $contents,
                "Transition '$transition' must have four_eyes: true"
            );
        }
    }

    #[Test]
    public function workflowYamlDefinesReasonRequiredOnRollback(): void
    {
        $yamlPath = \dirname(__DIR__, 2) . '/config/workflows/patch.yaml';
        $contents = (string) file_get_contents($yamlPath);
        $this->assertMatchesRegularExpression(
            '/rollback:[\s\S]*?reason_required:\s*true/',
            $contents,
            "Transition 'rollback' must require a reason"
        );
    }
}
