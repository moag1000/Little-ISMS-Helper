<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every `module:` value in a workflow YAML must name a module defined in
 * config/modules.yaml. ModuleGateGuard blocks a transition whose module is
 * inactive; an unknown key is never active, so a typo silently blocks the
 * transition for every tenant (threat_intelligence used `threat`,
 * prototype_protection_assessment used `prototype_protection`).
 */
final class WorkflowModuleKeysTest extends TestCase
{
    #[Test]
    public function everyWorkflowModuleKeyExistsInModulesYaml(): void
    {
        $root = \dirname(__DIR__, 2);
        $modules = Yaml::parseFile($root . '/config/modules.yaml')['modules'] ?? [];
        self::assertNotEmpty($modules);

        $files = array_merge(
            glob($root . '/config/workflows/*.yaml') ?: [],
            glob($root . '/config/workflows/*/*.yaml') ?: [],
        );
        self::assertNotEmpty($files);

        $found = 0;
        $unknown = [];
        foreach ($files as $file) {
            $data = Yaml::parseFile($file);
            foreach ($this->collectModuleValues($data, '') as $path => $value) {
                ++$found;
                if (!array_key_exists($value, $modules)) {
                    $unknown[] = sprintf('%s %s: %s', basename($file), $path, $value);
                }
            }
        }

        self::assertGreaterThan(0, $found, 'No module keys found; the scanner is broken.');
        self::assertSame([], $unknown, "Unknown module keys in workflow YAML:\n" . implode("\n", $unknown));
    }

    /**
     * @return \Generator<string, string>
     */
    private function collectModuleValues(mixed $node, string $path): \Generator
    {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $child) {
            $childPath = $path . '.' . $key;
            if ($key === 'module' && is_string($child)) {
                yield $childPath => $child;
                continue;
            }
            yield from $this->collectModuleValues($child, $childPath);
        }
    }
}
