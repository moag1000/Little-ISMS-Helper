<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WizardSession;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every compliance-wizard route must accept exactly the wizards that have their own
 * session slot (WizardSession::ALL_WIZARDS). A wizard missing from a route 404s on that
 * page; one missing from ALL_WIZARDS lands in the ISO 27001 history lane.
 */
final class ComplianceWizardRouteRequirementTest extends KernelTestCase
{
    #[Test]
    public function wizard_routes_accept_exactly_the_session_wizards(): void
    {
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $checked = 0;
        foreach ($routes as $name => $route) {
            if (!str_starts_with($name, 'app_compliance_wizard_') || $route->getRequirement('wizard') === null) {
                continue;
            }
            self::assertEqualsCanonicalizing(
                WizardSession::ALL_WIZARDS,
                explode('|', $route->getRequirement('wizard')),
                $name,
            );
            ++$checked;
        }
        self::assertGreaterThanOrEqual(10, $checked);
    }
}
