<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule;

use App\Lifecycle\EntityTypeRegistry;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids direct ->setStatus() calls on lifecycle-managed entities from
 * production code. Status transitions must go through LifecycleService.
 *
 * The set of lifecycle-managed entities comes from
 * {@see EntityTypeRegistry::managedEntityClasses()} — registering an entity
 * there is what puts it under this rule; there is no second list to keep
 * in sync.
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
    private const ALLOWED_FILE_PATTERNS = [
        '#/src/Entity/#',
        '#/src/Lifecycle/#',
        '#/migrations/#',
        '#/tests/#',
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
            foreach (EntityTypeRegistry::managedEntityClasses() as $managed) {
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
