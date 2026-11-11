<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 97 (T4: AST rule, replaces check_tenant_pin_restore.py): an organisation pinned in TenantContext is put
 * back. The worker runs scheduled commands and jobs in one container (S23); a pin left behind scopes the next
 * command to one organisation.
 *
 * A pin is a call of setCurrentTenant(), setCurrentTenantById() or setNoOrganisation() — also with a line break
 * after `->`, by a name built from strings (`->{'setCurrent' . 'Tenant'}()`, `$m = 'setNoOrganisation'`,
 * `call_user_func([$tc, 'setCurrentTenant'])`, `[$tc, '…']()`): any string equal to one of the three names counts.
 * It is fine when it sits in the `try` of a try/finally in the same function (a closure or arrow function is its
 * own function: its body runs elsewhere, maybe after the finally) whose `finally` really restores — a call of
 * reset()/restore*()/runAs()/setCurrentTenant*()/setNoOrganisation() on a receiver named like the tenant context,
 * or of a `$restore…()` callable; a finally that only reads the context restores nothing — or is itself in such a
 * finally, or carries `// @tenant-pin-ok: <reason>` on its line or in the comment block above. TenantContext
 * itself (runAs, the reset) is the mechanism and exempt.
 *
 * @implements Rule<InClassNode>
 */
final class TenantPinRestoreRule implements Rule
{
    private const PINS = ['setcurrenttenant', 'setcurrenttenantbyid', 'setnoorganisation'];
    private const RESTORES = ['reset', 'restore', 'restorestate', 'runas', 'runwithoutorganisation',
        'setcurrenttenant', 'setcurrenttenantbyid', 'setnoorganisation'];
    private const EXEMPT = ['App\Service\TenantContext'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection()->getName();
        if (!str_starts_with($class, 'App\\') || str_starts_with($class, 'App\Tests\\') || in_array($class, self::EXEMPT, true)) {
            return [];
        }
        $classNode = $node->getOriginalNode();
        $file = $scope->getFile();
        $parents = GateAst::parents($classNode);
        $errors = [];
        foreach (GateAst::find($classNode, fn (Node $n): bool => $this->isPin($n, $scope)) as $pin) {
            if ($this->restored($pin, $parents) || GateAst::markerAbove($file, '@tenant-pin-ok', $pin->getStartLine())) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Gate 97 [%s]: organisation pinned in TenantContext without putting it back — use '
                . 'TenantContext::runAs()/runWithoutOrganisation(), a try/finally that restores, or '
                . '`// @tenant-pin-ok: <reason>`.',
                GateAst::relative($file),
            ))->identifier('gate97.pinNotRestored')->line($pin->getStartLine())->build();
        }

        return $errors;
    }

    private function isPin(Node $n, Scope $scope): bool
    {
        if ($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall) {
            if ($n->name instanceof Node\Identifier) {
                return in_array($n->name->toLowerString(), self::PINS, true);
            }

            // `->{'setCurrent' . 'Tenant'}()`; a variable name is caught where its string is written.
            return in_array(strtolower((string) GateAst::template($n->name, $scope)), self::PINS, true);
        }

        return $n instanceof Node\Scalar\String_ && in_array(strtolower($n->value), self::PINS, true);
    }

    /** @param \SplObjectStorage<Node, Node> $parents */
    private function restored(Node $pin, \SplObjectStorage $parents): bool
    {
        $child = $pin;
        while (isset($parents[$child])) {
            $parent = $parents[$child];
            if ($parent instanceof Expr\Closure || $parent instanceof Expr\ArrowFunction
                || $parent instanceof Node\Stmt\ClassMethod || $parent instanceof Node\Stmt\Function_) {
                return false;
            }
            if ($parent instanceof Node\Stmt\Finally_) {
                return true; // the restore itself
            }
            if ($parent instanceof Node\Stmt\TryCatch && in_array($child, $parent->stmts, true)
                && $parent->finally !== null && $this->restores($parent->finally)) {
                return true;
            }
            $child = $parent;
        }

        return false;
    }

    private function restores(Node\Stmt\Finally_ $finally): bool
    {
        return GateAst::find($finally->stmts, static function (Node $n): bool {
            if ($n instanceof Expr\FuncCall && $n->name instanceof Expr\Variable && is_string($n->name->name)) {
                return str_starts_with(strtolower($n->name->name), 'restore');
            }
            if (($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall)
                && in_array(GateAst::callName($n), self::RESTORES, true)) {
                $receiver = $n->var;
                $name = match (true) {
                    $receiver instanceof Expr\PropertyFetch && $receiver->name instanceof Node\Identifier => $receiver->name->toString(),
                    $receiver instanceof Expr\Variable && is_string($receiver->name) => $receiver->name,
                    default => '',
                };

                return stripos($name, 'tenant') !== false;
            }

            return false;
        }, true) !== [];
    }
}
