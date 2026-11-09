<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 11 module gate (T3: AST rule, replaces check_module_gating.py). Every feature of an optional framework is
 * gated by its module (CLAUDE.md "Module-Awareness").
 *
 * Form pass (every class below App\Form): a field whose name signals a framework (DORA, NIS-2, LkSG, MaRisk,
 * TISAX, GDPR/DPIA, BaFin, ...) is added inside `if ($this->isModuleActive('<its module>'))` /
 * `isAnyModuleActive(...)` — the condition is the module call, alone or joined with `&&` (an `||`, a negation or
 * the `else` branch gates nothing) — through `addModuleGatedField($builder, '<its module>', ...)`, or in a helper
 * method every call of which sits in such a block. Field names are evaluated, so `self::DORA_FIELD` counts; a
 * call spread over several lines is one node. A reviewed exception carries
 * `// @no-module-gate-required: <reason>` on or directly above the field.
 *
 * Controller pass (every class below App\Controller): an action whose class name, route path or route name
 * signals an optional module is gated by one of those modules through `#[RequiresModule]` (the attribute, not a
 * commented-out one) on the action or class, or through `checkModuleActive()` / `isModuleActive()` whose result
 * ends the action (an `if` that returns or throws, a return) — a bare call or one only logged gates nothing, nor
 * does `false && ...`. Exception: `// @no-module-gate-required: <reason>` in the lead of the action or class.
 *
 * @implements Rule<InClassNode>
 */
final class ModuleGateRule implements Rule
{
    private const MARKER = '@no-module-gate-required';
    private const ROUTE = ['Symfony\Component\Routing\Attribute\Route', 'Symfony\Component\Routing\Annotation\Route'];
    private const REQUIRES_MODULE = 'App\Attribute\RequiresModule';

    /** Field names that signal a framework (case-insensitive). */
    private const REGULATORY = '/dora|lksg|marisk|nis2|tisax|gdpr|^bafin|^leiCode$|^naceCode$|^requiresDpia$|^legalBasis'
        . '|^processingActivities$|^outsourcingClassification$|^outsourcingDueDiligence|^outsourcingExitStrategy$'
        . '|^riskBearingCapacityImpact$|^boardLevelRiskAcceptance$|^complianceFunctionInvolvement$'
        . '|^internalAuditFunctionInvolvement$/i';

    /** The module(s) a regulatory field belongs to; the first match wins. Unlisted fields accept any module gate. */
    private const FIELD_MODULES = [
        '/gdpr|dpia|^legalBasis|^processingActivities$/i' => ['privacy'],
        '/tisax/i' => ['tisax'],
        '/lksg/i' => ['lksg'],
        '/dora|nis2|bafin|^leiCode$|^naceCode$|^outsourcing|marisk|^riskBearingCapacityImpact$|^boardLevelRiskAcceptance$'
            . '|^complianceFunctionInvolvement$|^internalAuditFunctionInvolvement$/i' => ['nis2_dora', 'marisk'],
    ];

    /** Pattern over "<ControllerClass> <full route path> <route names>" => module. */
    private const CONTROLLER_MODULES = [
        '/dora|nis2|ict[-_]?provider|kritis/i' => 'nis2_dora',
        '/lksg/i' => 'lksg',
        '/grundschutz|gstool|cross[-_]gap/i' => 'bsi_grundschutz',
        '/tisax|prototype[-_]?protection/i' => 'tisax',
        '/training/i' => 'training',
        '/supplier/i' => 'suppliers',
        '/data[-_]?breach|dpia|consent|processing[-_]?activit|data[-_]?subject|privacy|(?<![a-z])dpo(?![a-z])|'
            . 'dpodashboard|gdpr|verfahrensverzeichnis|(?<![a-z])vvt(?![a-z])|transfer[-_]?impact|(?<![a-z])dpa(?![a-z])|'
            . 'dpacontroller/i' => 'privacy',
        '/bcm|business[-_]?continuity|bc[-_]?exercise|crisis[-_]?team|business[-_]?process/i' => 'bcm',
        '/mris/i' => 'mris',
        '/vulnerabilit|threat[-_]?intel/i' => 'vulnerability_intel',
        '/(?<![a-z])patch/i' => 'patches',
        '/marisk/i' => 'marisk',
        '/ai[-_]?agent|ai[-_]?governance/i' => 'ai_governance',
        '/authority/i' => 'eu_authority_reporting',
        '/answer[-_]?library/i' => 'answer_library',
        '/(?<![a-z])planning/i' => 'resource_planning',
        '/change[-_]?request/i' => 'change_requests',
        '/(?<![a-z])locations?(?=[^a-z]|controller|$)/i' => 'locations',
        '/(?<![a-z])persons?(?=[^a-z]|controller|$)/i' => 'persons',
        '/interested[-_]?part/i' => 'interested_parties',
        '/corrective[-_]?action/i' => 'corrective_actions',
        '/risk[-_]?appetite/i' => 'risk_appetite',
        '/risk[-_]?treatment[-_]?plan/i' => 'risk_treatment_plans',
        '/access[-_]?review/i' => 'access_review',
        '/analytics|fte[-_]?tracking/i' => 'analytics',
        '/report[-_]?builder/i' => 'report_builder',
        '/reports\/scheduled|scheduled[-_]?report/i' => 'scheduled_reports',
        '/portfolio|group[-_]?report/i' => 'portfolio_reports',
        '/admin\/notification\/|notification[-_]?center|\s\/notifications/i' => 'notifications',
    ];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->getClassReflection()->getName();
        if (str_starts_with($name, 'App\Form\\')) {
            return $this->formPass($node->getOriginalNode(), $scope);
        }
        if (str_starts_with($name, 'App\Controller\\') && !$node->getClassReflection()->isAbstract()) {
            return $this->controllerPass($node->getOriginalNode(), $scope);
        }

        return [];
    }

    // ── Form pass ────────────────────────────────────────────────────────

    /** @return list<IdentifierRuleError> */
    private function formPass(Node\Stmt\ClassLike $classNode, Scope $scope): array
    {
        $file = $scope->getFile();
        $parents = GateAst::parents($classNode);
        $methods = [];
        foreach ($classNode->getMethods() as $method) {
            $methods[$method->name->toLowerString()] = $method;
        }
        $errors = [];
        $calls = GateAst::find($classNode, static fn (Node $n): bool => GateAst::isMethodCall($n, 'add', 'addModuleGatedField'));
        foreach ($calls as $call) {
            \assert($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall);
            $viaHelper = GateAst::callName($call) === 'addmodulegatedfield';
            $fields = GateAst::strings(GateAst::arg($call, $viaHelper ? 2 : 0, $viaHelper ? 'name' : 'child'), $scope);
            foreach ($fields as $field) {
                if (preg_match(self::REGULATORY, $field) !== 1) {
                    continue;
                }
                if ($this->markerAt($file, $call, $parents)) {
                    continue;
                }
                $expected = $this->fieldModules($field);
                if ($viaHelper) {
                    $keys = GateAst::strings(GateAst::arg($call, 1, 'moduleKey'), $scope);
                    $gate = $keys === [] ? ['*'] : $keys;
                } else {
                    $gate = $this->gateOf($call, $parents, $methods, $scope);
                }
                if ($gate === null) {
                    $errors[] = $this->formError($file, $call, $field, sprintf(
                        'regulatory field "%s" added without a module gate (isModuleActive() block, '
                        . 'addModuleGatedField(), a helper only called from such a block, or `// %s: <reason>`).',
                        $field,
                        self::MARKER,
                    ));
                } elseif ($expected !== [] && !in_array('*', $gate, true) && array_intersect($gate, $expected) === []) {
                    $errors[] = $this->formError($file, $call, $field, sprintf(
                        'regulatory field "%s" is gated by %s, not by %s.',
                        $field,
                        implode('/', $gate),
                        implode('/', $expected),
                    ));
                }
            }
        }

        return $errors;
    }

    /** @param \SplObjectStorage<Node, Node> $parents */
    private function markerAt(string $file, Node $call, \SplObjectStorage $parents): bool
    {
        // The marker sits above the `->add(` line of a chain, which may start lines below the statement.
        $line = $call instanceof Expr\MethodCall && $call->name instanceof Node\Identifier
            ? $call->name->getStartLine() : $call->getStartLine();
        if (GateAst::markerAbove($file, self::MARKER, $line)) {
            return true;
        }
        $node = $call;
        while ($parents->contains($node) && !$node instanceof Node\Stmt) {
            $node = $parents[$node];
        }

        return $node instanceof Node\Stmt && GateAst::markerAbove($file, self::MARKER, $node->getStartLine());
    }

    /**
     * Modules of the gate around a node: ['*'] for a dynamic key, null when ungated. Walks up through if/elseif
     * blocks (the node must sit in the statements of the gated branch, not its else) and through private helpers
     * every call of which is gated.
     *
     * @param \SplObjectStorage<Node, Node> $parents
     * @param array<string, ClassMethod> $methods
     * @param list<string> $seen
     * @return list<string>|null
     */
    private function gateOf(Node $node, \SplObjectStorage $parents, array $methods, Scope $scope, array $seen = []): ?array
    {
        $child = $node;
        while ($parents->contains($child)) {
            $parent = $parents[$child];
            if (($parent instanceof Node\Stmt\If_ || $parent instanceof Node\Stmt\ElseIf_)
                && in_array($child, $parent->stmts, true)) {
                $modules = $this->gateCondition($parent->cond, $scope);
                if ($modules !== null) {
                    return $modules;
                }
            }
            if ($parent instanceof ClassMethod) {
                $name = $parent->name->toLowerString();
                if (!$parent->isPrivate() && !$parent->isProtected() || in_array($name, $seen, true)) {
                    return null;
                }
                $callers = GateAst::find(array_values($methods), static fn (Node $n): bool => $n instanceof Expr\MethodCall
                    && $n->var instanceof Expr\Variable && $n->var->name === 'this' && GateAst::callName($n) === $name);
                if ($callers === []) {
                    return null;
                }
                $all = [];
                foreach ($callers as $caller) {
                    $modules = $this->gateOf($caller, $parents, $methods, $scope, [...$seen, $name]);
                    if ($modules === null) {
                        return null;
                    }
                    $all = [...$all, ...$modules];
                }

                return array_values(array_unique($all));
            }
            $child = $parent;
        }

        return null;
    }

    /**
     * Modules a condition gates on: the module call alone or as an operand of `&&`. Null otherwise.
     *
     * @return list<string>|null
     */
    private function gateCondition(Expr $cond, Scope $scope): ?array
    {
        if ($cond instanceof Expr\BinaryOp\BooleanAnd || $cond instanceof Expr\BinaryOp\LogicalAnd) {
            if (GateAst::isFalse($cond->left) || GateAst::isFalse($cond->right)) {
                return null;
            }
            $left = $this->gateCondition($cond->left, $scope);
            $right = $this->gateCondition($cond->right, $scope);
            if ($left === null && $right === null) {
                return null;
            }

            return array_values(array_unique([...$left ?? [], ...$right ?? []]));
        }
        if (GateAst::isMethodCall($cond, 'isModuleActive', 'isAnyModuleActive')) {
            \assert($cond instanceof Expr\MethodCall || $cond instanceof Expr\NullsafeMethodCall);
            $keys = [];
            foreach ($cond->getArgs() as $arg) {
                $values = GateAst::strings($arg->value, $scope);
                if ($values === []) {
                    return ['*'];
                }
                $keys = [...$keys, ...$values];
            }

            return $keys === [] ? ['*'] : $keys;
        }

        return null;
    }

    /** @return list<string> */
    private function fieldModules(string $field): array
    {
        foreach (self::FIELD_MODULES as $pattern => $modules) {
            if (preg_match($pattern, $field) === 1) {
                return $modules;
            }
        }

        return [];
    }

    private function formError(string $file, Node $call, string $field, string $message): IdentifierRuleError
    {
        $line = $call instanceof Expr\MethodCall && $call->name instanceof Node\Identifier
            ? $call->name->getStartLine() : $call->getStartLine();

        return RuleErrorBuilder::message(sprintf('Gate 11 [%s:%s]: %s', GateAst::relative($file), $field, $message))
            ->identifier('gate11.formField')->line($line)->build();
    }

    // ── Controller pass ──────────────────────────────────────────────────

    /** @return list<IdentifierRuleError> */
    private function controllerPass(Node\Stmt\ClassLike $classNode, Scope $scope): array
    {
        $file = $scope->getFile();
        if (GateAst::markerOnMember($file, self::MARKER, $classNode)) {
            return [];
        }
        $className = $classNode->name?->toString() ?? '';
        $classRoutes = GateAst::attributes($classNode, ...self::ROUTE);
        [$prefixes, $classNames] = $this->routeParts($classRoutes, $scope);
        $classModules = $this->requiredModules($classNode, $scope);

        $errors = [];
        foreach ($classNode->getMethods() as $method) {
            if (!$method->isPublic()) {
                continue;
            }
            $routes = GateAst::attributes($method, ...self::ROUTE);
            if ($routes === [] && !($method->name->toLowerString() === '__invoke' && $classRoutes !== [])) {
                continue;
            }
            if (GateAst::markerOnMember($file, self::MARKER, $method)) {
                continue;
            }
            $gated = [...$classModules, ...$this->requiredModules($method, $scope), ...$this->decidingChecks($method, $scope)];
            [$paths, $names] = $routes === [] ? [[''], ['']] : $this->routeParts($routes, $scope);
            foreach ($paths as $i => $path) {
                foreach ($prefixes === [] ? [''] : $prefixes as $prefix) {
                    $haystack = sprintf('%s %s%s %s%s', $className, $prefix, $path, implode(' ', $classNames), $names[$i] ?? '');
                    $expected = [];
                    foreach (self::CONTROLLER_MODULES as $pattern => $module) {
                        if (preg_match($pattern, $haystack) === 1) {
                            $expected[] = $module;
                        }
                    }
                    if ($expected !== [] && array_intersect($expected, $gated) === []) {
                        $errors[] = RuleErrorBuilder::message(sprintf(
                            'Gate 11 [%s:%s:route]: route "%s%s" (%s::%s) serves module %s but has no '
                            . '#[RequiresModule], deciding checkModuleActive() or `// %s: <reason>`.',
                            GateAst::relative($file),
                            $method->name->toString(),
                            $prefix,
                            $path,
                            $className,
                            $method->name->toString(),
                            implode('/', array_unique($expected)),
                            self::MARKER,
                        ))->identifier('gate11.route')->line($method->name->getStartLine())->build();
                        continue 3;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Paths and names of route attributes.
     *
     * @param list<Node\Attribute> $routes
     * @return array{list<string>, list<string>}
     */
    private function routeParts(array $routes, Scope $scope): array
    {
        $paths = [];
        $names = [];
        foreach ($routes as $route) {
            $path = GateAst::strings(GateAst::arg($route, 0, 'path'), $scope);
            $name = GateAst::strings(GateAst::arg($route, 1, 'name'), $scope);
            foreach ($path === [] ? [''] : $path as $p) {
                $paths[] = $p;
                $names[] = implode(' ', $name);
            }
        }

        return [$paths, $names];
    }

    /** @return list<string> */
    private function requiredModules(Node $member, Scope $scope): array
    {
        $modules = [];
        foreach (GateAst::attributes($member, self::REQUIRES_MODULE) as $attr) {
            foreach ($attr->args as $arg) {
                $modules = [...$modules, ...GateAst::strings($arg->value, $scope)];
            }
        }

        return $modules;
    }

    /**
     * Modules of checkModuleActive()/isModuleActive() calls whose result ends the action.
     *
     * @return list<string>
     */
    private function decidingChecks(ClassMethod $method, Scope $scope): array
    {
        $isCheck = static fn (Node $n): bool => GateAst::isMethodCall($n, 'checkModuleActive', 'isModuleActive');
        $modulesIn = static function (Node $n) use ($isCheck, $scope): array {
            $modules = [];
            foreach (GateAst::find($n, $isCheck) as $call) {
                $modules = [...$modules, ...GateAst::strings(GateAst::arg($call, 0), $scope)];
            }

            return $modules;
        };
        $hasFalseAnd = static fn (Node $n): bool => GateAst::find($n, static fn (Node $x): bool => ($x instanceof Expr\BinaryOp\BooleanAnd
            || $x instanceof Expr\BinaryOp\LogicalAnd) && (GateAst::isFalse($x->left) || GateAst::isFalse($x->right))) !== [];

        $modules = [];
        $variables = [];
        foreach (GateAst::find($method, static fn (Node $n): bool => $n instanceof Expr\Assign, true) as $assign) {
            \assert($assign instanceof Expr\Assign);
            if ($assign->var instanceof Expr\Variable && is_string($assign->var->name) && $isCheck($assign->expr)) {
                $variables[$assign->var->name] = $modulesIn($assign->expr);
            }
        }
        foreach (GateAst::find($method, static fn (Node $n): bool => true, true) as $n) {
            if (($n instanceof Node\Stmt\If_ || $n instanceof Node\Stmt\ElseIf_) && !$hasFalseAnd($n->cond)
                && GateAst::endsAction($n)) {
                $modules = [...$modules, ...$modulesIn($n->cond)];
                foreach (GateAst::find($n->cond, static fn (Node $x): bool => $x instanceof Expr\Variable) as $var) {
                    \assert($var instanceof Expr\Variable);
                    if (is_string($var->name) && isset($variables[$var->name])) {
                        $modules = [...$modules, ...$variables[$var->name]];
                    }
                }
            }
            if ($n instanceof Node\Stmt\Return_ && $n->expr !== null && !$hasFalseAnd($n->expr)) {
                $modules = [...$modules, ...$modulesIn($n->expr)];
            }
        }

        return $modules;
    }
}
