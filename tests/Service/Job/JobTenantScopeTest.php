<?php

declare(strict_types=1);

namespace App\Tests\Service\Job;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Service\Job\JobTenantScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class JobTenantScopeTest extends TestCase
{
    #[Test]
    public function userWithTenantGetsTenantScope(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn(42);
        $user = $this->createStub(User::class);
        $user->method('getTenant')->willReturn($tenant);

        self::assertSame(
            [JobTenantScope::ARG_TENANT_ID => 42],
            $this->scope($user, superAdmin: true)->argsForCurrentUser(),
            'even a SUPER_ADMIN with an organisation exports that organisation only',
        );
    }

    #[Test]
    public function superAdminWithoutTenantGetsExplicitInstanceWideScope(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getTenant')->willReturn(null);

        self::assertSame(
            [JobTenantScope::ARG_ALL_TENANTS => true],
            $this->scope($user, superAdmin: true)->argsForCurrentUser(),
        );
    }

    #[Test]
    public function nonSuperAdminWithoutTenantIsDenied(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getTenant')->willReturn(null);

        $this->expectException(AccessDeniedException::class);
        $this->scope($user, superAdmin: false)->argsForCurrentUser();
    }

    private function scope(User $user, bool $superAdmin): JobTenantScope
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(
            static fn(string $attribute): bool => $attribute === 'ROLE_SUPER_ADMIN' && $superAdmin,
        );

        return new JobTenantScope($security, $this->createStub(TenantRepository::class));
    }
}
