<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\ModuleConfigurationService;
use App\Service\ReportBuilderService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * S11: a report widget shows data of one module, and returns nothing while that module
 * is off — through the designer's widget-data endpoint, the preview and the PDF (all call ReportBuilderService::getWidgetData()).
 */
final class ReportWidgetModuleTest extends TestCase
{
    #[Test]
    public function everyWidgetTypeNamesItsModule(): void
    {
        $types = [];
        foreach ((new \ReflectionClass(ReportBuilderService::class))->getReflectionConstants() as $constant) {
            $name = $constant->getName();
            if (str_starts_with($name, 'WIDGET_') && !str_starts_with($name, 'WIDGET_CATEGORY_') && $name !== 'WIDGET_MODULES') {
                $types[] = $constant->getValue();
            }
        }
        sort($types);
        $mapped = array_keys(ReportBuilderService::WIDGET_MODULES);
        sort($mapped);

        self::assertSame($types, $mapped);
    }

    #[Test]
    public function theModulesExistAndMatchTheWidgetLibrary(): void
    {
        $modules = array_keys(Yaml::parseFile(dirname(__DIR__, 2) . '/config/modules.yaml')['modules']);
        foreach (ReportBuilderService::WIDGET_MODULES as $type => $module) {
            self::assertTrue($module === null || in_array($module, $modules, true), sprintf('%s: unknown module %s', $type, $module));
        }

        foreach ($this->service(true)->getWidgetLibrary() as $category) {
            foreach ($category['widgets'] as $type => $widget) {
                self::assertArrayHasKey($type, ReportBuilderService::WIDGET_MODULES, $type . ' is missing in WIDGET_MODULES');
                self::assertSame(
                    ReportBuilderService::WIDGET_MODULES[$type],
                    $widget['module'] ?? null,
                    sprintf('Widget %s: library module and WIDGET_MODULES differ.', $type),
                );
            }
        }
    }

    #[Test]
    public function aWidgetOfAnInactiveModuleReturnsNoData(): void
    {
        $service = $this->service(false);

        foreach (ReportBuilderService::WIDGET_MODULES as $type => $module) {
            if ($module === null) {
                continue;
            }
            $data = $service->getWidgetData($type);
            self::assertSame(['error'], array_keys($data), $type . ' must return no data while its module is off');
        }
    }

    private function service(bool $modulesActive): ReportBuilderService
    {
        $args = [];
        foreach ((new \ReflectionClass(ReportBuilderService::class))->getConstructor()->getParameters() as $parameter) {
            $type = (string) $parameter->getType();
            if ($type === ModuleConfigurationService::class) {
                $modules = $this->createStub(ModuleConfigurationService::class);
                $modules->method('isModuleActive')->willReturn($modulesActive);
                $args[] = $modules;
                continue;
            }
            $args[] = $this->createStub($type);
        }

        return new ReportBuilderService(...$args);
    }
}
