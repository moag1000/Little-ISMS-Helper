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
    private const CONNECTION = 'Doctrine\DBAL\Connection';
    private const BULK_TRAIT = 'App\Controller\Trait\BulkActionTrait';
    private const REPOSITORY = 'Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository';
    private const LIFECYCLE = 'App\Lifecycle\LifecycleService';
    /** Calls that write when they follow a positive guard `if (valid) { … }` outside its branch (T4 C4). */
    private const WRITE_PREFIXES = ['flush', 'persist', 'remove', 'save', 'delete', 'update', 'create', 'apply',
        'approve', 'reject', 'archive', 'import', 'execute', 'transition', 'send', 'dispatch', 'record', 'store',
        'assign', 'revoke', 'restore', 'handle'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $classNode = $node->getOriginalNode();
        $file = $scope->getFile();
        if (str_starts_with($class->getName(), 'App\Form\\')) {
            return $this->formCsrfOff($classNode, $class, $file);
        }
        if (!str_starts_with($class->getName(), 'App\Controller\\') || $class->isAbstract()
            || !$classNode instanceof Node\Stmt\Class_) {
            // T4 (C6, C10): a route on an abstract parent, a trait or a class outside App\Controller is checked by
            // nobody here; Symfony still serves it. Routes live only on concrete controllers (RouteDeclarationTest).
            if (!str_starts_with($class->getName(), 'App\\') || str_starts_with($class->getName(), 'App\Tests\\')) {
                return [];
            }
            $errors = [];
            foreach ($classNode->getMethods() as $method) {
                foreach (GateAst::attributes($method, ...self::ROUTE) as $route) {
                    $errors[] = $this->error($file, $class, $method, 'route-outside-controller', sprintf(
                        'Route on %s() of %s, which is no concrete class in App\Controller: Gates 65 and 11 do not '
                        . 'check it — move the action to a concrete controller.',
                        $method->name->toString(),
                        $class->getName(),
                    ), $route->getStartLine());
                }
            }

            return $errors;
        }
        $methods = [];
        foreach ($classNode->getMethods() as $method) {
            $methods[$method->name->toLowerString()] = $method;
        }
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
            $verbs = $this->verbs($routes === [] ? $classRoutes : $routes, $scope, $classRoutes);
            $helpers = $this->helpers($method, $methods);

            if (array_intersect($verbs, self::WRITE_VERBS) === []) {
                if (!GateAst::markerOnMember($file, '@get-write-ok', $method)) {
                    foreach ([$method, ...$helpers] as $body) {
                        $write = $this->firstWrite($body, $class, $scope);
                        if ($write !== null) {
                            $errors[] = $this->error($file, $class, $method, 'get-writes', sprintf(
                                'GET action %s() writes (->%s()%s): move the write to a POST '
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

            $attributes = [...GateAst::attributes($classNode, ...self::CSRF_ATTRIBUTE), ...GateAst::attributes($method, ...self::CSRF_ATTRIBUTE)];
            $unchecked = $this->verbsTheAttributesSkip($attributes, $verbs, $scope);
            if ($attributes !== [] && $unchecked !== []) {
                // T4 C1: #[IsCsrfTokenValid(methods: ['GET'])] checks only the listed verbs; the POST goes through.
                $errors[] = $this->error($file, $class, $method, 'attribute-methods', sprintf(
                    '#[IsCsrfTokenValid] on %s() leaves %s unchecked (its `methods:` does not list them).',
                    $method->name->toString(),
                    implode('/', $unchecked),
                ), $method->name->getStartLine());
                continue;
            }
            if ($attributes !== [] || GateAst::markerOnMember($file, '@csrf-exempt', $method)) {
                continue;
            }
            $protected = false;
            $deciders = $this->decidingHelpers($helpers);
            $tail = $this->tailCalled($method);
            foreach ([$method, ...$helpers] as $body) {
                // `return $this->helper(...)`: the helper's return is the action's, so it decides like the action.
                $asAction = $body === $method || in_array($body->name->toLowerString(), $tail, true);
                if ($this->protects($body, $class, $methods, $asAction ? $deciders : [], !$asAction)) {
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
     * A route without `methods:` takes the class route's verbs (Symfony merges them, T4 C7).
     *
     * @param list<Node\Attribute> $routes
     * @param list<Node\Attribute> $classRoutes
     * @return list<string>
     */
    private function verbs(array $routes, Scope $scope, array $classRoutes = []): array
    {
        $verbs = [];
        foreach ($routes as $route) {
            $methods = GateAst::arg($route, 6, 'methods');
            if ($methods === null && $routes !== $classRoutes) {
                foreach ($classRoutes as $classRoute) {
                    $methods ??= GateAst::arg($classRoute, 6, 'methods');
                }
            }
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

    /**
     * @param array<string, ClassMethod> $methods
     * @param list<string> $deciders helpers whose result is the token check (their call counts as one)
     */
    private function protects(ClassMethod $body, ClassReflection $class, array $methods, array $deciders, bool $helper): bool
    {
        // T4 C2: only BulkActionTrait's handleBulkDelete() checks the bulk token, not a look-alike of the class.
        if (!isset($methods['handlebulkdelete']) && $class->hasTraitUse(self::BULK_TRAIT)
            && GateAst::find($body, static fn (Node $n): bool => GateAst::isMethodCall($n, 'handleBulkDelete')) !== []) {
            return true;
        }
        if ($this->tokenCheckDecides($body, $deciders, $helper)) {
            return true;
        }
        $handled = GateAst::find($body, static fn (Node $n): bool => GateAst::isMethodCall($n, 'handleRequest', 'submit'));
        $checked = GateAst::find($body, static fn (Node $n): bool => GateAst::isMethodCall($n, 'isSubmitted', 'isValid'));
        $csrfOff = GateAst::find($body, static fn (Node $n): bool => $n instanceof Node\ArrayItem
            && $n->key instanceof Node\Scalar\String_ && $n->key->value === 'csrf_protection'
            && GateAst::isFalse($n->value));

        return $handled !== [] && $checked !== [] && $csrfOff === [];
    }

    /**
     * Helpers the action returns directly (`return $this->helper(...);`).
     *
     * @return list<string>
     */
    private function tailCalled(ClassMethod $method): array
    {
        $names = [];
        foreach (GateAst::find($method, static fn (Node $n): bool => $n instanceof Node\Stmt\Return_, true) as $return) {
            \assert($return instanceof Node\Stmt\Return_);
            $expr = $return->expr;
            if ($expr instanceof Expr\MethodCall && $expr->var instanceof Expr\Variable && $expr->var->name === 'this'
                && GateAst::callName($expr) !== null) {
                $names[] = (string) GateAst::callName($expr);
            }
        }

        return $names;
    }

    /** @param list<string> $deciders */
    private function isTokenCheck(Node $node, array $deciders = []): bool
    {
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && in_array(GateAst::callName($node), self::TOKEN_CHECKS, true)) {
            return true;
        }
        if ($deciders !== [] && $node instanceof Expr\MethodCall && $node->var instanceof Expr\Variable
            && $node->var->name === 'this' && in_array(GateAst::callName($node), $deciders, true)) {
            return true;
        }

        return GateAst::isMethodCall($node, 'isTokenValid')
            && GateAst::arg($node, 0) instanceof Expr\New_;
    }

    /**
     * Helpers that hand the token check back as their result (`return $this->isCsrfTokenValid(…)`): their call
     * protects only where the action itself decides on it (T4 C3), a bare `$this->check($request);` does not.
     *
     * @param list<ClassMethod> $helpers
     * @return list<string>
     */
    private function decidingHelpers(array $helpers): array
    {
        $names = [];
        foreach ($helpers as $helper) {
            [$contains, $decides] = $this->deciders($helper, []);
            foreach (GateAst::find($helper, static fn (Node $n): bool => $n instanceof Node\Stmt\Return_, true) as $return) {
                \assert($return instanceof Node\Stmt\Return_);
                if ($decides($return->expr)) {
                    $names[] = $helper->name->toLowerString();
                    break;
                }
            }
        }

        return $names;
    }

    /**
     * @param list<string> $deciders
     * @return array{callable(?Node): bool, callable(?Node): bool}
     */
    private function deciders(ClassMethod $body, array $deciders): array
    {
        $contains = fn (?Node $n): bool => $n !== null
            && GateAst::find($n, fn (Node $x): bool => $this->isTokenCheck($x, $deciders)) !== [];
        $variables = [];
        foreach (GateAst::find($body, static fn (Node $n): bool => $n instanceof Expr\Assign) as $assign) {
            if ($assign instanceof Expr\Assign && $assign->var instanceof Expr\Variable
                && is_string($assign->var->name) && $contains($assign->expr)) {
                $variables[] = $assign->var->name;
            }
        }
        $decides = static function (?Node $n) use ($contains, $variables): bool {
            if ($n === null) {
                return false;
            }
            if ($contains($n)) {
                return true;
            }

            return GateAst::find($n, static fn (Node $x): bool => $x instanceof Expr\Variable
                && in_array($x->name, $variables, true)) !== [];
        };

        return [$contains, $decides];
    }

    /**
     * A token check decides when its result ends the action: the condition of an if/elseif/while/match whose
     * failure branch returns or throws, a return, an expression with a throw (`$ok || throw ...`), or a variable
     * assigned from it and used in one of those. A positive guard `if (valid) { work }` counts only when nothing
     * after it writes (T4 C4). In a helper a `return` ends the helper, not the action: there only a throw counts.
     *
     * @param list<string> $deciders
     */
    private function tokenCheckDecides(ClassMethod $body, array $deciders = [], bool $helper = false): bool
    {
        [, $decides] = $this->deciders($body, $deciders);
        $ends = $helper
            ? static fn (array $stmts): bool => GateAst::find($stmts, static fn (Node $n): bool => $n instanceof Expr\Throw_
                || $n instanceof Expr\Exit_ || GateAst::isMethodCall($n, 'denyAccessUnlessGranted'), true) !== []
            : static fn (array $stmts): bool => GateAst::endsAction($stmts);
        $parents = null;

        foreach (GateAst::find($body, static fn (Node $n): bool => true, true) as $n) {
            if (($n instanceof Node\Stmt\If_ || $n instanceof Node\Stmt\ElseIf_ || $n instanceof Node\Stmt\While_)
                && $decides($n->cond)) {
                if ($this->negated($n->cond)) {
                    // T4 C5: only the failure branch counts, not an `else { return }` after it.
                    if ($ends($n->stmts)) {
                        return true;
                    }
                    continue;
                }
                if ($n->stmts !== [] && !$helper) {
                    $parents ??= GateAst::parents($body);
                    if (!$this->writesAfter($n, $parents, $body)) {
                        return true;
                    }
                }
            }
            if (!$helper && $n instanceof Node\Stmt\Return_ && $decides($n->expr)) {
                return true;
            }
            if ($n instanceof Node\Stmt\Expression && !$n->expr instanceof Expr\Assign && $decides($n->expr)
                && GateAst::find($n, static fn (Node $x): bool => $x instanceof Expr\Throw_) !== []) {
                return true;
            }
            if (!$helper && $n instanceof Expr\Match_) {
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

    /**
     * A statement after $node (in its block and in every enclosing block up to the method) calls a write-like
     * method: then the positive guard does not hold the work.
     *
     * @param \SplObjectStorage<Node, Node> $parents
     */
    private function writesAfter(Node $node, \SplObjectStorage $parents, ClassMethod $method): bool
    {
        $current = $node;
        while ($current !== $method && isset($parents[$current])) {
            $parent = $parents[$current];
            $after = [];
            foreach ($parent->getSubNodeNames() as $name) {
                $list = $parent->{$name};
                if (!is_array($list)) {
                    continue;
                }
                $index = array_search($current, $list, true);
                if ($index !== false) {
                    $after = array_slice($list, (int) $index + 1);
                }
            }
            $writes = GateAst::find($after, static function (Node $n): bool {
                $name = GateAst::callName($n);
                if ($name === null) {
                    return false;
                }
                foreach (self::WRITE_PREFIXES as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        return true;
                    }
                }

                return false;
            }, true);
            if ($writes !== []) {
                return true;
            }
            $current = $parent;
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

    /**
     * T4 C1: the verbs of the action a `#[IsCsrfTokenValid(methods: [...])]` leaves out (empty: all checked).
     *
     * @param list<Node\Attribute> $attributes
     * @param list<string> $verbs
     * @return list<string>
     */
    private function verbsTheAttributesSkip(array $attributes, array $verbs, Scope $scope): array
    {
        $checked = [];
        foreach ($attributes as $attribute) {
            $methods = GateAst::arg($attribute, 2, 'methods');
            if ($methods === null) {
                return [];
            }
            foreach (GateAst::strings($methods, $scope) as $verb) {
                $checked[] = strtoupper(trim($verb));
            }
        }

        return array_values(array_diff(array_intersect($verbs, self::WRITE_VERBS), $checked));
    }

    /**
     * T4 C8: a FormType that switches CSRF off for every form of its kind (`'csrf_protection' => false` in its
     * defaults) leaves each controller's `handleRequest()` unprotected.
     *
     * @return list<IdentifierRuleError>
     */
    private function formCsrfOff(Node\Stmt\ClassLike $classNode, ClassReflection $class, string $file): array
    {
        $errors = [];
        foreach ($classNode->getMethods() as $method) {
            if (GateAst::markerOnMember($file, '@csrf-exempt', $method)) {
                continue;
            }
            foreach (GateAst::find($method, static fn (Node $n): bool => ($n instanceof Node\ArrayItem
                && $n->key instanceof Node\Scalar\String_ && $n->key->value === 'csrf_protection'
                && !GateAst::isTrue($n->value))
                || (GateAst::isMethodCall($n, 'setDefault') && GateAst::arg($n, 0) instanceof Node\Scalar\String_
                    && GateAst::arg($n, 0)->value === 'csrf_protection' && !GateAst::isTrue(GateAst::arg($n, 1)))) as $off) {
                $errors[] = $this->error($file, $class, $method, 'form-csrf-off', 'FormType switches CSRF protection off '
                    . 'for every form of its kind: drop the option or mark the method `// @csrf-exempt: <reason>`.', $off->getStartLine());
            }
        }

        return $errors;
    }

    /**
     * First write of the method: flush()/persist()/remove() on a Doctrine object manager (also through a local
     * alias), and since T4 (C9) executeStatement() on a DBAL connection, a DQL UPDATE/DELETE, a repository
     * save()/remove() with flush, a lifecycle transition().
     */
    private function firstWrite(ClassMethod $method, ClassReflection $class, Scope $scope): ?Node
    {
        foreach (GateAst::find($method, static fn (Node $n): bool => $n instanceof Expr\MethodCall
            || $n instanceof Expr\NullsafeMethodCall) as $call) {
            \assert($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall);
            $receiver = $call->var;
            $name = GateAst::callName($call);
            if (in_array($name, ['flush', 'persist', 'remove'], true)
                && (GateAst::isMethodCall($receiver, 'getManager', 'getEntityManager', 'getManagerForClass')
                    || GateAst::isA($receiver, $class, $method, $scope, self::OBJECT_MANAGER))) {
                return $call;
            }
            if (in_array($name, ['executestatement', 'executeupdate'], true)
                && GateAst::isA($receiver, $class, $method, $scope, self::CONNECTION)) {
                return $call;
            }
            if ($name === 'createquery') {
                $dql = GateAst::arg($call, 0);
                $text = $dql === null ? null : GateAst::template($dql, $scope);
                if ($text !== null && preg_match('/^\s*(UPDATE|DELETE)\b/i', $text) === 1) {
                    return $call;
                }
            }
            if (in_array($name, ['save', 'remove'], true) && GateAst::isTrue(GateAst::arg($call, 1, 'flush'))
                && GateAst::isA($receiver, $class, $method, $scope, self::REPOSITORY)) {
                return $call;
            }
            if ($name === 'transition' && GateAst::isA($receiver, $class, $method, $scope, self::LIFECYCLE)) {
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
