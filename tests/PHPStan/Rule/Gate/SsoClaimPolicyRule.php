<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 93, PHP part (T3: AST rule, replaces the src/ checks of check_sso_claim_policy.py; security.yaml,
 * services.yaml and .env stay there).
 *
 * S9 (2026-11): one class holds the claim rules (`App\Service\Sso\SsoClaimPolicy`). The textual gate accepted the
 * policy's name anywhere in an authenticator or in a class it imported — a docblock `{@see}` in
 * SsoAccountLinkPolicy kept AzureOAuthAuthenticator green with every policy call removed. Now:
 *
 *   1. Every authenticator class of the app (any class implementing Symfony's AuthenticatorInterface — found by
 *      type, so a firewall under `when@prod:` or `custom_authenticator:` cannot hide one) is either NON_SSO or
 *      listed in CLAIM_CHECKS, and calls each listed SsoClaimPolicy method itself (real calls, not comments), or
 *      calls the listed method of its flow class, which is itself listed with its policy calls.
 *   2. `->processResponse()` gets the request id (no empty call, no null): InResponseTo must be checked.
 *   3. SSO code builds no URL from the Host header (`getSchemeAndHttpHost()`, `getHttpHost()`, `getHost()`,
 *      `getUri()`, `getUriForPath()`): DEFAULT_URI (`SamlAuthFactory::baseUrl()`).
 *   4. src/Service/Sso combines no claims with array_merge/array_replace, `+` or spread — later (unsigned
 *      UserInfo) values would overwrite signed ones; `SsoClaimPolicy::mergeUserInfo()`.
 *   5. E-mail linking is off by default (`$allowEmailLinking = true` as a property or parameter default).
 *
 * @implements Rule<InClassNode>
 */
final class SsoClaimPolicyRule implements Rule
{
    private const POLICY = 'App\Service\Sso\SsoClaimPolicy';
    private const AUTHENTICATOR = 'Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface';

    /** Authenticators that take no identity claims from an outside IdP. */
    private const NON_SSO = ['App\Security\ApiTokenAuthenticator'];

    /**
     * The claim checks each SSO class runs itself: `policy` = SsoClaimPolicy methods that must be called,
     * `flow` = [class, method] it hands the claims to (that class is listed with its own checks).
     *
     * @var array<string, array{policy?: list<string>, flow?: array{string, string}}>
     */
    public const CLAIM_CHECKS = [
        'App\Security\AzureOAuthAuthenticator' => [
            'policy' => ['allowedAzureTenants', 'assertAzureTenant', 'isEmailVerified'],
        ],
        'App\Security\AzureSamlAuthenticator' => [
            'policy' => ['allowedAzureTenants', 'assertAzureTenant'],
        ],
        'App\Security\GenericOidcAuthenticator' => [
            'flow' => ['App\Service\Sso\OidcAuthenticationFlow', 'handleCallback'],
        ],
        'App\Service\Sso\OidcAuthenticationFlow' => [
            'policy' => ['assertIdToken', 'mergeUserInfo', 'isEmailVerified', 'assertEmailVerifiedForLinking'],
        ],
    ];

    private const HOST_HEADER_CALLS = ['getschemeandhttphost', 'gethttphost', 'gethost', 'geturi', 'geturiforpath'];
    /** Reads the Host only to compare a redirect target with the current site, never to build a URL. */
    private const HOST_READERS = ['App\Security\SafeRedirectTarget'];

    /** @param array<string, array{policy?: list<string>, flow?: array{string, string}}> $claimChecks */
    public function __construct(private readonly array $claimChecks = self::CLAIM_CHECKS)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $name = $class->getName();
        if (!str_starts_with($name, 'App\\')) {
            return [];
        }
        $classNode = $node->getOriginalNode();
        $rel = GateAst::relative($scope->getFile());
        $errors = [];
        $error = static function (string $id, Node $at, string $message) use (&$errors, $rel): void {
            $errors[] = RuleErrorBuilder::message(sprintf('Gate 93 [%s]: %s', $rel, $message))
                ->identifier('gate93.' . $id)->line($at->getStartLine())->build();
        };

        $isAuthenticator = !$class->isAbstract() && !$class->isInterface()
            && $class->implementsInterface(self::AUTHENTICATOR);
        if ($isAuthenticator && !in_array($name, self::NON_SSO, true) && !isset($this->claimChecks[$name])) {
            $error('unknownAuthenticator', $classNode, sprintf(
                'authenticator %s is not listed in SsoClaimPolicyRule::CLAIM_CHECKS (or NON_SSO) — name the '
                . 'SsoClaimPolicy checks it runs, and give it a claim contract in SsoClaimContractTest.',
                $name,
            ));
        }
        if (isset($this->claimChecks[$name])) {
            $checks = $this->claimChecks[$name];
            $called = $this->policyCalls($classNode);
            foreach ($checks['policy'] ?? [] as $method) {
                if (!in_array(strtolower($method), $called, true)) {
                    $error('policyNotCalled', $classNode, sprintf(
                        '%s takes SSO claims without calling SsoClaimPolicy::%s() (a name in a comment or an '
                        . 'imported class does not count).',
                        $name,
                        $method,
                    ));
                }
            }
            if (isset($checks['flow']) && !$this->callsFlow($classNode, $class, $scope, ...$checks['flow'])) {
                $error('flowNotCalled', $classNode, sprintf(
                    '%s must hand the claims to %s::%s(), which runs the SsoClaimPolicy checks.',
                    $name,
                    $checks['flow'][0],
                    $checks['flow'][1],
                ));
            }
        }

        $ssoCode = $this->isSsoCode($name, $classNode);
        foreach (GateAst::find($classNode, static fn (Node $n): bool => true) as $n) {
            if (GateAst::isMethodCall($n, 'processResponse')) {
                $arg = GateAst::arg($n, 0, 'requestId');
                if ($arg === null || GateAst::isNull($arg)) {
                    $error('processResponse', $n, 'processResponse() without the request id '
                        . '(SamlMessageGuard::consumeRequestId()) — InResponseTo unchecked.');
                }
            }
            if ($ssoCode && !in_array($name, self::HOST_READERS, true)
                && ($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall)
                && in_array(GateAst::callName($n), self::HOST_HEADER_CALLS, true)) {
                $error('hostHeader', $n, 'SSO URL from the Host header — use DEFAULT_URI (SamlAuthFactory::baseUrl()).');
            }
            if ($this->allowsLinkingByDefault($n)) {
                $error('emailLinkingDefault', $n, 'e-mail linking on by default.');
            }
        }

        if (str_starts_with($name, 'App\Service\Sso\\') && $name !== self::POLICY) {
            foreach (GateAst::find($classNode, fn (Node $n): bool => $this->mergesClaims($n)) as $merge) {
                $error('claimMerge', $merge, 'claims combined by hand — use SsoClaimPolicy::mergeUserInfo().');
            }
        }

        return $errors;
    }

    /** @return list<string> lower-case names of the SsoClaimPolicy methods the class calls */
    private function policyCalls(Node\Stmt\ClassLike $classNode): array
    {
        $names = [];
        foreach (GateAst::find($classNode, static fn (Node $n): bool => $n instanceof Expr\StaticCall
            && $n->class instanceof Node\Name && ltrim($n->class->toString(), '\\') === self::POLICY) as $call) {
            $names[] = (string) GateAst::callName($call);
        }

        return $names;
    }

    private function callsFlow(Node\Stmt\ClassLike $classNode, ClassReflection $class, Scope $scope, string $flow, string $method): bool
    {
        foreach ($classNode->getMethods() as $classMethod) {
            foreach (GateAst::find($classMethod, static fn (Node $n): bool => GateAst::isMethodCall($n, $method)) as $call) {
                \assert($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall);
                if (GateAst::isA($call->var, $class, $classMethod, $scope, $flow)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Code that builds SSO URLs: the security and SSO namespaces, and every class that names SAML/OIDC/OAuth. */
    private function isSsoCode(string $name, Node\Stmt\ClassLike $classNode): bool
    {
        if (preg_match('/^App\\\\(Security|Service\\\\Sso|Controller\\\\Sso)\\\\|^App\\\\Controller\\\\(SecurityController|SsoController)$/', $name) === 1
            || preg_match('/Sso|Saml|Oidc|OAuth/i', $name) === 1) {
            return true;
        }

        return GateAst::find($classNode, static fn (Node $n): bool => $n instanceof Node\Name
            && preg_match('/Saml|Oidc|OAuth2?\b|OneLogin/i', $n->toString()) === 1) !== [];
    }

    private function mergesClaims(Node $n): bool
    {
        if ($n instanceof Expr\FuncCall && in_array(GateAst::callName($n), [
            'array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive',
        ], true)) {
            return true;
        }
        // `[...$idToken, ...$userInfo]`: an array of nothing but spreads is a merge; a list that adds its own items
        // (`[...$roles, 'ROLE_USER']`) is not.
        if ($n instanceof Expr\Array_ && $n->items !== []) {
            foreach ($n->items as $item) {
                if (!$item instanceof Node\ArrayItem || !$item->unpack) {
                    return false;
                }
            }

            return true;
        }
        if ($n instanceof Expr\BinaryOp\Plus || $n instanceof Expr\AssignOp\Plus) {
            [$left, $right] = $n instanceof Expr\BinaryOp\Plus ? [$n->left, $n->right] : [$n->var, $n->expr];

            return !$this->isNumber($left) && !$this->isNumber($right);
        }

        return false;
    }

    /** A side of `+` that makes it arithmetic: a number, a numeric cast, a constant or another calculation. */
    private function isNumber(Expr $expr): bool
    {
        return $expr instanceof Node\Scalar\Int_ || $expr instanceof Node\Scalar\Float_
            || $expr instanceof Expr\Cast\Int_ || $expr instanceof Expr\Cast\Double
            || $expr instanceof Expr\ClassConstFetch || $expr instanceof Expr\ConstFetch
            || ($expr instanceof Expr\BinaryOp && !$expr instanceof Expr\BinaryOp\Plus)
            || ($expr instanceof Expr\FuncCall && in_array(GateAst::callName($expr), ['time', 'count', 'strlen', 'intdiv'], true));
    }

    private function allowsLinkingByDefault(Node $n): bool
    {
        if ($n instanceof Node\Param && $n->var instanceof Expr\Variable && is_string($n->var->name)) {
            return strcasecmp($n->var->name, 'allowEmailLinking') === 0 && $this->truthy($n->default);
        }
        if ($n instanceof Node\PropertyItem) {
            return strcasecmp($n->name->toString(), 'allowEmailLinking') === 0 && $this->truthy($n->default);
        }

        return false;
    }

    private function truthy(?Expr $default): bool
    {
        return GateAst::isTrue($default)
            || ($default instanceof Node\Scalar\Int_ && $default->value !== 0)
            || ($default instanceof Node\Scalar\String_ && in_array(strtolower($default->value), ['1', 'true', 'yes', 'on'], true));
    }
}
