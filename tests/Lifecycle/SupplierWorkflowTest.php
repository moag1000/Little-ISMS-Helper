<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Supplier;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Lifecycle PR C — Unit tests for Supplier state-machine.
 *
 * Workflow definition: config/workflows/supplier.yaml
 *   Places: active, inactive, evaluation, terminated (4)
 *   Transitions: evaluation_passed / evaluation_failed / request_evaluation /
 *                deactivate / reactivate / terminate (6)
 *
 * Scope (ISO 27001 Annex A):
 *   - A.5.19 Information security in supplier relationships
 *   - A.5.20 Addressing information security within supplier agreements
 *   - A.5.21 Managing information security in the ICT supply chain
 *   - A.5.22 Monitoring, review and change management of supplier services
 *
 * Four-eyes is enforced on `terminate` (contractual termination — irreversible).
 *
 * Behaviour-level transition tests (guards, role enforcement, four-eyes
 * second-approver flow) live in LifecycleControllerTest at the HTTP layer.
 * This suite pins the entity-shape + YAML contract the workflow infrastructure
 * depends on.
 */
final class SupplierWorkflowTest extends TestCase
{
    private const string WORKFLOW_YAML = __DIR__ . '/../../config/workflows/supplier.yaml';

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
        // New suppliers start in evaluation — they must pass initial assessment
        // before being marked active (ISO 27001 A.5.19 — due diligence).
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
        $places = ['active', 'inactive', 'evaluation', 'terminated'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
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
    public function isOperationalReturnsTrueOnlyForActiveStatus(): void
    {
        // Domain decision pinned in Supplier::isOperational() docblock: only
        // `active` counts as operational for KPIs / repository filters.
        $entity = new Supplier();

        $entity->setStatus('active');
        $this->assertTrue($entity->isOperational(), "'active' must be operational");
    }

    #[Test]
    public function isOperationalReturnsFalseForNonActiveStatuses(): void
    {
        $entity = new Supplier();
        $nonOperational = ['inactive', 'evaluation', 'terminated'];

        foreach ($nonOperational as $place) {
            $entity->setStatus($place);
            $this->assertFalse(
                $entity->isOperational(),
                "'$place' must NOT be operational (only 'active' counts as operational)",
            );
        }
    }

    /**
     * Pin the YAML workflow contract — guards regression of the four ISO
     * 27001 A.5.19–A.5.22 places. If a future PR changes the places set,
     * this assertion forces a deliberate review.
     */
    #[Test]
    public function workflowYamlDeclaresExpectedFourPlaces(): void
    {
        $config = Yaml::parseFile(self::WORKFLOW_YAML);
        $workflow = $config['framework']['workflows']['supplier_lifecycle'] ?? null;
        $this->assertIsArray($workflow, 'supplier_lifecycle workflow must be defined in YAML');

        $this->assertSame('state_machine', $workflow['type']);
        $this->assertSame('evaluation', $workflow['initial_marking']);
        $this->assertSame([Supplier::class], $workflow['supports']);

        $expected = ['active', 'inactive', 'evaluation', 'terminated'];
        sort($expected);
        $actual = $workflow['places'];
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * Pin the transition set — six transitions matching the ISO 27001 A.5.19–A.5.22
     * supplier relationship lifecycle (deactivate, reactivate, request_evaluation,
     * evaluation_passed, evaluation_failed, terminate).
     */
    #[Test]
    public function workflowYamlDeclaresExpectedSixTransitions(): void
    {
        $config = Yaml::parseFile(self::WORKFLOW_YAML);
        $transitions = $config['framework']['workflows']['supplier_lifecycle']['transitions'] ?? [];

        $expected = [
            'evaluation_passed',
            'evaluation_failed',
            'request_evaluation',
            'deactivate',
            'reactivate',
            'terminate',
        ];
        sort($expected);
        $actual = array_keys($transitions);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * Four-eyes scope: `terminate` is contractual termination of the supplier
     * relationship (ISO 27001 A.5.20) — irreversible, must require two
     * approvers + a reason. No other supplier transition is gated this way.
     */
    #[Test]
    public function terminateTransitionRequiresFourEyesAndReason(): void
    {
        $config = Yaml::parseFile(self::WORKFLOW_YAML);
        $terminate = $config['framework']['workflows']['supplier_lifecycle']['transitions']['terminate'] ?? null;

        $this->assertIsArray($terminate, 'terminate transition must be defined');
        $this->assertSame('terminated', $terminate['to']);

        $meta = $terminate['metadata'] ?? [];
        $this->assertTrue(
            $meta['four_eyes'] ?? false,
            'terminate must require four-eyes (ISO 27001 A.5.20 — contractual termination is irreversible)',
        );
        $this->assertTrue(
            $meta['reason_required'] ?? false,
            'terminate must require a reason (audit trail for terminated supplier relationship)',
        );
    }

    /**
     * Reason-required (without four-eyes) on deactivate, evaluation_failed,
     * request_evaluation — every status change away from `active` or a
     * negative evaluation outcome carries documentation requirements.
     * Reactivate is the only soft-transition without reason (re-engagement).
     */
    #[Test]
    public function reasonRequiredTransitionsAreScopedCorrectly(): void
    {
        $config = Yaml::parseFile(self::WORKFLOW_YAML);
        $transitions = $config['framework']['workflows']['supplier_lifecycle']['transitions'];

        $reasonRequired = ['deactivate', 'evaluation_failed', 'request_evaluation', 'terminate'];
        foreach ($reasonRequired as $name) {
            $this->assertTrue(
                $transitions[$name]['metadata']['reason_required'] ?? false,
                "Transition '$name' must require a reason",
            );
        }

        // Reactivate + evaluation_passed are positive transitions — no reason
        // expected (audit trail still captures the actor + timestamp).
        $this->assertFalse(
            $transitions['reactivate']['metadata']['reason_required'] ?? false,
            'reactivate is a positive transition and must NOT require a reason',
        );
        $this->assertFalse(
            $transitions['evaluation_passed']['metadata']['reason_required'] ?? false,
            'evaluation_passed is a positive transition and must NOT require a reason',
        );
    }

    /**
     * Terminate is reachable from active, inactive, evaluation (all
     * non-terminal places). This is the supplier-management contract — once
     * a supplier is being onboarded or already in use, the relationship can
     * always be ended for cause.
     */
    #[Test]
    public function terminateIsReachableFromAllNonTerminalPlaces(): void
    {
        $config = Yaml::parseFile(self::WORKFLOW_YAML);
        $from = $config['framework']['workflows']['supplier_lifecycle']['transitions']['terminate']['from'];

        $expected = ['active', 'inactive', 'evaluation'];
        sort($expected);
        $actual = is_array($from) ? $from : [$from];
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}
