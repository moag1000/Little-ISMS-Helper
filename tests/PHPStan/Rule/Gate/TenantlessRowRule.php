<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 72 (T3: AST rule, replaces check_tenant_null_rows.py): no rows without a tenant that the TenantFilter hides.
 *
 * TenantFilter adds `tenant_id = <current>` to every query on a tenant-owned entity, so a row whose tenant is NULL
 * is invisible to every organisation — unless the entity implements SharedTenantRowsInterface.
 *
 *   new-no-tenant  Code outside entities, fixtures, restore and migrations creates a tenant-owned entity and gives
 *                  it no tenant: the object (variable, property, or the `new` itself in a chain) gets no
 *                  `setTenant(<not null>)` in the method and no Tenant through its constructor. Class names are
 *                  resolved, so `use App\Entity\Asset as A; new A()` counts; `new $class()` and `clone` are left
 *                  to the runtime guard (TenantlessInsertGuard).
 *   shared-query   A query asks for "own OR global" rows on an entity without the interface — any spelling the
 *                  parser can join: `x.tenant = :t OR x.tenant IS NULL` (also `?1`, `IN (...)`, IDENTITY(),
 *                  `%s` aliases through sprintf/concatenation), `->orWhere('x.tenant IS NULL')`,
 *                  `->orWhere($qb->expr()->isNull('x.tenant'))`, `orX(..., isNull('x.tenant'))` and
 *                  `COALESCE(IDENTITY(x.tenant), :t) = :t`. The entity is the repository's (constructor
 *                  `parent::__construct($registry, X::class)` or the class name).
 *
 * @implements Rule<InClassNode>
 */
final class TenantlessRowRule implements Rule
{
    private const SKIP_NEW = ['src/Entity/', 'src/DataFixtures/', 'src/Service/Restore/', 'src/Migrations/'];
    private const SHARED = 'App\Doctrine\SharedTenantRowsInterface';
    private const TENANT = 'App\Entity\Tenant';

    private const TENANT_REF = '(?:IDENTITY\(\s*)?(?:\w+|%s)\.tenant(?:_id|Id)?\s*\)?';
    private const PARAM = '(?::\w+|\?\d*|%s)';

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $classNode = $node->getOriginalNode();
        $file = $scope->getFile();
        $rel = GateAst::relative($file);
        if (!str_starts_with($rel, 'src/')) {
            return [];
        }
        $errors = [];

        $shared = $this->sharedQuery($classNode, $scope);
        if ($shared !== null) {
            $entity = $this->repositoryEntity($class, $classNode);
            if ($entity === null || !$this->implementsShared($entity)) {
                $short = $entity === null ? 'unknown-entity' : substr($entity, (int) strrpos($entity, '\\') + 1);
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Gate 72 [%s::shared-query::%s]: "own OR global" query on an entity without '
                    . 'SharedTenantRowsInterface — the TenantFilter hides the global rows; implement the interface '
                    . '(and check the voter) or drop the dead branch.',
                    $rel,
                    $short,
                ))->identifier('gate72.sharedQuery')->line($shared->getStartLine())->build();
            }
        }

        foreach (self::SKIP_NEW as $skip) {
            if (str_starts_with($rel, $skip)) {
                return $errors;
            }
        }
        foreach ($classNode->getMethods() as $method) {
            if ($method->stmts === null) {
                continue;
            }
            $parents = GateAst::parents($method);
            foreach (GateAst::find($method, static fn (Node $n): bool => $n instanceof Expr\New_
                && $n->class instanceof Node\Name) as $new) {
                \assert($new instanceof Expr\New_ && $new->class instanceof Node\Name);
                $entity = $new->class->toString();
                if (!$this->isTenantOwned($entity) || $this->constructorTakesTenant($entity, $new)) {
                    continue;
                }
                if ($this->getsTenant($new, $method, $parents)) {
                    continue;
                }
                $short = substr($entity, (int) strrpos($entity, '\\') + 1);
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Gate 72 [%s::new-no-tenant::%s::%s]: new %s() gets no setTenant(<organisation>) in %s() — the row '
                    . 'is saved without a tenant and disappears for its own organisation.',
                    $rel,
                    $method->name->toString(),
                    $short,
                    $short,
                    $method->name->toString(),
                ))->identifier('gate72.newNoTenant')->line($new->getStartLine())->build();
            }
        }

        return $errors;
    }

    private function isTenantOwned(string $entity): bool
    {
        if (!str_starts_with($entity, 'App\Entity\\') || $entity === 'App\Entity\User'
            || !$this->reflectionProvider->hasClass($entity)) {
            return false;
        }
        $reflection = $this->reflectionProvider->getClass($entity);
        if (!$reflection->hasNativeProperty('tenant') || $reflection->implementsInterface(self::SHARED)) {
            return false;
        }

        return in_array(self::TENANT, $reflection->getNativeProperty('tenant')->getNativeType()->getReferencedClasses(), true);
    }

    private function implementsShared(string $entity): bool
    {
        return $this->reflectionProvider->hasClass($entity)
            && $this->reflectionProvider->getClass($entity)->implementsInterface(self::SHARED);
    }

    /** `new X($tenant)` where the constructor takes the Tenant (not null). */
    private function constructorTakesTenant(string $entity, Expr\New_ $new): bool
    {
        $reflection = $this->reflectionProvider->getClass($entity);
        if (!$reflection->hasConstructor()) {
            return false;
        }
        $params = $reflection->getConstructor()->getOnlyVariant()->getParameters();
        foreach ($params as $i => $param) {
            if (!in_array(self::TENANT, $param->getType()->getReferencedClasses(), true)) {
                continue;
            }
            $arg = GateAst::arg($new, $i, $param->getName());

            return $arg !== null && !GateAst::isNull($arg);
        }

        return false;
    }

    /**
     * The new object receives a tenant: a `->setTenant(<not null>)` on it in the chain, or on the variable or
     * property it is assigned to anywhere in the method.
     *
     * @param \SplObjectStorage<Node, Node> $parents
     */
    private function getsTenant(Expr\New_ $new, Node $method, \SplObjectStorage $parents): bool
    {
        $node = $new;
        while ($parents->contains($node)) {
            $parent = $parents[$node];
            if (($parent instanceof Expr\MethodCall || $parent instanceof Expr\NullsafeMethodCall) && $parent->var === $node) {
                if ($this->setsTenant($parent)) {
                    return true;
                }
                $node = $parent;
                continue;
            }
            if ($parent instanceof Expr\Assign && $parent->expr === $node) {
                $target = $parent->var;
                $key = $this->key($target);
                if ($key === null) {
                    return false;
                }
                foreach (GateAst::find($method, fn (Node $n): bool => GateAst::isMethodCall($n, 'setTenant')) as $call) {
                    \assert($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall);
                    // `$list[] = new X()`: the element is not addressable, any setTenant() of the method counts.
                    $same = str_ends_with($key, '[]') || $this->key($this->chainRoot($call->var)) === $key;
                    if ($same && $this->setsTenant($call)) {
                        return true;
                    }
                }

                return false;
            }
            if ($parent instanceof Expr\Cast || ($parent instanceof Node\Arg) || $parent instanceof Node\Stmt\Expression) {
                return false;
            }
            $node = $parent;
        }

        return false;
    }

    private function setsTenant(Node $call): bool
    {
        if (!GateAst::isMethodCall($call, 'setTenant')) {
            return false;
        }
        $arg = GateAst::arg($call, 0);

        return $arg !== null && !GateAst::isNull($arg);
    }

    /** `$a` of `$a->setName()->setTenant()`: the object a fluent chain is called on. */
    private function chainRoot(Expr $expr): Expr
    {
        while (($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall)
            && !($expr->var instanceof Expr\Variable && $expr->var->name === 'this')) {
            $expr = $expr->var;
        }

        return $expr;
    }

    /** `$a` / `$this->a` / `$x->a` as a comparable key. */
    private function key(Node $expr): ?string
    {
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return '$' . $expr->name;
        }
        if ($expr instanceof Expr\PropertyFetch && $expr->name instanceof Node\Identifier) {
            $base = $this->key($expr->var);

            return $base === null ? null : $base . '->' . $expr->name->toString();
        }
        if ($expr instanceof Expr\ArrayDimFetch) {
            return $this->key($expr->var) === null ? null : $this->key($expr->var) . '[]';
        }

        return null;
    }

    /** First node of the class that asks for "own OR global" rows. */
    private function sharedQuery(Node\Stmt\ClassLike $classNode, Scope $scope): ?Node
    {
        $t = self::TENANT_REF;
        $p = self::PARAM;
        $ownOrNull = '/' . $t . '\s*(?:=\s*' . $p . '|IN\s*\()[^;]*?\bOR\b[\s(]*' . $t . '\s+IS\s+NULL'
            . '|' . $t . '\s+IS\s+NULL[\s)]*\bOR\b[\s(]*' . $t . '\s*(?:=\s*' . $p . '|IN\s*\()'
            . '|COALESCE\(\s*' . $t . '\s*,\s*' . $p . '\s*\)\s*=\s*' . $p . '/i';
        $isNullOnly = '/^\s*\(?\s*' . $t . '\s+IS\s+NULL/i';

        foreach (GateAst::find($classNode, static fn (Node $n): bool => $n instanceof Expr) as $expr) {
            \assert($expr instanceof Expr);
            if ($expr instanceof Node\Scalar\String_ || $expr instanceof Expr\BinaryOp\Concat
                || $expr instanceof Node\Scalar\InterpolatedString
                || ($expr instanceof Expr\FuncCall && GateAst::callName($expr) === 'sprintf')) {
                $text = GateAst::template($expr, $scope);
                if ($text !== null && preg_match($ownOrNull, $text) === 1) {
                    return $expr;
                }
            }
            if (GateAst::isMethodCall($expr, 'orWhere')) {
                $arg = GateAst::arg($expr, 0);
                if ($arg !== null && ($this->isNullTenant($arg, $scope)
                    || (($text = GateAst::template($arg, $scope)) !== null && preg_match($isNullOnly, $text) === 1))) {
                    return $expr;
                }
            }
            if (GateAst::isMethodCall($expr, 'orX')) {
                foreach (GateAst::find($expr, static fn (Node $n): bool => $n instanceof Expr) as $inner) {
                    \assert($inner instanceof Expr);
                    if ($this->isNullTenant($inner, $scope)
                        || (($inner instanceof Node\Scalar\String_ || $inner instanceof Expr\BinaryOp\Concat)
                            && ($text = GateAst::template($inner, $scope)) !== null
                            && preg_match($isNullOnly, $text) === 1)) {
                        return $expr;
                    }
                }
            }
        }

        return null;
    }

    /** `$qb->expr()->isNull('x.tenant')` (also with a built alias). */
    private function isNullTenant(Expr $expr, Scope $scope): bool
    {
        if (!GateAst::isMethodCall($expr, 'isNull')) {
            return false;
        }
        $arg = GateAst::arg($expr, 0);
        $text = $arg === null ? null : GateAst::template($arg, $scope);

        return $text !== null && preg_match('/^(?:\w+|%s)\.tenant(?:_id|Id)?$/', $text) === 1;
    }

    private function repositoryEntity(ClassReflection $class, Node\Stmt\ClassLike $classNode): ?string
    {
        foreach (GateAst::find($classNode, static fn (Node $n): bool => $n instanceof Expr\StaticCall
            && $n->class instanceof Node\Name && $n->class->toLowerString() === 'parent'
            && GateAst::callName($n) === '__construct') as $call) {
            \assert($call instanceof Expr\StaticCall);
            foreach ($call->getArgs() as $arg) {
                if ($arg->value instanceof Expr\ClassConstFetch && $arg->value->class instanceof Node\Name
                    && $arg->value->name instanceof Node\Identifier && $arg->value->name->toLowerString() === 'class') {
                    return $arg->value->class->toString();
                }
            }
        }
        if (preg_match('/^App\\\\Repository\\\\(?:\w+\\\\)*(\w+)Repository$/', $class->getName(), $m) === 1
            && $this->reflectionProvider->hasClass('App\Entity\\' . $m[1])) {
            return 'App\Entity\\' . $m[1];
        }

        return null;
    }
}
