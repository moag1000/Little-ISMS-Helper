<?php

declare(strict_types=1);

namespace App\Tests\Controller\Authority;

use App\Controller\Authority\AuthorityHubController;
use App\Service\Authority\AuthorityHubService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    /**
     * Regression guard for E2E round-2 BLOCKER: AuthorityHubController was missing
     * TranslatorInterface in its constructor. When module 'eu_authority_reporting'
     * is inactive, checkModuleActive() calls $this->translator->trans() — which
     * would throw "Typed property ... $translator must not be accessed before initialization".
     *
     * This test verifies the controller can be instantiated from the DI container
     * (i.e. all dependencies including TranslatorInterface are wired correctly).
     */
    #[Test]
    public function controllerIsInstantiableWithAllDependenciesIncludingTranslator(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // The controller must be retrievable from the test container without throwing
        $controller = $container->get(AuthorityHubController::class);

        self::assertInstanceOf(AuthorityHubController::class, $controller);
    }

    #[Test]
    public function translatorInterfaceIsWiredInContainer(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertTrue(
            $container->has(TranslatorInterface::class),
            'TranslatorInterface must be registered as a service'
        );
    }
}
