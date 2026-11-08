<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\GlobalCatalogueWriteSubscriber;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The route list of the global-catalogue guard must not rot: a renamed route would
 * silently drop out of it (the Doctrine backstop would still refuse the flush, but only
 * after the user filled the form).
 */
final class GlobalCatalogueWriteSubscriberTest extends KernelTestCase
{
    #[Test]
    public function everyGuardedRouteExists(): void
    {
        self::bootKernel();
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();

        foreach (GlobalCatalogueWriteSubscriber::guardedRoutes() as $name) {
            self::assertNotNull($routes->get($name), sprintf('Route "%s" is guarded but does not exist.', $name));
        }
    }
}
