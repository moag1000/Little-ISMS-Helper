<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\UserVoter;
use App\Service\User\UserListScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Review M-8: the export gate is derived from one visibleUsers() result —
 * the voter runs once per user, not twice.
 */
final class UserListScopeTest extends TestCase
{
    #[Test]
    public function exportScopeEvaluatesTheVoterOncePerUser(): void
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 7);
        $users = [(new User())->setTenant($tenant), (new User())->setTenant($tenant)];

        $repo = $this->createStub(UserRepository::class);
        $repo->method('findAllWithRoles')->willReturn($users);
        $viewChecks = 0;
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static function (string $attribute) use (&$viewChecks): bool {
                if ($attribute === UserVoter::VIEW) {
                    ++$viewChecks;

                    return true;
                }

                return false;
            },
        );

        [$visible, $args] = (new UserListScope($security, $repo))->exportScopeForCurrentUser();

        self::assertCount(2, $visible);
        self::assertSame([UserListScope::ARG_TENANT_IDS => [7]], $args);
        self::assertSame(2, $viewChecks);
    }

    #[Test]
    public function exportScopeIsDeniedWithoutAnyOrganisation(): void
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findAllWithRoles')->willReturn([new User()]);
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $a): bool => $a === UserVoter::VIEW);

        $this->expectException(AccessDeniedException::class);
        (new UserListScope($security, $repo))->exportScopeForCurrentUser();
    }
}
