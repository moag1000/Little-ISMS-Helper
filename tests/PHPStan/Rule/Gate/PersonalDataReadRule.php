<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 104 (S33): pages of a record about a person carry a read rule. A class marked
 * `#[PersonalDataResource]` without a field list (data subject request, data breach, consent, …) is read only by
 * managers (DPO included) and the people responsible for the record — {@see \App\Security\Voter\PersonalDataRecordVoter}.
 *
 * Checked in every class below App\Controller that has a GET route taking such a record as parameter:
 *  - that action carries `#[IsGranted]` (on the action or the class) naming the voter attribute
 *    (`PersonalDataRecordVoter::VIEW`), `RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE`,
 *    `PrivacyExportAccess::RULE` or a role from ROLE_MANAGER up — `ROLE_USER` alone is no rule;
 *  - every other GET action of that controller (the list, the dashboard) is gated like that, or filters with
 *    `PersonalDataRecordVoter::VIEW` or `PersonalDataVisibility::readable()/readableItems()` (a constructor property of
 *    that type) in its body or in a method of the class it calls (list and detail page, one rule).
 *
 * A reviewed exception carries `// @personal-data-read-ok: <reason>` in the lead of the action or class, or an
 * entry in phpstan-gates-baseline.neon (`gate104.read`). Static reflection only (ReflectionProvider).
 *
 * @implements Rule<InClassNode>
 */
final class PersonalDataReadRule implements Rule
{
    private const MARKER = '@personal-data-read-ok';
    private const ROUTE = ['Symfony\Component\Routing\Attribute\Route', 'Symfony\Component\Routing\Annotation\Route'];
    private const IS_GRANTED = 'Symfony\Component\Security\Http\Attribute\IsGranted';
    private const MARKER_ATTRIBUTE = 'App\Attribute\PersonalDataResource';
    private const VOTER = 'App\Security\Voter\PersonalDataRecordVoter';
    private const VISIBILITY = 'App\Security\PersonalDataVisibility';
    private const VISIBILITY_METHODS = ['readable', 'readableitems'];

    /** Constants that are a read rule as strict as the voter's. */
    private const RULE_CONSTANTS = [
        'App\Security\Voter\PersonalDataRecordVoter::VIEW',
        'App\Security\Voter\RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE',
        'App\Security\Voter\RecordResponsibleVoter::RESPONSIBLE',
        'App\Security\PrivacyExportAccess::RULE',
    ];

    /** Roles that reach ROLE_MANAGER (or are the DPO's). */
    private const ROLES = '/^(ROLE_MANAGER|ROLE_DPO|ROLE_CISO|ROLE_ADMIN|ROLE_SUPER_ADMIN|PERSONAL_DATA_VIEW|RECORD_RESPONSIBLE)$/';

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
        if (!str_starts_with($class->getName(), 'App\Controller\\') || $class->isAbstract()) {
            return [];
        }
        $classNode = $node->getOriginalNode();
        $file = $scope->getFile();
        if (GateAst::markerOnMember($file, self::MARKER, $classNode)) {
            return [];
        }

        $methods = [];
        foreach ($classNode->getMethods() as $method) {
            $methods[$method->name->toLowerString()] = $method;
        }
        $classGuarded = $this->guarded($classNode, $scope);
        $visibilityProperties = $this->visibilityProperties($methods['__construct'] ?? null, $scope);

        $getActions = [];
        $recordActions = [];
        foreach ($classNode->getMethods() as $method) {
            if (!$method->isPublic() || !$this->servesGet($method, $scope)) {
                continue;
            }
            $getActions[] = $method;
            if ($this->takesPersonRecord($method, $scope)) {
                $recordActions[$method->name->toLowerString()] = true;
            }
        }
        if ($recordActions === []) {
            return [];
        }

        $errors = [];
        foreach ($getActions as $method) {
            if ($classGuarded || $this->guarded($method, $scope) || GateAst::markerOnMember($file, self::MARKER, $method)) {
                continue;
            }
            $isRecord = isset($recordActions[$method->name->toLowerString()]);
            if (!$isRecord && $this->filters($method, $methods, $visibilityProperties, [])) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Gate 104 [%s::%s#read]: %s of a #[PersonalDataResource] record without read rule — %s',
                GateAst::relative($file),
                $method->name->toString(),
                $isRecord ? 'the detail page' : 'a page in the controller',
                $isRecord
                    ? 'add #[IsGranted(PersonalDataRecordVoter::VIEW, subject: \'…\')] (managers, DPO, responsible).'
                    : 'filter the records with PersonalDataRecordVoter::VIEW or gate the page by role (S33).',
            ))->identifier('gate104.read')->line($method->name->getStartLine())->build();
        }

        return $errors;
    }

    private function servesGet(ClassMethod $method, Scope $scope): bool
    {
        foreach (GateAst::attributes($method, ...self::ROUTE) as $route) {
            $methods = null;
            foreach ($route->args as $arg) {
                if ($arg->name?->toString() === 'methods') {
                    $methods = GateAst::strings($arg->value, $scope);
                }
            }
            if ($methods === null || $methods === [] || in_array('GET', array_map('strtoupper', $methods), true)) {
                return true;
            }
        }

        return false;
    }

    private function takesPersonRecord(ClassMethod $method, Scope $scope): bool
    {
        foreach ($method->params as $param) {
            $type = $param->type instanceof Node\NullableType ? $param->type->type : $param->type;
            if (!$type instanceof Node\Name) {
                continue;
            }
            $name = $scope->resolveName($type);
            if (!$this->reflectionProvider->hasClass($name)) {
                continue;
            }
            foreach ($this->reflectionProvider->getClass($name)->getNativeReflection()->getAttributes(self::MARKER_ATTRIBUTE) as $attribute) {
                $fields = $attribute->getArguments()['fields'] ?? $attribute->getArguments()[0] ?? [];
                if ($fields === []) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Does the member carry an #[IsGranted] that is a read rule (role from manager up, the voter, an expression)? */
    private function guarded(Node $member, Scope $scope): bool
    {
        foreach (GateAst::attributes($member, self::IS_GRANTED) as $attribute) {
            $first = $attribute->args[0]->value ?? null;
            if ($first === null) {
                continue;
            }
            if ($first instanceof Expr\New_) {
                $first = $first->args[0]->value ?? null; // new Expression(<rule>)
                if (!$first instanceof Expr) {
                    continue;
                }
            }
            if ($first instanceof Expr\ClassConstFetch && $first->class instanceof Node\Name && $first->name instanceof Node\Identifier) {
                if (in_array($scope->resolveName($first->class) . '::' . $first->name->toString(), self::RULE_CONSTANTS, true)) {
                    return true;
                }
            }
            foreach (GateAst::strings($first, $scope) as $value) {
                if (preg_match(self::ROLES, $value) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Names of the promoted constructor properties typed PersonalDataVisibility.
     *
     * @return list<string>
     */
    private function visibilityProperties(?ClassMethod $constructor, Scope $scope): array
    {
        $names = [];
        foreach ($constructor?->params ?? [] as $param) {
            $type = $param->type instanceof Node\NullableType ? $param->type->type : $param->type;
            if ($param->flags !== 0 && $type instanceof Node\Name && $param->var instanceof Expr\Variable
                && is_string($param->var->name) && $scope->resolveName($type) === self::VISIBILITY) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }

    /**
     * Does the action (or a method of the class it calls) filter with PersonalDataRecordVoter::VIEW
     * or PersonalDataVisibility::readable()/readableItems()?
     *
     * @param array<string, ClassMethod> $methods
     * @param list<string>               $visibilityProperties
     * @param list<string>               $seen
     */
    private function filters(ClassMethod $method, array $methods, array $visibilityProperties, array $seen): bool
    {
        $voter = GateAst::find($method, static fn (Node $n): bool => $n instanceof Expr\ClassConstFetch
            && $n->class instanceof Node\Name && ltrim($n->class->toString(), '\\') === self::VOTER
            && $n->name instanceof Node\Identifier && $n->name->toString() === 'VIEW');
        if ($voter !== []) {
            return true;
        }
        $visibility = GateAst::find($method, static fn (Node $n): bool => ($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall)
            && $n->name instanceof Node\Identifier && in_array($n->name->toLowerString(), self::VISIBILITY_METHODS, true)
            && ($n->var instanceof Expr\PropertyFetch || $n->var instanceof Expr\NullsafePropertyFetch)
            && $n->var->var instanceof Expr\Variable && $n->var->var->name === 'this'
            && $n->var->name instanceof Node\Identifier && in_array($n->var->name->toString(), $visibilityProperties, true));
        if ($visibility !== []) {
            return true;
        }
        $calls = GateAst::find($method, static fn (Node $n): bool => $n instanceof Expr\MethodCall
            && $n->var instanceof Expr\Variable && $n->var->name === 'this' && $n->name instanceof Node\Identifier);
        foreach ($calls as $call) {
            \assert($call instanceof Expr\MethodCall && $call->name instanceof Node\Identifier);
            $name = $call->name->toLowerString();
            if (isset($methods[$name]) && !in_array($name, $seen, true)
                && $this->filters($methods[$name], $methods, $visibilityProperties, [...$seen, $method->name->toLowerString(), $name])) {
                return true;
            }
        }

        return false;
    }
}
