<?php

declare(strict_types=1);

namespace App\Tests\Controller\Authority;

use App\Service\Authority\AuthorityHubService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Smoke tests for AuthorityHubController — verifies service wiring in DI container.
 */
#[AllowMockObjectsWithoutExpectations]
final class AuthorityHubControllerTest extends KernelTestCase
{
    #[Test]
    public function authorityHubServiceIsWiredInContainer(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertTrue(
            $container->has(AuthorityHubService::class),
            'AuthorityHubService must be registered as a service'
        );
    }

    #[Test]
    public function moduleConfigurationServiceIsWiredInContainer(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertTrue(
            $container->has(ModuleConfigurationService::class),
            'ModuleConfigurationService must be registered as a service'
        );
    }

    #[Test]
    public function tenantContextIsWiredInContainer(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertTrue(
            $container->has(TenantContext::class),
            'TenantContext must be registered as a service'
        );
    }
}
