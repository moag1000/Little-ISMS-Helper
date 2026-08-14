<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Module activation is per tenant.
 *
 * It used to live only in config/active_modules.yaml — one set for the whole
 * instance — so a tenant switching a module on or off changed it for every
 * other tenant of the installation. A tenant without its own selection still
 * inherits that instance default, which keeps existing installations working.
 */
final class TenantScopedModuleActivationTest extends TestCase
{
    /**
     * config/active_modules.yaml is gitignored, so its contents differ per
     * machine. The tests build their own project dir with a known instance
     * default instead of reading whatever the checkout happens to carry.
     */
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/module_scope_' . uniqid();
        mkdir($this->projectDir . '/config', 0777, true);
        copy(
            \dirname(__DIR__, 2) . '/config/modules.yaml',
            $this->projectDir . '/config/modules.yaml',
        );
        file_put_contents(
            $this->projectDir . '/config/active_modules.yaml',
            "active_modules:\n    - privacy\n    - assets\n",
        );
    }

    protected function tearDown(): void
    {
        foreach (['/config/modules.yaml', '/config/active_modules.yaml'] as $file) {
            @unlink($this->projectDir . $file);
        }
        @rmdir($this->projectDir . '/config');
        @rmdir($this->projectDir);
    }

    private function service(?Tenant $tenant, ?EntityManagerInterface $em = null): ModuleConfigurationService
    {
        $context = $this->createMock(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($tenant);

        return new ModuleConfigurationService(
            $this->projectDir,
            $context,
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    #[Test]
    public function tenantWithoutOwnSelectionInheritsTheInstanceDefault(): void
    {
        $service = $this->service(new Tenant());

        self::assertFalse($service->hasTenantOverride());
        self::assertSame(['privacy', 'assets'], $service->getInstanceDefaultModules());
        foreach ($service->getInstanceDefaultModules() as $module) {
            self::assertTrue(
                $service->isModuleActive($module),
                sprintf('inheriting tenant must see instance module "%s"', $module),
            );
        }
    }

    #[Test]
    public function tenantSelectionReplacesTheInstanceDefault(): void
    {
        $tenant = (new Tenant())->setActiveModules(['privacy']);
        $service = $this->service($tenant);

        self::assertTrue($service->hasTenantOverride());
        self::assertTrue($service->isModuleActive('privacy'));
        // 'assets' is in the instance default but not in this tenant's selection.
        self::assertFalse(
            $service->isModuleActive('assets'),
            'instance default must not leak into a tenant with its own selection',
        );
    }

    #[Test]
    public function requiredModulesStayActiveEvenIfATenantOmitsThem(): void
    {
        $service = $this->service((new Tenant())->setActiveModules([]));

        foreach (array_keys($service->getRequiredModules()) as $required) {
            self::assertTrue(
                $service->isModuleActive((string) $required),
                sprintf('required module "%s" must stay active', (string) $required),
            );
        }
    }

    #[Test]
    public function savingWritesToTheTenantAndNotToTheSharedYaml(): void
    {
        $tenant = new Tenant();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $yaml = $this->projectDir . '/config/active_modules.yaml';
        $before = file_exists($yaml) ? file_get_contents($yaml) : null;

        $this->service($tenant, $em)->saveActiveModules(['privacy', 'assets']);

        self::assertSame(['privacy', 'assets'], $tenant->getActiveModules());
        self::assertSame(
            $before,
            file_exists($yaml) ? file_get_contents($yaml) : null,
            'the shared instance config must not be touched when a tenant saves',
        );
    }
}
