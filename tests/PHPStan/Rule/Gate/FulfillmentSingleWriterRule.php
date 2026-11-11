<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Gate 85, type part (T4, 2026-11): the percentage and status of a requirement fulfilment have one writer,
 * `FulfillmentDerivationService` (src/Service/Compliance/Fulfillment/). check_fulfillment_single_writer.py finds a
 * second writer by the receiver's NAME (`$fulfillment->…`), so `$row->setStatus('compliant')` and a setter called
 * by a constant string stayed green. This rule reads the receiver's TYPE: a call of setFulfillmentPercentage(),
 * setStatus(), setDerivation(), markOverride() or clearOverride() on a ComplianceRequirementFulfillment — also as
 * `$x->{'setStatus'}()`, `[$x, 'setStatus']` or a first-class callable — outside App\Service\Compliance\Fulfillment
 * and the entity itself fails, unless the line carries `// @fulfillment-writer-ok: <reason>`.
 *
 * @implements Rule<Expr>
 */
final class FulfillmentSingleWriterRule implements Rule
{
    private const ENTITY = 'App\Entity\ComplianceRequirementFulfillment';
    private const WRITERS = ['setfulfillmentpercentage', 'setstatus', 'setderivation', 'markoverride', 'clearoverride'];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection()?->getName() ?? '';
        if (!str_starts_with($class, 'App\\') || str_starts_with($class, 'App\Service\Compliance\Fulfillment\\')
            || $class === self::ENTITY) {
            return [];
        }
        [$receiver, $method] = match (true) {
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall => [$node->var, $node->name instanceof Node\Identifier
                ? $node->name->toLowerString() : strtolower(implode('', GateAst::strings($node->name, $scope)))],
            $node instanceof Expr\Array_ && count($node->items) === 2 && $node->items[0] instanceof Node\ArrayItem
                && $node->items[1] instanceof Node\ArrayItem => [$node->items[0]->value, strtolower(implode('', GateAst::strings($node->items[1]->value, $scope)))],
            default => [null, ''],
        };
        if ($receiver === null || !in_array($method, self::WRITERS, true)
            || !(new ObjectType(self::ENTITY))->isSuperTypeOf($scope->getType($receiver))->yes()
            || GateAst::markerAbove($scope->getFile(), '@fulfillment-writer-ok', $node->getStartLine())) {
            return [];
        }

        return [RuleErrorBuilder::message(sprintf(
            'Gate 85 [%s]: %s() on a requirement fulfilment outside FulfillmentDerivationService — derive it '
            . '(refresh/override/setManual) or mark the line `// @fulfillment-writer-ok: <reason>`.',
            GateAst::relative($scope->getFile()),
            $method,
        ))->identifier('gate85.secondWriter')->line($node->getStartLine())->build()];
    }
}
