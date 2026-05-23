<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\CorrectiveAction;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for CorrectiveAction custom lifecycle (CAPA).
 *
 * Places: planned → in_progress → completed → verified → verified_effective | verified_ineffective
 *         planned → cancelled (ROLE_MANAGER, reason_required)
 *         verified_ineffective → in_progress (retry, ROLE_MANAGER)
 *
 * ISO 27001 Cl. 10.1 — corrective action and continual improvement.
 *
 * Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: stage count extended 6 → 7
 * with the new intermediate `verified` place — forced `completed → verify`
 * step before any verdict (ISO 27001 Cl. 10.1 d).
 */
final class CorrectiveActionWorkflowTest extends TestCase
{
    private const string YAML_PATH = __DIR__ . '/../../config/workflows/corrective_action.yaml';

    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new CorrectiveAction();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPlanned(): void
    {
        $entity = new CorrectiveAction();
        $this->assertSame('planned', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new CorrectiveAction();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllSevenWorkflowPlaces(): void
    {
        $entity = new CorrectiveAction();
        $places = ['planned', 'in_progress', 'completed', 'verified', 'verified_effective', 'verified_ineffective', 'cancelled'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('corrective-action');
        $this->assertNotNull($entry, "'corrective-action' slug must be registered in EntityTypeRegistry");
        $this->assertSame(CorrectiveAction::class, $entry['class']);
        $this->assertSame('corrective_action_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesCorrectiveAction(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('corrective-action', $slugs);
    }

    #[Test]
    public function yamlDefinesSevenPlaces(): void
    {
        // Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: pin the new 7-place
        // shape so any future drift (especially accidental drop of the
        // intermediate `verified` place) breaks the build.
        $places = $this->workflowConfig()['places'];
        $this->assertEqualsCanonicalizing(
            ['planned', 'in_progress', 'completed', 'verified', 'verified_effective', 'verified_ineffective', 'cancelled'],
            $places,
        );
    }

    #[Test]
    public function yamlForcesCompletedThroughVerifyBeforeVerdict(): void
    {
        // Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: ISO 27001 Cl. 10.1 d
        // — `completed` MUST transition through the `verified` intermediate
        // place; no direct verdict from `completed`.
        $transitions = $this->workflowConfig()['transitions'];

        $this->assertArrayHasKey('verify', $transitions, 'verify transition must exist');
        $this->assertSame('completed', $transitions['verify']['from']);
        $this->assertSame('verified', $transitions['verify']['to']);

        $this->assertArrayHasKey('confirm_effective', $transitions);
        $this->assertSame('verified', $transitions['confirm_effective']['from']);
        $this->assertSame('verified_effective', $transitions['confirm_effective']['to']);
        $this->assertTrue(
            ($transitions['confirm_effective']['metadata']['four_eyes'] ?? false) === true,
            'confirm_effective verdict must keep four-eyes guard (Cl. 10.1 d).'
        );

        $this->assertArrayHasKey('confirm_ineffective', $transitions);
        $this->assertSame('verified', $transitions['confirm_ineffective']['from']);
        $this->assertSame('verified_ineffective', $transitions['confirm_ineffective']['to']);
        $this->assertTrue(
            ($transitions['confirm_ineffective']['metadata']['reason_required'] ?? false) === true,
            'confirm_ineffective verdict must require a reason.'
        );

        // Regression-guard: the old direct `completed → verified_*`
        // transitions must NOT exist any more.
        foreach (['verify_effective', 'verify_ineffective'] as $legacy) {
            $this->assertArrayNotHasKey(
                $legacy,
                $transitions,
                "Legacy transition '$legacy' must be replaced by `verify` + `confirm_*` (Cl. 10.1 d).",
            );
        }
    }

    /** @return array<string, mixed> */
    private function workflowConfig(): array
    {
        $parsed = \Symfony\Component\Yaml\Yaml::parseFile(self::YAML_PATH);
        $this->assertIsArray($parsed);
        return $parsed['framework']['workflows']['corrective_action_lifecycle'];
    }
}
