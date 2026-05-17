<?php

declare(strict_types=1);

namespace LittleIsmsHelper\PhpStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * PHPStan rule: Prevent instantiation of deprecated Workflow/WorkflowStep entities.
 *
 * Fires an error when `new App\Entity\Workflow()` or `new App\Entity\WorkflowStep()`
 * appears in src/ code outside the Repository and Command namespaces.
 *
 * Exempt namespaces (allowed to instantiate for read-only/migration use):
 *   - App\Repository\*   (read-only ORM repository layer)
 *   - App\Command\*      (migration/verification commands only)
 *
 * Background:
 *   Since Sprint Y.4 (2026-06) the canonical source of truth for workflow
 *   definitions is config/workflows/regulatory/*.yaml. New Workflow and
 *   WorkflowStep entities MUST NOT be created; existing DB rows are preserved
 *   read-only for historical display.
 *
 * @see src/Entity/Workflow.php @deprecated notice
 * @see src/Entity/WorkflowStep.php @deprecated notice
 * @see docs/decisions/2026-05-17-workflow-yaml-unification.md
 *
 * @implements Rule<New_>
 */
class NoNewWorkflowOrWorkflowStep implements Rule
{
    private const DEPRECATED_CLASSES = [
        'App\\Entity\\Workflow',
        'App\\Entity\\WorkflowStep',
    ];

    /**
     * Namespace prefixes that are allowed to instantiate the deprecated classes.
     *
     * - Repository: read-only ORM layer, allowed to persist/hydrate existing rows
     * - Command: migration/verification commands (MigrateLegacyWorkflowsCommand, GenerateRegulatoryWorkflowsCommand)
     * - Controller\Api\WorkflowStepApiController: already @deprecated since Y.3 —
     *   kept for one-release backward compat; returns 410 Gone on write methods.
     *   Exempted here to avoid flagging deprecated-for-deprecated code.
     */
    private const EXEMPT_NAMESPACE_PREFIXES = [
        'App\\Repository\\',
        'App\\Command\\',
        'App\\Controller\\Api\\',  // WorkflowStepApiController deprecated — see Y.3
    ];

    public function getNodeType(): string
    {
        return New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof New_) {
            return [];
        }

        $class = $node->class;
        if (!$class instanceof Name) {
            return [];
        }

        $fullyQualifiedName = $class->toString();

        // Check if this resolves to a deprecated class name (full or short form)
        $isDeprecated = false;
        foreach (self::DEPRECATED_CLASSES as $deprecatedClass) {
            if ($fullyQualifiedName === $deprecatedClass) {
                $isDeprecated = true;
                break;
            }
            // Handle short name (in same namespace context)
            $shortName = substr($deprecatedClass, strrpos($deprecatedClass, '\\') + 1);
            if ($fullyQualifiedName === $shortName) {
                // Resolve via scope namespace
                $namespacedName = $scope->getNamespace() !== null
                    ? $scope->getNamespace() . '\\' . $shortName
                    : $shortName;
                if ($namespacedName === $deprecatedClass) {
                    $isDeprecated = true;
                    break;
                }
            }
        }

        if (!$isDeprecated) {
            return [];
        }

        // Check if current namespace is in the exempt list
        $currentNamespace = $scope->getNamespace();
        foreach (self::EXEMPT_NAMESPACE_PREFIXES as $exemptPrefix) {
            if ($currentNamespace !== null && str_starts_with($currentNamespace . '\\', $exemptPrefix)) {
                return [];
            }
        }

        $shortClassName = substr($fullyQualifiedName, strrpos($fullyQualifiedName, '\\') + 1);

        return [
            RuleErrorBuilder::message(sprintf(
                'Instantiation of deprecated entity %s is not allowed in production code. '
                . 'Define workflows as YAML files in config/workflows/regulatory/ instead. '
                . 'See docs/decisions/2026-05-17-workflow-yaml-unification.md for details.',
                $shortClassName,
            ))
                ->identifier('app.deprecatedWorkflowEntityInstantiation')
                ->build(),
        ];
    }
}
