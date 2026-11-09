<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 91 (T3: AST rule, replaces check_mfa_login_state.py): the second factor has one state and no side door.
 *
 * S8 (2026-11): remember-me, a second login and the password re-auth each skipped the TOTP code because every
 * path kept its own session flags. `App\Security\Mfa\MfaSessionState` holds the state of a login.
 *
 *   1. A `_security.mfa_*` session key outside MfaSessionState is a second writer or reader of the state.
 *   2. A hand-made login needs `MfaSessionState::begin()` in the same method: putting a token into the token
 *      storage (`->setToken(<not null>)`, `?->setToken()`, `setToken(...)`, `call_user_func([$ts, 'setToken'])`),
 *      naming a firewall's session key (any string that starts with `_security_`, also as a class constant, built
 *      with sprintf/concatenation, or inside `->replace([...])`) or touching `$_SESSION`. `begin()` counts only on
 *      an MfaSessionState (property, parameter or local of that type), not any `->begin()`. A reviewed exception
 *      carries `// @mfa-ok: <reason>` in the lead of that method.
 *   3. `'/setup'` is not an always-allowed path of the MFA enforcers.
 *
 * @implements Rule<InClassNode>
 */
final class MfaLoginStateRule implements Rule
{
    private const STATE = 'App\Security\Mfa\MfaSessionState';
    private const ENFORCERS = [
        'App\EventSubscriber\MfaEnforcerSubscriber',
        'App\EventSubscriber\MfaEnrollmentEnforcerSubscriber',
    ];
    /** Strings that start with `_security_` but are no session key of a firewall: [class, value]. */
    private const NOT_A_SESSION_KEY = [
        ['App\EventSubscriber\SecurityEventSubscriber', '_security_token'], // request attribute, read only
    ];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        if (!str_starts_with($class->getName(), 'App\\') || $class->getName() === self::STATE) {
            return [];
        }
        $classNode = $node->getOriginalNode();
        $file = $scope->getFile();
        $rel = GateAst::relative($file);
        $errors = [];

        foreach (GateAst::find($classNode, static fn (Node $n): bool => $n instanceof Node\Scalar\String_) as $string) {
            \assert($string instanceof Node\Scalar\String_);
            if (preg_match('/^_security\.mfa_/', $string->value) === 1) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Gate 91 [%s]: session key "%s" outside MfaSessionState — use the class.',
                    $rel,
                    $string->value,
                ))->identifier('gate91.sessionKey')->line($string->getStartLine())->build();
            }
        }

        $parents = GateAst::parents($classNode);
        foreach ($this->sites($classNode, $class) as [$site, $what]) {
            $method = $this->methodOf($site, $parents);
            if ($method !== null && ($this->begins($method, $class, $scope)
                || GateAst::markerOnMember($file, '@mfa-ok', $method))) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Gate 91 [%s]: %s without MfaSessionState::begin() in the same method — a login that never asks '
                . 'for the second factor (or `// @mfa-ok: <reason>` on the method).',
                $rel,
                $what,
            ))->identifier('gate91.handMadeLogin')->line($site->getStartLine())->build();
        }

        if (in_array($class->getName(), self::ENFORCERS, true)) {
            foreach ($classNode->getConstants() as $constants) {
                foreach ($constants->consts as $const) {
                    if ($const->name->toString() !== 'ALLOWED_PREFIXES') {
                        continue;
                    }
                    foreach (GateAst::find($const->value, static fn (Node $n): bool => $n instanceof Node\Scalar\String_
                        && rtrim($n->value, '/') === '/setup') as $setup) {
                        $errors[] = RuleErrorBuilder::message(sprintf(
                            'Gate 91 [%s]: \'/setup\' always allowed while MFA is outstanding — only before setup.',
                            $rel,
                        ))->identifier('gate91.setupAllowed')->line($setup->getStartLine())->build();
                    }
                }
            }
        }

        return $errors;
    }

    /** @return list<array{Node, string}> */
    private function sites(Node\Stmt\ClassLike $classNode, ClassReflection $class): array
    {
        $sites = [];
        foreach (GateAst::find($classNode, static fn (Node $n): bool => true) as $n) {
            if (GateAst::isMethodCall($n, 'setToken')) {
                \assert($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall);
                if ($n->isFirstClassCallable() || !GateAst::isNull(GateAst::arg($n, 0))) {
                    $sites[] = [$n, 'token put into the token storage'];
                }
            } elseif ($n instanceof Node\Scalar\String_ && strcasecmp($n->value, 'setToken') === 0) {
                $sites[] = [$n, 'setToken called by name'];
            } elseif ($n instanceof Node\Scalar\String_ && str_starts_with($n->value, '_security_')
                && !in_array([$class->getName(), $n->value], self::NOT_A_SESSION_KEY, true)) {
                $sites[] = [$n, sprintf('firewall session key "%s"', $n->value)];
            } elseif ($n instanceof Node\Scalar\InterpolatedString && $n->parts !== []
                && $n->parts[0] instanceof Node\InterpolatedStringPart
                && str_starts_with($n->parts[0]->value, '_security_')) {
                $sites[] = [$n, 'firewall session key built from "_security_"'];
            } elseif ($n instanceof Expr\Variable && $n->name === '_SESSION') {
                $sites[] = [$n, '$_SESSION written by hand'];
            }
        }

        return $sites;
    }

    /** @param \SplObjectStorage<Node, Node> $parents */
    private function methodOf(Node $node, \SplObjectStorage $parents): ?ClassMethod
    {
        while ($parents->contains($node)) {
            $node = $parents[$node];
            if ($node instanceof ClassMethod) {
                return $node;
            }
        }

        return null;
    }

    private function begins(ClassMethod $method, ClassReflection $class, Scope $scope): bool
    {
        foreach (GateAst::find($method, static fn (Node $n): bool => GateAst::isMethodCall($n, 'begin')) as $call) {
            \assert($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall);
            if (GateAst::isA($call->var, $class, $method, $scope, self::STATE)) {
                return true;
            }
        }

        return false;
    }
}
