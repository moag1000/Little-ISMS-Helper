<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\ManagementReview;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle Y.5 PR-A — Unit tests for ManagementReview lifecycle.
 *
 * ISO 27001 Cl. 9.3 — Management Review (central audit obligation).
 *
 * Places: planned → completed → follow_up_required (loops back to completed)
 *
 * 4-eyes + reason_required on: complete
 */
final class ManagementReviewWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new ManagementReview();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPlanned(): void
    {
        $entity = new ManagementReview();
        $this->assertSame('planned', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new ManagementReview();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllThreeWorkflowPlaces(): void
    {
        $entity = new ManagementReview();
        $places = ['planned', 'completed', 'follow_up_required'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('management-review');
        $this->assertNotNull($entry, "'management-review' slug must be registered in EntityTypeRegistry");
        $this->assertSame(ManagementReview::class, $entry['class']);
        $this->assertSame('management_review_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesManagementReview(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('management-review', $slugs);
    }

    #[Test]
    public function workflowYamlDefinesFourEyesAndReasonRequiredOnComplete(): void
    {
        $yamlPath = \dirname(__DIR__, 2) . '/config/workflows/management_review.yaml';
        $this->assertFileExists($yamlPath);
        $contents = (string) file_get_contents($yamlPath);

        $this->assertMatchesRegularExpression(
            '/complete:[\s\S]*?four_eyes:\s*true/',
            $contents,
            "Transition 'complete' must have four_eyes: true"
        );
        $this->assertMatchesRegularExpression(
            '/complete:[\s\S]*?reason_required:\s*true/',
            $contents,
            "Transition 'complete' must require a reason"
        );
    }
}
