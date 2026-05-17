<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\PolicyTemplate;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle — Unit tests for PolicyTemplate workflow shape.
 *
 * Pins the entity-level assumptions the policy_template_lifecycle
 * state-machine depends on (status field, lock_version, initial_marking).
 *
 * Integration tests (guard checks, HTTP transitions) belong in
 * LifecycleControllerTest.
 */
final class PolicyTemplateWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new PolicyTemplate();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsDraft(): void
    {
        $entity = new PolicyTemplate();
        $this->assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new PolicyTemplate();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllWorkflowPlaces(): void
    {
        $entity = new PolicyTemplate();
        $places = ['draft', 'in_review', 'approved', 'published', 'archived'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('policy-template');
        $this->assertNotNull($entry, "'policy-template' slug must be registered");
        $this->assertSame(PolicyTemplate::class, $entry['class']);
        $this->assertSame('policy_template_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesPolicyTemplate(): void
    {
        $registry = new EntityTypeRegistry();
        $this->assertContains('policy-template', $registry->knownSlugs());
    }
}
