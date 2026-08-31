<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids putting a backed enum straight into a JSON response payload.
 *
 * json_encode() cannot serialise a non-JsonSerializable enum: it returns false,
 * the response body becomes "null" and the endpoint answers 500 — at runtime
 * only, on whichever branch happens to carry the enum.
 *
 * Replaces the textual check_enum_to_json_unwrap.py, which matched `getStatus()`
 * by name and therefore flagged five call sites whose getters all return string
 * (DataProtectionImpactAssessment, ProcessingActivity, MappingGapItem,
 * NotificationDelivery). Every one of its baseline entries was a false positive,
 * which is the failure mode that teaches people to baseline reflexively.
 *
 * This rule resolves the value's actual type instead, so it stays silent unless
 * the expression really is an enum.
 *
 * @implements Rule<Node>
 */
final class NoEnumInJsonResponseRule implements Rule
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $payload = $this->payloadArray($node);
        if ($payload === null) {
            return [];
        }

        $errors = [];
        foreach ($this->enumItems($payload, $scope) as [$key, $className]) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Backed enum %s is placed in a JSON payload%s. json_encode() cannot '
                . 'serialise it — the body becomes "null" and the endpoint answers 500. '
                . 'Pass ->value (or make the enum JsonSerializable).',
                $className,
                $key !== null ? sprintf(' under key "%s"', $key) : '',
            ))
            ->identifier('json.enumPayload')
            ->build();
        }

        return $errors;
    }

    /** The array literal handed to a JSON response, or null if this is not one. */
    private function payloadArray(Node $node): ?Array_
    {
        if ($node instanceof New_ && $node->class instanceof Node\Name) {
            $class = $node->class->toString();
            if (!str_ends_with($class, 'JsonResponse')) {
                return null;
            }
            $first = $node->getArgs()[0] ?? null;

            return $first?->value instanceof Array_ ? $first->value : null;
        }

        // $this->json([...]) — AbstractController helper
        if ($node instanceof MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->name === 'json'
        ) {
            $first = $node->getArgs()[0] ?? null;

            return $first?->value instanceof Array_ ? $first->value : null;
        }

        return null;
    }

    /**
     * @return list<array{0: string|null, 1: string}>
     */
    private function enumItems(Array_ $array, Scope $scope): array
    {
        $found = [];

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->value instanceof Array_) {
                foreach ($this->enumItems($item->value, $scope) as $nested) {
                    $found[] = $nested;
                }
                continue;
            }

            foreach ($scope->getType($item->value)->getObjectClassNames() as $className) {
                if (!$this->reflectionProvider->hasClass($className)) {
                    continue;
                }
                if (!$this->reflectionProvider->getClass($className)->isEnum()) {
                    continue;
                }
                // A JsonSerializable enum encodes fine.
                if ($this->reflectionProvider->getClass($className)->hasMethod('jsonSerialize')) {
                    continue;
                }

                $key = null;
                if ($item->key instanceof Node\Scalar\String_) {
                    $key = $item->key->value;
                }

                $found[] = [$key, $className];
            }
        }

        return $found;
    }
}
