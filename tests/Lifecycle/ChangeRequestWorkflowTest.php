<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\ChangeRequest;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle Y.5 PR-A — Unit tests for ChangeRequest lifecycle.
 *
 * ISO 27001 A.8.32 (Change Management) + DORA Art. 16.
 *
 * Places: draft → submitted → under_review → approved | rejected →
 *         scheduled → implemented → verified → closed
 * Rework: rejected → draft (reason_required)
 *
 * 4-eyes on: approve, implemented, verified
 */
final class ChangeRequestWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new ChangeRequest();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsDraft(): void
    {
        $entity = new ChangeRequest();
        $this->assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new ChangeRequest();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllNineWorkflowPlaces(): void
    {
        $entity = new ChangeRequest();
        $places = [
            'draft', 'submitted', 'under_review', 'approved', 'rejected',
            'scheduled', 'implemented', 'verified', 'closed',
        ];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('change-request');
        $this->assertNotNull($entry, "'change-request' slug must be registered in EntityTypeRegistry");
        $this->assertSame(ChangeRequest::class, $entry['class']);
        $this->assertSame('change_request_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesChangeRequest(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('change-request', $slugs);
    }

    #[Test]
    public function workflowYamlExistsAndDefinesFourEyesOnApprove(): void
    {
        $yamlPath = \dirname(__DIR__, 2) . '/config/workflows/change_request.yaml';
        $this->assertFileExists($yamlPath);
        $contents = (string) file_get_contents($yamlPath);

        // 4-eyes transitions per spec
        foreach (['approve', 'implemented', 'verified'] as $transition) {
            $this->assertMatchesRegularExpression(
                '/' . $transition . ':[\s\S]*?four_eyes:\s*true/',
                $contents,
                "Transition '$transition' must have four_eyes: true"
            );
        }
    }

    #[Test]
    public function workflowYamlDefinesReasonRequiredOnReject(): void
    {
        $yamlPath = \dirname(__DIR__, 2) . '/config/workflows/change_request.yaml';
        $contents = (string) file_get_contents($yamlPath);
        $this->assertMatchesRegularExpression(
            '/reject:[\s\S]*?reason_required:\s*true/',
            $contents,
            "Transition 'reject' must require a reason"
        );
    }
}
