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

    /**
     * S14: a login maintains only the roles the provider can hand out; top
     * management, persona and other roles granted in the user administration
     * survive every login.
     */
    #[Test]
    public function loginKeepsRolesTheProviderDoesNotManage(): void
    {
        $user = (new User())->setRoles(['ROLE_AUDITOR', 'ROLE_TOP_MGMT', 'ROLE_DPO']);
        $eventLogger = $this->createMock(SsoEventLogger::class);
        $eventLogger->expects(self::once())->method('logRoleChanged')
            ->with(self::anything(), $user, 'ROLE_AUDITOR', 'ROLE_MANAGER');

        $flow = $this->makeFlow(
            new ClaimToRoleResolverResult('ROLE_MANAGER', true, 'rule#1'),
            $eventLogger,
            managed: ['ROLE_USER', 'ROLE_AUDITOR', 'ROLE_MANAGER'],
        );
        $this->invokeApply($flow, $user, isNew: false);

        $stored = $user->getStoredRoles();
        sort($stored);
        self::assertSame(['ROLE_DPO', 'ROLE_MANAGER', 'ROLE_TOP_MGMT'], $stored);
    }

    #[Test]
    public function privilegedOrUnknownMappedRoleIsNeverAssigned(): void
    {
        foreach (['ROLE_SUPER_ADMIN', 'ROLE_GROUP_CISO', 'ROLE_GROUP_BCM_OFFICER', 'ROLE_SOMETHING_TYPED'] as $role) {
            $user = (new User())->setRoles(['ROLE_USER']);
            $flow = $this->makeFlow(new ClaimToRoleResolverResult($role, true, 'rule#1'), $this->createStub(SsoEventLogger::class), managed: [$role]);
            $this->invokeApply($flow, $user, isNew: false);
            self::assertSame(['ROLE_USER'], $user->getStoredRoles(), $role);
        }
    }

    /**
     * @param list<string> $managed
     */
    private function makeFlow(ClaimToRoleResolverResult $result, SsoEventLogger $eventLogger, array $managed = []): OidcAuthenticationFlow
    {
        $resolver = $this->createMock(ClaimToRoleResolver::class);
        $resolver->method('resolve')->willReturn($result);
        $resolver->method('managedRoles')->willReturn($managed);

        return new OidcAuthenticationFlow(
            $this->createMock(OidcDiscoveryService::class),
            new SsoSecretEncryption('test-kernel-secret'),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserRepository::class),
            $this->createMock(LoggerInterface::class),
            $resolver,
            $eventLogger,
            new \App\Http\UserUrlHttpClient(new \Symfony\Component\HttpClient\MockHttpClient()),
        );
    }

    private function invokeApply(OidcAuthenticationFlow $flow, User $user, bool $isNew): void
    {
        $method = new \ReflectionMethod(OidcAuthenticationFlow::class, 'applyRoleMapping');
        $method->invoke($flow, $user, new IdentityProvider(), ['groups' => ['admins']], $isNew);
    }
}
