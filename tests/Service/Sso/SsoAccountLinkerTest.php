<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\IdentityProviderRepository;
use App\Repository\UserRepository;
use App\Security\Voter\UserVoter;
use App\Service\Sso\SsoAccountLinker;
use App\Service\Sso\SsoEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Manual SSO binding (#1059): an administrator links an existing account to a
 * provider + subject, with the same trust boundary as the automatic paths.
 */
#[AllowMockObjectsWithoutExpectations]
final class SsoAccountLinkerTest extends TestCase
{
    private IdentityProviderRepository&MockObject $providers;
    private UserRepository&MockObject $users;
    private EntityManagerInterface&MockObject $em;
    private SsoEventLogger&MockObject $events;
    /** @var array<string, bool> */
    private array $granted = [];
    private Tenant $tenant;
    private IdentityProvider $global;
    private IdentityProvider $organisationProvider;

    protected function setUp(): void
    {
        $this->tenant = $this->withId(new Tenant(), 7);
        $this->global = $this->withId((new IdentityProvider())->setSlug('global')->setName('Global')->setEnabled(true), 1);
        $this->organisationProvider = $this->withId(
            (new IdentityProvider())->setSlug('org')->setName('Org')->setEnabled(true)->setTenant($this->tenant),
            2,
        );

        $this->providers = $this->createMock(IdentityProviderRepository::class);
        $this->providers->method('findAllEnabledUnfiltered')->willReturn([$this->global, $this->organisationProvider]);
        $this->users = $this->createMock(UserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->events = $this->createMock(SsoEventLogger::class);
        $this->granted = [UserVoter::EDIT => true, 'ROLE_SUPER_ADMIN' => false];
    }

    #[Test]
    public function linksAnAccountAndRecordsItInTheProviderTrail(): void
    {
        $user = $this->user(['ROLE_USER']);
        $this->users->method('findOneBy')->willReturn(null);
        $this->em->expects(self::once())->method('flush');
        $this->events->expects(self::once())->method('logAccountLinked')
            ->with($this->organisationProvider, $user, null, null, 'abc-123');

        self::assertNull($this->linker()->link($user, 2, '  abc-123 '));
        self::assertSame($this->organisationProvider, $user->getSsoProvider());
        self::assertSame('abc-123', $user->getSsoExternalId());
        self::assertSame('sso:org', $user->getAuthProvider());
    }

    #[Test]
    public function aPrivilegedAccountNeedsAnInstanceOperatorAndAGlobalProvider(): void
    {
        $admin = $this->user(['ROLE_SUPER_ADMIN']);
        $this->users->method('findOneBy')->willReturn(null);

        self::assertSame('user.sso_link.error.not_allowed', $this->linker()->link($admin, 1, 'sub'));

        $this->granted['ROLE_SUPER_ADMIN'] = true;
        self::assertSame([$this->global], $this->linker()->linkableProviders($admin), 'an organisation provider never signs in a privileged account');
        self::assertSame('user.sso_link.error.provider_not_allowed', $this->linker()->link($admin, 2, 'sub'));
        self::assertNull($this->linker()->link($admin, 1, 'sub'));
        self::assertSame($this->global, $admin->getSsoProvider());
    }

    #[Test]
    public function refusesWithoutEditRightsAMissingSubjectAndASubjectOfAnotherAccount(): void
    {
        $user = $this->user(['ROLE_USER']);
        $other = $this->user(['ROLE_USER']);
        $this->users->method('findOneBy')->willReturn($other);
        $this->em->expects(self::never())->method('flush');

        self::assertSame('user.sso_link.error.subject_required', $this->linker()->link($user, 1, '   '));
        self::assertSame('user.sso_link.error.subject_too_long', $this->linker()->link($user, 1, str_repeat('x', 256)));
        self::assertSame('user.sso_link.error.provider_not_allowed', $this->linker()->link($user, 99, 'sub'));
        self::assertSame('user.sso_link.error.subject_taken', $this->linker()->link($user, 1, 'sub'));

        $this->granted[UserVoter::EDIT] = false;
        self::assertSame('user.sso_link.error.not_allowed', $this->linker()->link($user, 1, 'free'));
        self::assertNull($user->getSsoProvider());
    }

    #[Test]
    public function unlinkRemovesTheBindingAndFallsBackToTheLocalLogin(): void
    {
        $user = $this->user(['ROLE_USER']);
        $user->setSsoProvider($this->global)->setSsoExternalId('sub-1')->setAuthProvider('sso:global');
        $this->events->expects(self::once())->method('logAccountUnlinked')->with($this->global, $user, 'sub-1');

        self::assertNull($this->linker()->unlink($user));
        self::assertNull($user->getSsoProvider());
        self::assertNull($user->getSsoExternalId());
        self::assertSame('local', $user->getAuthProvider());
        self::assertSame('user.sso_link.error.not_linked', $this->linker()->unlink($user));
    }

    private function linker(): SsoAccountLinker
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(fn (string $attribute): bool => $this->granted[$attribute] ?? false);

        return new SsoAccountLinker($this->providers, $this->users, $this->em, $this->events, $auth);
    }

    /**
     * @param list<string> $roles
     */
    private function user(array $roles): User
    {
        $user = new User();
        $user->setEmail(uniqid('u', true) . '@example.test');
        $user->setRoles($roles);
        $user->setTenant($this->tenant);

        return $user;
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }
}
