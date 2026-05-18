<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids direct ->setStatus() calls on lifecycle-managed entities from
 * production code. Status transitions must go through LifecycleService.
 *
 * Allowed contexts:
 *   - LifecycleService itself (delegating to Symfony Workflow)
 *   - Entity classes (internal setter implementations)
 *   - Migrations (data backfill)
 *   - Test fixtures
 *   - Workflow event listeners (Symfony Workflow marking-store)
 *
 * @implements Rule<MethodCall>
 */
final class NoDirectSetStatusRule implements Rule
{
    private const LIFECYCLE_ENTITIES = [
        'App\\Entity\\Document',
        'App\\Entity\\ProcessingActivity',
        'App\\Entity\\ISMSObjective',
        'App\\Entity\\PolicyTemplate',
        'App\\Entity\\Asset',
        'App\\Entity\\DataBreach',
        'App\\Entity\\Incident',
        'App\\Entity\\Risk',
        'App\\Entity\\DataProtectionImpactAssessment',
        'App\\Entity\\CorrectiveAction',
        'App\\Entity\\AuditFinding',
        'App\\Entity\\InternalAudit',
        'App\\Entity\\Vulnerability',
        'App\\Entity\\DataSubjectRequest',
        'App\\Entity\\Consent',
        // Sprint Y.5 — 10 additional lifecycle-managed entities
        'App\\Entity\\Training',
        'App\\Entity\\RiskTreatmentPlan',
        'App\\Entity\\Supplier',
        'App\\Entity\\PrototypeProtectionAssessment',
        'App\\Entity\\BusinessContinuityPlan',
        'App\\Entity\\Patch',
        'App\\Entity\\ManagementReview',
        'App\\Entity\\ChangeRequest',
        'App\\Entity\\ThreatIntelligence',
        'App\\Entity\\BCExercise',
    ];

    private const ALLOWED_FILE_PATTERNS = [
        '#/src/Entity/#',
        '#/src/Lifecycle/#',
        '#/migrations/#',
        '#/tests/#',
        '#WorkflowAutoProgressionService\\.php$#',  // legacy, slated for X.6 removal
        '#StateMachineMarkingStore#',                 // Symfony internal
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier || $node->name->name !== 'setStatus') {
            return [];
        }

        $type = $scope->getType($node->var);
        if (!$type->isObject()->yes()) {
            return [];
        }

        $classNames = $type->getObjectClassNames();
        $isLifecycleEntity = false;
        foreach ($classNames as $className) {
            foreach (self::LIFECYCLE_ENTITIES as $managed) {
                if ($className === $managed || is_subclass_of($className, $managed)) {
                    $isLifecycleEntity = true;
                    break 2;
                }
            }
        }

        if (!$isLifecycleEntity) {
            return [];
        }

        $file = $scope->getFile();
        foreach (self::ALLOWED_FILE_PATTERNS as $pattern) {
            if (preg_match($pattern, $file) === 1) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Direct ->setStatus() call on lifecycle-managed entity (%s) is forbidden. Use App\\Lifecycle\\LifecycleService::transition() instead.',
                $classNames[0] ?? '?',
            ))
            ->identifier('lifecycle.directSetStatus')
            ->build(),
        ];
    }
}
