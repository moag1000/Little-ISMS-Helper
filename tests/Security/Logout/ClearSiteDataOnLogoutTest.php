<?php

declare(strict_types=1);

namespace App\Tests\Security\Logout;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Yaml\Yaml;

/**
 * S16: signing out tells the browser to drop its HTTP cache, the service-worker caches
 * and localStorage/IndexedDB. Before, pages and .json reports (an Art. 33 notification)
 * stayed in the service-worker cache after logout and opened for the next person on a
 * shared device.
 *
 * Every firewall that has a logout must send the header; the test runs the real
 * logout listeners of each such firewall.
 */
final class ClearSiteDataOnLogoutTest extends KernelTestCase
{
    #[Test]
    public function everyFirewallWithLogoutClearsCacheAndStorage(): void
    {
        $firewalls = $this->firewallsWithLogout();
        self::assertNotEmpty($firewalls, 'no firewall with logout found in security.yaml');

        foreach ($firewalls as $name => $logout) {
            $values = (array) ($logout['clear_site_data'] ?? []);
            self::assertContains('cache', $values, sprintf('firewall "%s": logout keeps the HTTP cache', $name));
            self::assertContains('storage', $values, sprintf('firewall "%s": logout keeps localStorage/IndexedDB/service-worker data', $name));
        }
    }

    #[Test]
    public function theLogoutResponseCarriesClearSiteData(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        foreach (array_keys($this->firewallsWithLogout()) as $name) {
            $dispatcher = $container->get('security.event_dispatcher.' . $name);
            $request = Request::create('/en/logout');
            $request->setSession(new Session(new MockArraySessionStorage()));
            // The remember-me listener clears its cookie on the main request.
            $requestStack = $container->get('request_stack');
            $requestStack->push($request);
            $event = new LogoutEvent($request, null);
            try {
                $dispatcher->dispatch($event);
            } finally {
                $requestStack->pop();
            }

            $response = $event->getResponse();
            self::assertNotNull($response, sprintf('firewall "%s": logout built no response', $name));
            $header = (string) $response->headers->get('Clear-Site-Data');
            self::assertStringContainsString('"cache"', $header, sprintf('firewall "%s"', $name));
            self::assertStringContainsString('"storage"', $header, sprintf('firewall "%s"', $name));
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function firewallsWithLogout(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/security.yaml');
        $result = [];
        foreach ($config['security']['firewalls'] ?? [] as $name => $firewall) {
            if (is_array($firewall) && isset($firewall['logout'])) {
                $result[$name] = is_array($firewall['logout']) ? $firewall['logout'] : [];
            }
        }

        return $result;
    }
}
