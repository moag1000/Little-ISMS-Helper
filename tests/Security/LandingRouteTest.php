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
        yield 'auditor' => [['ROLE_AUDITOR'], 'app_my_day'];
        yield 'manager' => [['ROLE_MANAGER'], 'app_dashboard'];
        yield 'admin' => [['ROLE_ADMIN'], 'app_dashboard'];
        yield 'dpo persona' => [['ROLE_USER', 'ROLE_DPO'], 'app_dashboard'];
        yield 'ciso persona' => [['ROLE_CISO'], 'app_dashboard'];
    }

    /** @param list<string> $roles */
    #[Test]
    #[DataProvider('roles')]
    public function landsOnMyDayUnlessTheRoleHasADashboard(array $roles, string $expected): void
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
