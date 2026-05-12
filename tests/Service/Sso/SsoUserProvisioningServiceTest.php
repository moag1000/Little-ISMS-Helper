<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Sso\ClaimToRoleResolver;
use App\Service\Sso\ClaimToRoleResolverResult;
use App\Service\Sso\SsoEventLogger;
use App\Service\Sso\SsoUserProvisioningService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Unit tests for SsoUserProvisioningService.
 *
 * No database — all repositories are mocked.
 */
#[AllowMockObjectsWithoutExpectations]
final class SsoUserProvisioningServiceTest extends TestCase
{
    private IdentityProvider $provider;
    private UserRepository $userRepo;
    private EntityManagerInterface $em;
    private AuditLogger $audit;
    private ClaimToRoleResolver $resolver;
    private SsoEventLogger $ssoLogger;

    protected function setUp(): void
    {
        $this->provider = (new IdentityProvider())
            ->setSlug('test-idp')
            ->setClientId('client123')
            ->setName('Test IdP')
            ->setJitProvisioning(true)
            ->setAutoApprove(true)
            ->setDefaultFallbackRole('ROLE_USER')
            ->setAttributeMap(['email' => 'email', 'given_name' => 'firstName', 'family_name' => 'lastName']);

        $this->userRepo  = $this->createMock(UserRepository::class);
        $this->em        = $this->createMock(EntityManagerInterface::class);
        $this->audit     = $this->createMock(AuditLogger::class);
        // Default resolver: always return fallback role so existing tests keep passing
        $this->resolver  = $this->createMock(ClaimToRoleResolver::class);
        $this->resolver->method('resolve')->willReturnCallback(
            fn (IdentityProvider $p, array $c) => new ClaimToRoleResolverResult(
                role: $p->getDefaultFallbackRole() ?: 'ROLE_USER',
                matched: false,
                trace: 'fallback',
            )
        );
        $this->ssoLogger = $this->createMock(SsoEventLogger::class);
    }

    private function makeService(): SsoUserProvisioningService
    {
        return new SsoUserProvisioningService(
            $this->userRepo,
            $this->em,
            $this->audit,
            new NullLogger(),
            $this->resolver,
            $this->ssoLogger,
        );
    }

    #[Test]
    public function claimToRoleResolverIsConsultedForNewUser(): void
    {
        $resolver = $this->createMock(ClaimToRoleResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->willReturn(new ClaimToRoleResolverResult(role: 'ROLE_MANAGER', matched: true, trace: 'matched'));

        $ssoLogger = $this->createMock(SsoEventLogger::class);
        $ssoLogger->expects(self::once())->method('logJitProvisioned');

        $svc = new SsoUserProvisioningService(
            $this->userRepo,
            $this->em,
            $this->audit,
            new NullLogger(),
            $resolver,
            $ssoLogger,
        );

        $this->userRepo->method('findOneBy')->willReturn(null);

        $claims = ['sub' => 'u123', 'email' => 'alice@example.com', 'given_name' => 'Alice', 'family_name' => 'Smith'];
        $user   = $svc->provision($this->provider, $claims);

        self::assertContains('ROLE_MANAGER', $user->getRoles());
    }

    #[Test]
    public function returnsExistingUserLinkedByProviderAndSub(): void
    {
        $existing = new User();
        $existing->setEmail('alice@acme.com');

        $this->userRepo->method('findOneBy')
            ->with(['ssoProvider' => $this->provider, 'ssoExternalId' => 'sub123'])
            ->willReturn($existing);

        $claims = ['sub' => 'sub123', 'email' => 'alice@acme.com', 'given_name' => 'Alice', 'family_name' => 'Smith'];
        $this->em->expects(self::once())->method('flush');

        $result = $this->makeService()->provision($this->provider, $claims);
        self::assertSame($existing, $result);
    }

    #[Test]
    public function linksExistingUserByEmailWhenNoSubMatch(): void
    {
        $existing = new User();
        $existing->setEmail('alice@acme.com');

        $this->userRepo->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($existing) {
                if (isset($criteria['email'])) {
                    return $existing;
                }
                return null;
            });

        $claims = ['sub' => 'sub-new', 'email' => 'alice@acme.com'];
        $this->em->expects(self::once())->method('flush');

        $result = $this->makeService()->provision($this->provider, $claims);
        self::assertSame($existing, $result);
        self::assertSame('sub-new', $result->getSsoExternalId());
    }

    #[Test]
    public function createsNewUserWhenJitEnabled(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $claims = ['sub' => 'brand-new-sub', 'email' => 'newuser@acme.com', 'given_name' => 'New', 'family_name' => 'User'];
        $result = $this->makeService()->provision($this->provider, $claims);

        self::assertInstanceOf(User::class, $result);
        self::assertSame('newuser@acme.com', $result->getEmail());
        self::assertContains('ROLE_USER', $result->getRoles());
    }

    #[Test]
    public function throwsWhenEmailClaimMissing(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->makeService()->provision($this->provider, ['sub' => 'sub123']);
    }

    #[Test]
    public function throwsWhenSubClaimMissing(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->makeService()->provision($this->provider, ['email' => 'alice@acme.com']);
    }

    #[Test]
    public function throwsWhenJitProvisioningDisabledAndNoExistingUser(): void
    {
        $this->provider->setJitProvisioning(false);
        $this->userRepo->method('findOneBy')->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->makeService()->provision($this->provider, ['sub' => 'sub123', 'email' => 'alice@acme.com']);
    }

    #[Test]
    public function usesFallbackRoleFromProvider(): void
    {
        $this->provider->setDefaultFallbackRole('ROLE_AUDITOR');
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $claims = ['sub' => 'sub123', 'email' => 'auditor@acme.com'];
        $user = $this->makeService()->provision($this->provider, $claims);

        self::assertContains('ROLE_AUDITOR', $user->getRoles());
    }
}
