<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Every rule of `lifecycle.auto_transition_rules` can fire (B1, lesson 10).
 *
 * FieldCompletionAutoTransition skips a rule silently when the workflow has no such transition
 * (`$workflow->can()` false) and logs only a notice when the transition needs four eyes — an
 * automatic actor is always refused there. Two rules sat dead that way: an Incident rule naming a
 * `review` transition incident_lifecycle never had, and a Risk rule targeting the four-eyes
 * `accept`. This test reads the real YAML: each rule names a workflow that supports the entity,
 * a transition of that workflow without four eyes, and fields the entity has.
 */
final class AutoTransitionRulesTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return array<string, array<string, mixed>> workflow name => definition */
    private static function workflows(): array
    {
        $workflows = [];
        foreach ((new Finder())->files()->in(self::root() . '/config/workflows')->depth(0)->name('*.yaml') as $file) {
            $parsed = Yaml::parseFile($file->getRealPath());
            foreach ($parsed['framework']['workflows'] ?? [] as $name => $definition) {
                $workflows[$name] = $definition;
            }
        }

        return $workflows;
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private static function rules(): array
    {
        $config = Yaml::parseFile(self::root() . '/config/packages/lifecycle.yaml');

        return $config['parameters']['lifecycle.auto_transition_rules'] ?? [];
    }

    #[Test]
    public function everyRuleHitsAnAutomaticallyAllowedTransition(): void
    {
        $workflows = self::workflows();
        $rules = self::rules();
        self::assertNotSame([], $rules, 'lifecycle.yaml defines auto-transition rules');

        $checked = 0;
        foreach ($rules as $class => $classRules) {
            self::assertTrue(class_exists($class), sprintf('%s exists', $class));
            foreach ($classRules as $name => $rule) {
                $label = sprintf('%s rule "%s"', $class, $name);
                $workflow = $workflows[$rule['workflow']] ?? null;
                self::assertNotNull($workflow, sprintf('%s: workflow %s is defined in config/workflows', $label, $rule['workflow']));
                self::assertContains($class, (array) ($workflow['supports'] ?? []), sprintf('%s: %s supports the entity', $label, $rule['workflow']));

                $transition = $workflow['transitions'][$rule['transition']] ?? null;
                self::assertNotNull($transition, sprintf('%s: %s has a transition "%s"', $label, $rule['workflow'], $rule['transition']));
                self::assertNotTrue(
                    $transition['metadata']['four_eyes'] ?? false,
                    sprintf('%s: transition "%s" needs four eyes, an automatic actor is always refused', $label, $rule['transition']),
                );
                ++$checked;
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    #[Test]
    public function everyRuleReadsFieldsTheEntityHas(): void
    {
        foreach (self::rules() as $class => $classRules) {
            $reflection = new \ReflectionClass($class);
            foreach ($classRules as $name => $rule) {
                $fields = $rule['required_fields'] ?? [];
                foreach (['all', 'any'] as $join) {
                    foreach ($rule['conditions'][$join] ?? [] as $condition) {
                        $fields[] = $condition['field'];
                        if (isset($condition['value_from'])) {
                            $fields[] = $condition['value_from'];
                        }
                    }
                }
                self::assertNotSame([], $fields, sprintf('%s rule "%s" has a condition', $class, $name));
                foreach ($fields as $field) {
                    self::assertTrue(
                        $reflection->hasProperty($field),
                        sprintf('%s rule "%s" reads "%s", which %s does not have — the rule never matches', $class, $name, $field, $class),
                    );
                }
            }
        }
    }
}
