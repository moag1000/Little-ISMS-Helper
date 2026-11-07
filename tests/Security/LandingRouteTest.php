<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\LandingRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LandingRouteTest extends KernelTestCase
{
    /** @return iterable<string, array{list<string>, string}> */
    public static function roles(): iterable
    {
        yield 'risk owner (plain user)' => [['ROLE_USER'], 'app_my_day'];
        yield 'function owner' => [['ROLE_FUNCTION_OWNER'], 'app_my_day'];
        yield 'auditor' => [['ROLE_AUDITOR'], 'app_my_day'];
        yield 'manager' => [['ROLE_MANAGER'], 'app_dashboard'];
        yield 'admin' => [['ROLE_ADMIN'], 'app_dashboard'];
        yield 'ciso persona' => [['ROLE_CISO'], 'app_dashboard_ciso'];
        yield 'isb persona' => [['ROLE_ISB'], 'app_dashboard_isb'];
        yield 'risk manager persona' => [['ROLE_RISK_MANAGER'], 'app_dashboard_risk_manager'];
        yield 'compliance manager persona' => [['ROLE_COMPLIANCE_MANAGER'], 'app_dashboard_compliance_manager'];
        yield 'top management' => [['ROLE_TOP_MGMT'], 'app_dashboard_board'];
        yield 'admin who is ISB lands as ISB' => [['ROLE_ADMIN', 'ROLE_ISB'], 'app_dashboard_isb'];
        yield 'konzern auditor' => [['ROLE_KONZERN_AUDITOR'], 'app_dashboard_auditor'];
    }

    /** @param list<string> $roles */
    #[Test]
    #[DataProvider('roles')]
    public function landsOnThePersonaDashboardOrMyDay(array $roles, string $expected): void
    {
        self::bootKernel();
        $user = new User();
        $user->setRoles($roles);

        self::assertSame($expected, static::getContainer()->get(LandingRoute::class)->routeFor($user));
    }

    #[Test]
    public function withoutUserTheDashboardHandlesTheLogin(): void
    {
        self::bootKernel();

        self::assertSame('app_dashboard', static::getContainer()->get(LandingRoute::class)->routeFor(null));
    }
}
