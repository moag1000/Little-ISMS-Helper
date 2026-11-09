<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 65 (T3: AST rule, replaces check_csrf_state_changing.py): a controller action that answers POST, PUT,
 * PATCH or DELETE checks a CSRF token, and an action that answers GET does not write.
 *
 * Protected is an action with
 *   - `#[IsCsrfTokenValid]` on the action or the class (the attribute, not the word in a comment),
 *   - a token check (`isCsrfTokenValid()`, `isBulkCsrfTokenValid()`, `isTokenValid(new CsrfToken())`) whose result
 *     ends the action: in an `if` that returns or throws, in a `return`, beside a `throw`, or through a variable
 *     used that way — a bare call or a check whose failure only logs protects nothing,
 *   - `handleBulkDelete()` (BulkActionTrait checks the shared bulk token),
 *   - a Symfony form that is handled and checked (`handleRequest()`/`submit()` and `isSubmitted()`/`isValid()`),
 *     unless the method switches the form's CSRF off (`'csrf_protection' => false`),
 * in its body or in a private helper of the class it calls. A genuinely non-state-changing action carries
 * `// @csrf-exempt: <reason>` in its lead. Route verbs are evaluated, so `Request::METHOD_POST` counts.
 *
 * A GET action (only safe verbs, or no `methods:`) must not call `flush()`/`persist()`/`remove()` on a Doctrine
 * object manager (property, parameter, `->getManager()`), itself or in a helper; a deliberate write carries
 * `// @get-write-ok: <reason>`. A static token id with a route placeholder (`'delete_{id}'`) is never interpolated.
 *
 * @implements Rule<InClassNode>
 */
final class CsrfStateChangingActionRule implements Rule
{
    private const ROUTE = ['Symfony\Component\Routing\Attribute\Route', 'Symfony\Component\Routing\Annotation\Route'];
    private const CSRF_ATTRIBUTE = ['Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid'];
    private const WRITE_VERBS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const TOKEN_CHECKS = ['iscsrftokenvalid', 'isbulkcsrftokenvalid'];
    private const OBJECT_MANAGER = 'Doctrine\Persistence\ObjectManager';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        if (!str_starts_with($class->getName(), 'App\Controller\\') || $class->isAbstract()) {
            return [];
        }
        $classNode = $node->getOriginalNode();
        $file = $scope->getFile();
        $methods = [];
        foreach ($classNode->getMethods() as $method) {
            $methods[$method->name->toLowerString()] = $method;
        }
        $classProtected = GateAst::attributes($classNode, ...self::CSRF_ATTRIBUTE) !== [];
        $classRoutes = GateAst::attributes($classNode, ...self::ROUTE);

        $errors = [];
        foreach ([$classNode, ...$classNode->getMethods()] as $member) {
            foreach (GateAst::attributes($member, ...self::CSRF_ATTRIBUTE) as $attr) {
                foreach (GateAst::strings(GateAst::arg($attr, 0, 'id'), $scope) as $id) {
                    if (preg_match('/\{\w+\}/', $id) === 1) {
                        $errors[] = $this->error($file, $class, $member, 'literal-id', sprintf(
                            'CSRF token id "%s" carries a route placeholder that is never interpolated — use '
                            . 'new Expression(\'"prefix_" ~ args["entity"].getId()\').',
                            $id,
                        ), $attr->getStartLine());
                    }
                }
            }
        }

        foreach ($classNode->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic() || $method->stmts === null) {
                continue;
            }
            $routes = GateAst::attributes($method, ...self::ROUTE);
            if ($routes === [] && !($method->name->toLowerString() === '__invoke' && $classRoutes !== [])) {
                continue;
            }
            $verbs = $this->verbs($routes === [] ? $classRoutes : $routes, $scope);
            $helpers = $this->helpers($method, $methods);

            if (array_intersect($verbs, self::WRITE_VERBS) === []) {
                if (!GateAst::markerOnMember($file, '@get-write-ok', $method)) {
                    foreach ([$method, ...$helpers] as $body) {
                        $write = $this->firstWrite($body, $class, $scope);
                        if ($write !== null) {
                            $errors[] = $this->error($file, $class, $method, 'get-writes', sprintf(
                                'GET action %s() writes (->%s() on the object manager%s): move the write to a POST '
                                . 'route with #[IsCsrfTokenValid] or mark it `// @get-write-ok: <reason>`.',
                                $method->name->toString(),
                                GateAst::callName($write),
                                $body === $method ? '' : ' in ' . $body->name->toString() . '()',
                            ), $write->getStartLine());
                            break;
                        }
                    }
                }
                continue;
            }

            if ($classProtected || GateAst::attributes($method, ...self::CSRF_ATTRIBUTE) !== []
                || GateAst::markerOnMember($file, '@csrf-exempt', $method)) {
                continue;
            }
            $protected = false;
            foreach ([$method, ...$helpers] as $body) {
                if ($this->protects($body)) {
                    $protected = true;
                    break;
                }
            }
            if (!$protected) {
                $errors[] = $this->error($file, $class, $method, 'csrf', sprintf(
                    '%s action %s() has no CSRF check: add #[IsCsrfTokenValid(\'<id>\')] (tokenSource: '
                    . 'IsCsrfTokenValid::SOURCE_HEADER for fetch()), a token check that ends the action, a handled '
                    . 'Symfony form — or `// @csrf-exempt: <reason>` for an action that changes nothing.',
                    implode('/', array_values(array_intersect($verbs, self::WRITE_VERBS))),
                    $method->name->toString(),
                ), $method->name->getStartLine());
            }
        }

        return $errors;
    }

    /**
     * Verbs the routes accept; a route without `methods:` counts as GET (the old gate's reading — Symfony would
     * accept every verb, but such routes are links).
     *
     * @param list<Node\Attribute> $routes
     * @return list<string>
     */
    private function verbs(array $routes, Scope $scope): array
    {
        $verbs = [];
        foreach ($routes as $route) {
            $methods = GateAst::arg($route, 6, 'methods');
            if ($methods === null) {
                $verbs[] = 'GET';
                continue;
            }
            foreach (GateAst::strings($methods, $scope) as $value) {
                foreach (explode('|', strtoupper($value)) as $verb) {
                    $verbs[] = trim($verb);
                }
            }
        }

        return array_values(array_unique($verbs));
    }

    /**
     * Private/protected methods of the class the action calls through `$this->x()` (one level, like the old gate).
     *
     * @param array<string, ClassMethod> $methods
     * @return list<ClassMethod>
     */
    private function helpers(ClassMethod $method, array $methods): array
    {
        $helpers = [];
        foreach (GateAst::find($method, static fn (Node $n): bool => $n instanceof Expr\MethodCall
            && $n->var instanceof Expr\Variable && $n->var->name === 'this') as $call) {
            $name = GateAst::callName($call);
            if ($name !== null && isset($methods[$name]) && $methods[$name] !== $method
                && $methods[$name]->stmts !== null) {
                $helpers[$name] = $methods[$name];
            }
        }

        return array_values($helpers);
    }

    private function protects(ClassMethod $body): bool
    {
        if (GateAst::find($body, static fn (Node $n): bool => GateAst::isMethodCall($n, 'handleBulkDelete')) !== []) {
            return true;
        }
        if ($this->tokenCheckDecides($body)) {
            return true;
        }
        $handled = GateAst::find($body, static fn (Node $n): bool => GateAst::isMethodCall($n, 'handleRequest', 'submit'));
        $checked = GateAst::find($body, static fn (Node $n): bool => GateAst::isMethodCall($n, 'isSubmitted', 'isValid'));
        $csrfOff = GateAst::find($body, static fn (Node $n): bool => $n instanceof Node\ArrayItem
            && $n->key instanceof Node\Scalar\String_ && $n->key->value === 'csrf_protection'
            && GateAst::isFalse($n->value));

        return $handled !== [] && $checked !== [] && $csrfOff === [];
    }

    private function isTokenCheck(Node $node): bool
    {
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && in_array(GateAst::callName($node), self::TOKEN_CHECKS, true)) {
            return true;
        }

        return GateAst::isMethodCall($node, 'isTokenValid')
            && GateAst::arg($node, 0) instanceof Expr\New_;
    }

    /**
     * A token check decides when its result ends the action: the condition of an if/elseif/while/ternary/match
     * that returns or throws, a return, an expression with a throw (`$ok || throw ...`), or a variable assigned
     * from it and used in one of those.
     */
    private function tokenCheckDecides(ClassMethod $body): bool
    {
        $contains = fn (?Node $n): bool => $n !== null
            && GateAst::find($n, fn (Node $x): bool => $this->isTokenCheck($x)) !== [];
        $variables = [];
        foreach (GateAst::find($body, static fn (Node $n): bool => $n instanceof Expr\Assign) as $assign) {
            if ($assign instanceof Expr\Assign && $assign->var instanceof Expr\Variable
                && is_string($assign->var->name) && $contains($assign->expr)) {
                $variables[] = $assign->var->name;
            }
        }
        $decides = function (?Node $n) use ($contains, $variables): bool {
            if ($n === null) {
                return false;
            }
            if ($contains($n)) {
                return true;
            }

            return GateAst::find($n, static fn (Node $x): bool => $x instanceof Expr\Variable
                && in_array($x->name, $variables, true)) !== [];
        };

        foreach (GateAst::find($body, static fn (Node $n): bool => true, true) as $n) {
            if (($n instanceof Node\Stmt\If_ || $n instanceof Node\Stmt\ElseIf_ || $n instanceof Node\Stmt\While_)
                && $decides($n->cond)) {
                // `if (!valid) { ... }` must end the action; `if (valid) { work }` guards the work it holds.
                if (GateAst::endsAction($n) || (!$this->negated($n->cond) && $n->stmts !== [])) {
                    return true;
                }
            }
            if ($n instanceof Node\Stmt\Return_ && $decides($n->expr)) {
                return true;
            }
            if ($n instanceof Node\Stmt\Expression && !$n->expr instanceof Expr\Assign && $decides($n->expr)
                && GateAst::find($n, static fn (Node $x): bool => $x instanceof Expr\Throw_) !== []) {
                return true;
            }
            if ($n instanceof Expr\Match_) {
                // `match (true) { !valid => refuse, default => work }`: the arm decides.
                foreach ($n->arms as $arm) {
                    foreach ($arm->conds ?? [] as $armCond) {
                        if ($decides($armCond)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /** The condition asks for an invalid token: `!check`, `check === false`, `false === $ok`, `!$ok`. */
    private function negated(Expr $cond): bool
    {
        return GateAst::find($cond, static fn (Node $n): bool => $n instanceof Expr\BooleanNot
            || (($n instanceof Expr\BinaryOp\Identical || $n instanceof Expr\BinaryOp\Equal)
                && (GateAst::isFalse($n->left) || GateAst::isFalse($n->right)))
            || (($n instanceof Expr\BinaryOp\NotIdentical || $n instanceof Expr\BinaryOp\NotEqual)
                && (GateAst::isTrue($n->left) || GateAst::isTrue($n->right)))) !== [];
    }

    /** First flush()/persist()/remove() on a Doctrine object manager in the method. */
    private function firstWrite(ClassMethod $method, ClassReflection $class, Scope $scope): ?Node
    {
        foreach (GateAst::find($method, static fn (Node $n): bool => GateAst::isMethodCall($n, 'flush', 'persist', 'remove')) as $call) {
            \assert($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall);
            $receiver = $call->var;
            if (GateAst::isMethodCall($receiver, 'getManager', 'getEntityManager', 'getManagerForClass')
                || GateAst::isA($receiver, $class, $method, $scope, self::OBJECT_MANAGER)) {
                return $call;
            }
        }

        return null;
    }

    private function error(string $file, ClassReflection $class, Node $member, string $kind, string $message, int $line): IdentifierRuleError
    {
        $name = $member instanceof ClassMethod ? $member->name->toString() : '(class)';

        return RuleErrorBuilder::message(sprintf('Gate 65 [%s::%s#%s]: %s', GateAst::relative($file), $name, $kind, $message))
            ->identifier('gate65.' . str_replace('-', '', lcfirst(ucwords($kind, '-'))))
            ->line($line)
            ->build();
    }
}
