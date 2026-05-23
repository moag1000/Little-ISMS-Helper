<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Supplier;
use App\Enum\SupplierStatus;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Junior-ISB-Audit-2026-05-22 K-04 — Wave Y.6 Supplier-Lifecycle test.
 *
 * The Sprint Y.5 foundation already wired the four-stage Supplier
 * state-machine (evaluation → active ⇄ inactive → terminated) and pinned
 * the entity-shape via SprintY5LifecycleShapeTest. K-04 closes the
 * remaining gap: every `terminate_*` transition — including the early
 * `terminate_from_evaluation` — must require four-eyes per DORA Art. 28.
 *
 * This suite pins:
 *   1. Entity defaults (status, lock_version) for marking-store bootstrap.
 *   2. Supplier slug registration in EntityTypeRegistry.
 *   3. YAML contract: places = {evaluation, active, inactive, terminated}.
 *   4. YAML contract: all three `terminate_*` transitions carry four_eyes:true.
 *   5. YAML contract: all three `terminate_*` transitions require a reason.
 *
 * Behaviour-level transition tests (FourEyesValidator denial, RBAC guard)
 * are exercised by tests/Lifecycle/EventListener/FourEyesValidatorTest and
 * tests/Lifecycle/Guard/* — that infrastructure already covers any
 * transition whose YAML carries `four_eyes: true`, so adding the flag is
 * the only K-04 surface area we need to lock down here.
 */
final class SupplierWorkflowTest extends TestCase
{
    private const string YAML_PATH = __DIR__ . '/../../config/workflows/supplier.yaml';

    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new Supplier();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsEvaluation(): void
    {
        $entity = new Supplier();
        $this->assertSame('evaluation', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new Supplier();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasTenantMethodRequiredByTenantGuard(): void
    {
        $entity = new Supplier();
        $this->assertTrue(method_exists($entity, 'getTenant'));
    }

    #[Test]
    public function statusAcceptsAllFourWorkflowPlaces(): void
    {
        $entity = new Supplier();
        $places = ['evaluation', 'active', 'inactive', 'terminated'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function setStatusAcceptsBackedEnum(): void
    {
        $entity = new Supplier();
        $entity->setStatus(SupplierStatus::Active);
        $this->assertSame('active', $entity->getStatus());
        $this->assertSame(SupplierStatus::Active, $entity->getStatusEnum());
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('supplier');
        $this->assertNotNull($entry, "'supplier' slug must be registered in EntityTypeRegistry");
        $this->assertSame(Supplier::class, $entry['class']);
        $this->assertSame('supplier_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesSupplier(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('supplier', $slugs);
    }

    #[Test]
    public function yamlDefinesExactlyFourPlaces(): void
    {
        $places = $this->workflowConfig()['places'];
        $this->assertEqualsCanonicalizing(
            ['evaluation', 'active', 'inactive', 'terminated'],
            $places,
            'Supplier workflow must define exactly the four canonical places.'
        );
    }

    #[Test]
    public function yamlInitialMarkingIsEvaluation(): void
    {
        $this->assertSame('evaluation', $this->workflowConfig()['initial_marking']);
    }

    #[Test]
    public function yamlMarkingStoreIsMethodOnStatusProperty(): void
    {
        $store = $this->workflowConfig()['marking_store'];
        $this->assertSame('method', $store['type']);
        $this->assertSame('status', $store['property']);
    }

    #[Test]
    public function yamlSupportsSupplierEntity(): void
    {
        $supports = $this->workflowConfig()['supports'];
        $this->assertContains(Supplier::class, $supports);
    }

    /**
     * K-04 core contract: every transition that reaches `terminated` must be
     * four-eyes-protected, including the early `terminate_from_evaluation`
     * branch added by this task (DORA Art. 28 — early termination of a vetted
     * ICT-third-party candidate is regulator-visible).
     */
    #[Test]
    public function allTerminateTransitionsRequireFourEyes(): void
    {
        $transitions = $this->workflowConfig()['transitions'];
        $terminateNames = ['terminate_from_active', 'terminate_from_inactive', 'terminate_from_evaluation'];

        foreach ($terminateNames as $name) {
            $this->assertArrayHasKey(
                $name,
                $transitions,
                "Supplier workflow must define transition '$name' — terminated is the only terminal place."
            );
            $metadata = $transitions[$name]['metadata'] ?? [];
            $this->assertTrue(
                ($metadata['four_eyes'] ?? false) === true,
                "Transition '$name' must carry four_eyes: true (DORA Art. 28)."
            );
            $this->assertTrue(
                ($metadata['reason_required'] ?? false) === true,
                "Transition '$name' must require a reason (audit trail)."
            );
            $this->assertSame(
                'terminated',
                $transitions[$name]['to'],
                "Transition '$name' must transition to 'terminated'."
            );
        }
    }

    #[Test]
    public function terminateFromEvaluationIsK04Contract(): void
    {
        // Targeted contract: explicit guard against accidental regression
        // of K-04 specifically — separated from the broad sweep above so the
        // failure message points to the exact regression target.
        $transition = $this->workflowConfig()['transitions']['terminate_from_evaluation'] ?? null;
        $this->assertNotNull($transition, 'K-04: terminate_from_evaluation transition must exist.');
        $this->assertSame('evaluation', $transition['from']);
        $this->assertSame('terminated', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['four_eyes'] ?? false) === true,
            'K-04 regression: terminate_from_evaluation lost four_eyes: true (DORA Art. 28).'
        );
    }

    #[Test]
    public function activateTransitionLeavesEvaluation(): void
    {
        $activate = $this->workflowConfig()['transitions']['activate'];
        $this->assertSame('evaluation', $activate['from']);
        $this->assertSame('active', $activate['to']);
        $this->assertTrue(($activate['metadata']['reason_required'] ?? false) === true);
    }

    #[Test]
    public function suspendAndReactivateRoundTrip(): void
    {
        $transitions = $this->workflowConfig()['transitions'];
        $this->assertSame('active', $transitions['suspend']['from']);
        $this->assertSame('inactive', $transitions['suspend']['to']);
        $this->assertSame('inactive', $transitions['reactivate']['from']);
        $this->assertSame('active', $transitions['reactivate']['to']);
    }

    /** @return array<string, mixed> */
    private function workflowConfig(): array
    {
        $parsed = Yaml::parseFile(self::YAML_PATH);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('framework', $parsed);
        $this->assertArrayHasKey('workflows', $parsed['framework']);
        $this->assertArrayHasKey('supplier_lifecycle', $parsed['framework']['workflows']);

        return $parsed['framework']['workflows']['supplier_lifecycle'];
    }
}
