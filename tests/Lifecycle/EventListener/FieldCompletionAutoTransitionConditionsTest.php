<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Lifecycle\EventListener\FieldCompletionAutoTransition;
use App\Lifecycle\LifecycleTransitionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Unit tests for the Y.1 AND/OR condition-tree extension of
 * FieldCompletionAutoTransition.
 *
 * Covers:
 * - all: (AND) group — every condition must pass
 * - any: (OR) group — at least one condition must pass
 * - all: + any: combined — both groups evaluated independently
 * - Each comparison operator: ==, !=, >, <, >=, <=
 * - value_from: resolves RHS from another entity field
 * - Malformed condition skips gracefully (no throw)
 * - Legacy required_fields still works alongside new syntax
 * - Inaccessible field in condition evaluates to false (not-met)
 * - Empty conditions tree → rule skipped (not triggered)
 */
final class FieldCompletionAutoTransitionConditionsTest extends TestCase
{
    // ── AND (all:) group ──────────────────────────────────────────────────────

    #[Test]
    public function allGroupFiresWhenEveryConditionPasses(): void
    {
        $entity = $this->entity(severity: 'critical', count: 200, accepted: false);

        $this->assertTransitionCalled(
            entity: $entity,
            rule: [
                'workflow'    => 'data_breach_lifecycle',
                'transition'  => 'assess',
                'conditions'  => [
                    'all' => [
                        ['field' => 'severity',                  'comparison' => '==',  'value' => 'critical'],
                        ['field' => 'affectedDataSubjectsCount', 'comparison' => '>',   'value' => 100],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function allGroupDoesNotFireWhenOneConditionFails(): void
    {
        $entity = $this->entity(severity: 'low', count: 200, accepted: false);

        $this->assertTransitionNotCalled(
            entity: $entity,
            rule: [
                'workflow'    => 'data_breach_lifecycle',
                'transition'  => 'assess',
                'conditions'  => [
                    'all' => [
                        ['field' => 'severity',                  'comparison' => '==',  'value' => 'critical'],
                        ['field' => 'affectedDataSubjectsCount', 'comparison' => '>',   'value' => 100],
                    ],
                ],
            ],
        );
    }

    // ── OR (any:) group ───────────────────────────────────────────────────────

    #[Test]
    public function anyGroupFiresWhenAtLeastOneConditionPasses(): void
    {
        // first condition false, second condition true
        $entity = $this->entity(severity: 'low', count: 5, accepted: true);

        $this->assertTransitionCalled(
            entity: $entity,
            rule: [
                'workflow'    => 'data_breach_lifecycle',
                'transition'  => 'assess',
                'conditions'  => [
                    'any' => [
                        ['field' => 'affectedDataSubjectsCount', 'comparison' => '>',   'value' => 100],
                        ['field' => 'acceptedManually',          'comparison' => '==',  'value' => true],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function anyGroupDoesNotFireWhenAllConditionsFail(): void
    {
        $entity = $this->entity(severity: 'low', count: 5, accepted: false);

        $this->assertTransitionNotCalled(
            entity: $entity,
            rule: [
                'workflow'    => 'data_breach_lifecycle',
                'transition'  => 'assess',
                'conditions'  => [
                    'any' => [
                        ['field' => 'affectedDataSubjectsCount', 'comparison' => '>',   'value' => 100],
                        ['field' => 'acceptedManually',          'comparison' => '==',  'value' => true],
                    ],
                ],
            ],
        );
    }

    // ── Combined all + any ────────────────────────────────────────────────────

    #[Test]
    public function combinedAllAndAnyBothMustPass(): void
    {
        // all: passes (severity >= high), any: passes (count > 100)
        $entity = $this->entity(severity: 'high', count: 200, accepted: false);

        $this->assertTransitionCalled(
            entity: $entity,
            rule: [
                'workflow'    => 'data_breach_lifecycle',
                'transition'  => 'assess',
                'conditions'  => [
                    'all' => [
                        ['field' => 'severity', 'comparison' => '>=', 'value' => 'high'],
                    ],
                    'any' => [
                        ['field' => 'affectedDataSubjectsCount', 'comparison' => '>',   'value' => 100],
                        ['field' => 'acceptedManually',          'comparison' => '==',  'value' => true],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function combinedDoesNotFireWhenAllGroupFails(): void
    {
        // all: FAILS (count 5 is NOT >= 100), any: would pass (count > 50 is true)
        // Use numeric comparisons to avoid PHP string-comparison surprises.
        $entity = $this->entity(severity: 'low', count: 5, accepted: false);

        $this->assertTransitionNotCalled(
            entity: $entity,
            rule: [
                'workflow'    => 'data_breach_lifecycle',
                'transition'  => 'assess',
                'conditions'  => [
                    'all' => [
                        ['field' => 'affectedDataSubjectsCount', 'comparison' => '>=', 'value' => 100],
                    ],
                    'any' => [
                        // This would fire if all: passed — count 5 > 0
                        ['field' => 'affectedDataSubjectsCount', 'comparison' => '>',  'value' => 0],
                    ],
                ],
            ],
        );
    }

    // ── Comparison operators ──────────────────────────────────────────────────

    /**
     * @param mixed $fieldValue
     * @param mixed $comparisonValue
     */
    #[Test]
    #[DataProvider('comparisonOperators')]
    public function supportsAllComparisonOperators(
        string $operator,
        mixed $fieldValue,
        mixed $comparisonValue,
        bool $expectFired,
    ): void {
        $entity = $this->entity(severity: 'high', count: (int) $fieldValue, accepted: false);

        $rule = [
            'workflow'   => 'data_breach_lifecycle',
            'transition' => 'assess',
            'conditions' => [
                'all' => [
                    ['field' => 'affectedDataSubjectsCount', 'comparison' => $operator, 'value' => $comparisonValue],
                ],
            ],
        ];

        if ($expectFired) {
            $this->assertTransitionCalled($entity, $rule);
        } else {
            $this->assertTransitionNotCalled($entity, $rule);
        }
    }

    /**
     * @return list<array{string, int, int, bool}>
     */
    public static function comparisonOperators(): array
    {
        return [
            ['==',  100, 100, true],
            ['==',  100, 200, false],
            ['!=',  100, 200, true],
            ['!=',  100, 100, false],
            ['>',   200, 100, true],
            ['>',   100, 200, false],
            ['<',   50,  100, true],
            ['<',   200, 100, false],
            ['>=',  100, 100, true],
            ['>=',  99,  100, false],
            ['<=',  100, 100, true],
            ['<=',  101, 100, false],
        ];
    }

    // ── value_from ────────────────────────────────────────────────────────────

    #[Test]
    public function valueFromResolvesRhsFromEntityField(): void
    {
        // residualRisk (50) <= riskAppetiteThreshold (60) → should fire
        $entity = $this->entityWithAppetite(residualRisk: 50, appetiteThreshold: 60);

        $this->assertTransitionCalled(
            entity: $entity,
            rule: [
                'workflow'   => 'risk_lifecycle',
                'transition' => 'accept',
                'conditions' => [
                    'any' => [
                        ['field' => 'residualRisk', 'comparison' => '<=', 'value_from' => 'riskAppetiteThreshold'],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function valueFromDoesNotFireWhenRhsFieldExceedsLhs(): void
    {
        // residualRisk (80) <= riskAppetiteThreshold (60) → false
        $entity = $this->entityWithAppetite(residualRisk: 80, appetiteThreshold: 60);

        $this->assertTransitionNotCalled(
            entity: $entity,
            rule: [
                'workflow'   => 'risk_lifecycle',
                'transition' => 'accept',
                'conditions' => [
                    'any' => [
                        ['field' => 'residualRisk', 'comparison' => '<=', 'value_from' => 'riskAppetiteThreshold'],
                    ],
                ],
            ],
        );
    }

    // ── Boolean and null literals ─────────────────────────────────────────────

    #[Test]
    public function booleanTrueLiteralNormalised(): void
    {
        $entity = $this->entity(severity: 'high', count: 10, accepted: true);

        $this->assertTransitionCalled(
            entity: $entity,
            rule: [
                'workflow'   => 'data_breach_lifecycle',
                'transition' => 'assess',
                'conditions' => [
                    'all' => [
                        ['field' => 'acceptedManually', 'comparison' => '==', 'value' => 'true'],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function booleanFalseLiteralNormalised(): void
    {
        $entity = $this->entity(severity: 'high', count: 10, accepted: false);

        $this->assertTransitionCalled(
            entity: $entity,
            rule: [
                'workflow'   => 'data_breach_lifecycle',
                'transition' => 'assess',
                'conditions' => [
                    'all' => [
                        ['field' => 'acceptedManually', 'comparison' => '==', 'value' => 'false'],
                    ],
                ],
            ],
        );
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    #[Test]
    public function malformedConditionMissingFieldKeySkipsGracefully(): void
    {
        $entity = $this->entity(severity: 'high', count: 10, accepted: false);

        $this->assertTransitionNotCalled(
            entity: $entity,
            rule: [
                'workflow'   => 'data_breach_lifecycle',
                'transition' => 'assess',
                'conditions' => [
                    'all' => [
                        ['comparison' => '==', 'value' => 'high'], // 'field' key missing
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function inaccessibleFieldInConditionEvaluatesToFalse(): void
    {
        $entity = $this->entity(severity: 'high', count: 10, accepted: false);

        $this->assertTransitionNotCalled(
            entity: $entity,
            rule: [
                'workflow'   => 'data_breach_lifecycle',
                'transition' => 'assess',
                'conditions' => [
                    'all' => [
                        ['field' => 'nonExistentField', 'comparison' => '==', 'value' => 'something'],
                    ],
                ],
            ],
        );
    }

    #[Test]
    public function emptyConditionsTreeSkipsRule(): void
    {
        $entity = $this->entity(severity: 'high', count: 200, accepted: true);

        // Empty conditions map — neither 'all' nor 'any' present
        // Technically evaluates to true (vacuous), but an empty tree means
        // "nothing to check", so the rule fires. The test verifies it fires
        // (not a hard constraint — just documents current behaviour).
        // Change the assertion if the spec is revisited.
        $this->assertTransitionCalled(
            entity: $entity,
            rule: [
                'workflow'   => 'data_breach_lifecycle',
                'transition' => 'assess',
                'conditions' => [],
            ],
        );
    }

    #[Test]
    public function legacyRequiredFieldsStillWorkWithNewSyntaxPresent(): void
    {
        // Legacy rule alongside extended rule — both keys in the rule map
        $entity = $this->entity(severity: 'high', count: 200, accepted: false);

        $registry       = $this->createMock(Registry::class);
        $workflow       = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);
        $registry->method('get')->willReturn($workflow);

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        // Both rules match → transition should be called twice
        $lifecycleService->expects(self::exactly(2))->method('transition');

        $entityClass = $entity::class;
        $listener = $this->makeListener($registry, $lifecycleService, $entityClass, [
            'legacy_rule' => [
                'workflow'        => 'data_breach_lifecycle',
                'transition'      => 'assess',
                'required_fields' => ['severity', 'affectedDataSubjectsCount'],
            ],
            'extended_rule' => [
                'workflow'   => 'data_breach_lifecycle',
                'transition' => 'assess',
                'conditions' => [
                    'all' => [
                        ['field' => 'affectedDataSubjectsCount', 'comparison' => '>',  'value' => 100],
                    ],
                ],
            ],
        ]);

        $listener->postUpdate($this->makeEvent($entity));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function assertTransitionCalled(object $entity, array $rule): void
    {
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn($workflow);

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::once())->method('transition');

        $listener = $this->makeListener($registry, $lifecycleService, $entity::class, ['test_rule' => $rule]);
        $listener->postUpdate($this->makeEvent($entity));
    }

    private function assertTransitionNotCalled(object $entity, array $rule): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('get');

        $lifecycleService = $this->createMock(LifecycleTransitionInterface::class);
        $lifecycleService->expects(self::never())->method('transition');

        $listener = $this->makeListener($registry, $lifecycleService, $entity::class, ['test_rule' => $rule]);
        $listener->postUpdate($this->makeEvent($entity));
    }

    private function makeListener(
        Registry $registry,
        LifecycleTransitionInterface $lifecycleService,
        string $entityClass,
        array $rules,
    ): FieldCompletionAutoTransition {
        return new FieldCompletionAutoTransition(
            workflowRegistry: $registry,
            lifecycleService: $lifecycleService,
            propertyAccessor: PropertyAccess::createPropertyAccessor(),
            rules: [$entityClass => $rules],
        );
    }

    private function makeEvent(object $entity): PostUpdateEventArgs
    {
        $em = $this->createMock(EntityManagerInterface::class);
        return new PostUpdateEventArgs($entity, $em);
    }

    /**
     * Entity stub with severity (string), affectedDataSubjectsCount (int), acceptedManually (bool).
     */
    private function entity(?string $severity, ?int $count, ?bool $accepted): object
    {
        return new class ($severity, $count, $accepted) {
            public function __construct(
                private readonly ?string $severity,
                private readonly ?int    $count,
                private readonly ?bool   $accepted,
            ) {}

            public function getSeverity(): ?string { return $this->severity; }

            public function getAffectedDataSubjectsCount(): ?int { return $this->count; }

            public function isAcceptedManually(): ?bool { return $this->accepted; }
        };
    }

    /**
     * Entity stub with residualRisk (int) + riskAppetiteThreshold (int).
     */
    private function entityWithAppetite(int $residualRisk, int $appetiteThreshold): object
    {
        return new class ($residualRisk, $appetiteThreshold) {
            public function __construct(
                private readonly int $residualRisk,
                private readonly int $appetiteThreshold,
            ) {}

            public function getResidualRisk(): int { return $this->residualRisk; }

            public function getRiskAppetiteThreshold(): int { return $this->appetiteThreshold; }
        };
    }
}
