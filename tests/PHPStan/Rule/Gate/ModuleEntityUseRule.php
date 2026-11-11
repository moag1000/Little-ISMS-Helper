<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Gate 106 (S35): a controller that reads or writes the records of an optional module is gated by that module.
 *
 * Gate 11 recognises a controller by keywords in its name and route ("dpia", "supplier", ...); a page of a module
 * the keyword list does not know (compliance, KPI thresholds, risk governance, data retention) slipped through. This
 * rule starts from the other end: the entity table of config/modules.yaml (`entities` of each module) names which
 * module owns which record class, and every class under App\Controller that mentions such a class must be gated by
 * that module — `#[RequiresModule]` on the class, or on every route action, or a deciding checkModuleActive()
 * ({@see ModuleGateRule}), or `// @no-module-gate-required: <reason>` on the class or action (a dashboard or hub that
 * shows the record beside others and hides the part itself).
 *
 * Not in the table: a record class listed by two modules (Supplier: suppliers, LkSG, MaRisk, DORA) or by a module that
 * cannot be switched off (Risk, Asset, Incident, Control, audits, reviews are required since S35) — there is no
 * single module to demand.
 *
 * @implements Rule<InClassNode>
 */
final class ModuleEntityUseRule implements Rule
{
    private const MARKER = '@no-module-gate-required';
    private const ROUTE = ['Symfony\Component\Routing\Attribute\Route', 'Symfony\Component\Routing\Annotation\Route'];

    /**
     * Master data other pages only refer to (a person as owner, a location as site): their own pages are gated by
     * keyword (Gate 11), a picker elsewhere is not a use of the module.
     */
    private const REFERENCE_MODULES = ['persons', 'locations'];

    private readonly ModuleGateRule $gate;

    /** @var array<string, string> record short name => module key */
    private readonly array $owners;

    /** @param array<string, string>|null $owners override for the fixture test; default: config/modules.yaml */
    public function __construct(?array $owners = null)
    {
        $this->gate = new ModuleGateRule();
        $this->owners = $owners ?? self::ownersFromModulesYaml(dirname(__DIR__, 4) . '/config/modules.yaml');
    }

    /** @return array<string, string> */
    public static function ownersFromModulesYaml(string $file): array
    {
        $modules = Yaml::parseFile($file)['modules'] ?? Yaml::parseFile($file);
        $listed = [];
        foreach ($modules as $key => $module) {
            foreach ((array) ($module['entities'] ?? []) as $entity) {
                $listed[(string) $entity][] = ['module' => (string) $key, 'required' => (bool) ($module['required'] ?? false)];
            }
        }
        $owners = [];
        foreach ($listed as $entity => $entries) {
            if (count($entries) === 1 && !$entries[0]['required'] && !in_array($entries[0]['module'], self::REFERENCE_MODULES, true)) {
                $owners[$entity] = $entries[0]['module'];
            }
        }

        return $owners;
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $reflection = $node->getClassReflection();
        if (!str_starts_with($reflection->getName(), 'App\Controller\\') || $reflection->isAbstract()) {
            return [];
        }
        $class = $node->getOriginalNode();
        $file = $scope->getFile();
        if (!$class instanceof Node\Stmt\Class_ || GateAst::markerOnMember($file, self::MARKER, $class)) {
            return [];
        }

        // Entities a member mentions, by module: a routed public action answers for itself, anything else (helpers,
        // constructor, properties) counts for every action of the class.
        $actions = [];
        $perAction = [];
        $classWide = [];
        foreach ($class->stmts as $member) {
            $isAction = $member instanceof Node\Stmt\ClassMethod && $member->isPublic()
                && (GateAst::attributes($member, ...self::ROUTE) !== []
                    || ($member->name->toLowerString() === '__invoke' && GateAst::attributes($class, ...self::ROUTE) !== []));
            $found = $this->entitiesIn($member);
            if ($isAction) {
                if (!GateAst::markerOnMember($file, self::MARKER, $member)) {
                    $actions[] = $member;
                    $perAction[spl_object_id($member)] = $found;
                }
            } else {
                foreach ($found as $module => $entities) {
                    $classWide[$module] = [...($classWide[$module] ?? []), ...$entities];
                }
            }
        }

        $classModules = $this->gate->requiredModules($class, $scope);
        $errors = [];
        foreach ($actions as $method) {
            $needs = $classWide;
            foreach ($perAction[spl_object_id($method)] as $module => $entities) {
                $needs[$module] = [...($needs[$module] ?? []), ...$entities];
            }
            $gated = [...$classModules, ...$this->gate->requiredModules($method, $scope), ...$this->gate->decidingChecks($method, $scope)];
            foreach ($needs as $module => $entities) {
                if (in_array($module, $gated, true)) {
                    continue;
                }
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Gate 106 [%s:%s:%s:%s]: %s uses %s of module "%s" but %s() is not gated by it — '
                    . '#[RequiresModule(\'%s\')], a deciding checkModuleActive() or `// %s: <reason>`.',
                    GateAst::relative($file),
                    $class->name?->toString() ?? '',
                    $module,
                    $method->name->toString(),
                    $class->name?->toString() ?? 'controller',
                    implode('/', array_values(array_unique($entities))),
                    $module,
                    $method->name->toString(),
                    $module,
                    self::MARKER,
                ))->identifier('gate106.entityUse')->line($method->name->getStartLine())->build();
            }
        }

        return $errors;
    }

    /**
     * Owned record classes a member mentions, as module => short names.
     *
     * @return array<string, list<string>>
     */
    private function entitiesIn(Node $member): array
    {
        $used = [];
        foreach (GateAst::find($member, static fn (Node $n): bool => $n instanceof Node\Name) as $name) {
            $resolved = $name->getAttribute('resolvedName');
            $fqcn = ltrim($resolved instanceof Node\Name ? $resolved->toString() : $name->toString(), '\\');
            if (!str_starts_with($fqcn, 'App\\Entity\\')) {
                continue;
            }
            $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            if (isset($this->owners[$short])) {
                $used[$this->owners[$short]][] = $short;
            }
        }

        return $used;
    }
}
