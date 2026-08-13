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
    private function service(?Tenant $tenant, ?EntityManagerInterface $em = null): ModuleConfigurationService
    {
        $context = $this->createMock(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($tenant);

        return new ModuleConfigurationService(
            \dirname(__DIR__, 2),
            $context,
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    #[Test]
    public function tenantWithoutOwnSelectionInheritsTheInstanceDefault(): void
    {
        $service = $this->service(new Tenant());

        self::assertFalse($service->hasTenantOverride());
        self::assertSame(
            $service->getInstanceDefaultModules(),
            array_values(array_intersect($service->getActiveModules(), $service->getInstanceDefaultModules())),
            'a tenant without its own set must see the instance default',
        );
    }

    #[Test]
    public function tenantSelectionReplacesTheInstanceDefault(): void
    {
        $tenant = (new Tenant())->setActiveModules(['privacy']);
        $service = $this->service($tenant);

        self::assertTrue($service->hasTenantOverride());
        self::assertTrue($service->isModuleActive('privacy'));

        // Something the instance default carries, this tenant did not pick, and
        // that is not a required module (those stay active for everyone).
        $notPicked = array_values(array_diff(
            $service->getInstanceDefaultModules(),
            ['privacy'],
            array_keys($service->getRequiredModules()),
        ));
        if ($notPicked !== []) {
            self::assertFalse(
                $service->isModuleActive($notPicked[0]),
                'instance default must not leak into a tenant with its own selection',
            );
        }
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

        $yaml = \dirname(__DIR__, 2) . '/config/active_modules.yaml';
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
