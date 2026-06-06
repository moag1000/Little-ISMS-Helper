<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Sso\ClaimToRoleResolver;
use App\Service\Sso\ClaimToRoleResolverResult;
use App\Service\Sso\OidcAuthenticationFlow;
use App\Service\Sso\OidcDiscoveryService;
use App\Service\Sso\SsoEventLogger;
use App\Service\Sso\SsoSecretEncryption;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Role re-sync on SSO login (closing the "role drift via re-login" gap).
 *
 * Only an explicitly MATCHED claim→role mapping may change the role; a changed
 * role on an EXISTING user must emit sso.role.changed; a new user must not.
 */
#[AllowMockObjectsWithoutExpectations]
final class OidcAuthenticationFlowRoleSyncTest extends TestCase
{
    #[Test]
    public function matchedMappingChangesExistingUserRoleAndLogs(): void
    {
        $user = (new User())->setRoles(['ROLE_USER']);
        $eventLogger = $this->createMock(SsoEventLogger::class);
        $eventLogger->expects(self::once())
            ->method('logRoleChanged')
            ->with(self::anything(), $user, 'ROLE_USER', 'ROLE_MANAGER');

        $flow = $this->makeFlow(new ClaimToRoleResolverResult('ROLE_MANAGER', true, 'rule#1'), $eventLogger);
        $this->invokeApply($flow, $user, isNew: false);

        self::assertSame(['ROLE_MANAGER'], $user->getStoredRoles());
    }

    #[Test]
    public function newUserRoleChangeIsNotLoggedAsDrift(): void
    {
        $user = (new User())->setRoles(['ROLE_USER']);
        $eventLogger = $this->createMock(SsoEventLogger::class);
        $eventLogger->expects(self::never())->method('logRoleChanged');

        $flow = $this->makeFlow(new ClaimToRoleResolverResult('ROLE_MANAGER', true, 'rule#1'), $eventLogger);
        $this->invokeApply($flow, $user, isNew: true);

        self::assertSame(['ROLE_MANAGER'], $user->getStoredRoles());
    }

    #[Test]
    public function unmatchedMappingLeavesRoleUntouched(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN']);
        $eventLogger = $this->createMock(SsoEventLogger::class);
        $eventLogger->expects(self::never())->method('logRoleChanged');

        $flow = $this->makeFlow(new ClaimToRoleResolverResult('ROLE_USER', false, 'no-match'), $eventLogger);
        $this->invokeApply($flow, $user, isNew: false);

        self::assertSame(['ROLE_ADMIN'], $user->getStoredRoles());
    }

    #[Test]
    public function sameRoleIsANoOp(): void
    {
        $user = (new User())->setRoles(['ROLE_MANAGER']);
        $eventLogger = $this->createMock(SsoEventLogger::class);
        $eventLogger->expects(self::never())->method('logRoleChanged');

        $flow = $this->makeFlow(new ClaimToRoleResolverResult('ROLE_MANAGER', true, 'rule#1'), $eventLogger);
        $this->invokeApply($flow, $user, isNew: false);

        self::assertSame(['ROLE_MANAGER'], $user->getStoredRoles());
    }

    private function makeFlow(ClaimToRoleResolverResult $result, SsoEventLogger $eventLogger): OidcAuthenticationFlow
    {
        $resolver = $this->createMock(ClaimToRoleResolver::class);
        $resolver->method('resolve')->willReturn($result);

        return new OidcAuthenticationFlow(
            $this->createMock(OidcDiscoveryService::class),
            new SsoSecretEncryption('test-kernel-secret'),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserRepository::class),
            $this->createMock(LoggerInterface::class),
            $resolver,
            $eventLogger,
        );
    }

    private function invokeApply(OidcAuthenticationFlow $flow, User $user, bool $isNew): void
    {
        $method = new \ReflectionMethod(OidcAuthenticationFlow::class, 'applyRoleMapping');
        $method->invoke($flow, $user, new IdentityProvider(), ['groups' => ['admins']], $isNew);
    }
}
