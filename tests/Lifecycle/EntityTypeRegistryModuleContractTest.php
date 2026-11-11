<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Attribute\RequiresModule;
use App\Lifecycle\EntityTypeRegistry;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * S35: the module of a lifecycle type has one source, EntityTypeRegistry. It must name a real module, agree with the
 * `#[RequiresModule]` of the entity (REST gate) and with every `module:` a workflow YAML still carries.
 */
final class EntityTypeRegistryModuleContractTest extends KernelTestCase
{
    // A `module:` naming a required module (documents, notifications) is always active and needs no registry entry.

    #[Test]
    public function everyRegistryModuleIsARealOptionalOrRequiredModule(): void
    {
        $modules = static::getContainer()->get(ModuleConfigurationService::class)->getAllModules();
        $registry = new EntityTypeRegistry();
        $bad = [];
        foreach ($registry->knownSlugs() as $slug) {
            $module = $registry->moduleOf($slug);
            if ($module !== null && !isset($modules[$module])) {
                $bad[] = "{$slug}: unknown module {$module}";
            }
        }

        self::assertSame([], $bad);
    }

    #[Test]
    public function theRegistryAgreesWithTheEntityAttribute(): void
    {
        $registry = new EntityTypeRegistry();
        $bad = [];
        foreach ($registry->knownSlugs() as $slug) {
            $class = $registry->lookup($slug)['class'];
            $attributes = (new \ReflectionClass($class))->getAttributes(RequiresModule::class);
            $attribute = $attributes[0] ?? null;
            $declared = $attribute?->newInstance()->module;
            if ($declared !== null && $declared !== $registry->moduleOf($slug)) {
                $bad[] = sprintf('%s: registry %s, entity #[RequiresModule(%s)]', $slug, $registry->moduleOf($slug) ?? 'null', $declared);
            }
        }

        self::assertSame([], $bad);
    }

    #[Test]
    public function aWorkflowYamlNeverNamesAnotherModuleThanTheRegistry(): void
    {
        $required = array_keys(static::getContainer()->get(ModuleConfigurationService::class)->getRequiredModules());
        $registry = new EntityTypeRegistry();
        $byWorkflow = [];
        foreach ($registry->knownSlugs() as $slug) {
            $byWorkflow[$registry->lookup($slug)['workflow']] = $registry->moduleOf($slug);
        }
        $bad = [];
        foreach (glob(dirname(__DIR__, 2) . '/config/workflows/*.yaml') ?: [] as $file) {
            $yaml = Yaml::parseFile($file);
            foreach ($yaml['framework']['workflows'] ?? [] as $name => $definition) {
                if (!array_key_exists($name, $byWorkflow)) {
                    continue;
                }
                foreach ($definition['transitions'] ?? [] as $transition => $config) {
                    $module = $config['metadata']['module'] ?? null;
                    if ($module !== null && !in_array($module, $required, true) && $module !== $byWorkflow[$name]) {
                        $bad[] = sprintf('%s.%s: YAML module %s, registry %s', $name, (string) $transition, $module, $byWorkflow[$name] ?? 'null');
                    }
                }
            }
        }

        self::assertSame([], $bad);
    }

    #[Test]
    public function aRecordClassHasTheModuleOfItsType(): void
    {
        self::assertSame('privacy', EntityTypeRegistry::moduleOfClass('DataBreach'));
        self::assertSame('privacy', EntityTypeRegistry::moduleOfClass(\App\Entity\DataBreach::class));
        self::assertNull(EntityTypeRegistry::moduleOfClass('Document'));
        self::assertNull(EntityTypeRegistry::moduleOfClass('NoSuchRecord'));
    }
}
