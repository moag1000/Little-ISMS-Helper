<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Voter\SwitchUserVoter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Impersonation must not lead upwards: a crafted organisation restore could grant
 * ROLE_ALLOWED_TO_SWITCH, and Symfony's default lets that role become anyone,
 * the super admin included.
 */
final class SwitchUserVoterTest extends TestCase
{
    private SwitchUserVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new SwitchUserVoter(VoterTestHelper::createRoleHierarchy());
    }

    #[Test]
    public function superAdminImpersonatesOrdinaryUsersAndLeitung(): void
    {
        $caller = $this->user('root@example.test', ['ROLE_SUPER_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($caller, $this->user('a@example.test', ['ROLE_ADMIN'])));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($caller, $this->user('b@example.test', ['ROLE_TOP_MGMT'])));
    }

    #[Test]
    public function nobodyBecomesASuperAdmin(): void
    {
        $target = $this->user('root2@example.test', ['ROLE_SUPER_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->user('root@example.test', ['ROLE_SUPER_ADMIN']), $target));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->user('x@example.test', ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH']), $target));
    }

    #[Test]
    public function aSwitcherBelowSuperAdminOnlyGoesDownwards(): void
    {
        $caller = $this->user('x@example.test', ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($caller, $this->user('m@example.test', ['ROLE_MANAGER'])));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($caller, $this->user('g@example.test', ['ROLE_GROUP_CISO'])));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($caller, $this->user('t@example.test', ['ROLE_TOP_MGMT'])));
    }

    #[Test]
    public function withoutTheSwitchRoleNothingIsGranted(): void
    {
        $caller = $this->user('a@example.test', ['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($caller, $this->user('u@example.test', ['ROLE_USER'])));
    }

    /** @param string[] $roles */
    private function user(string $email, array $roles): User
    {
        return (new User())->setEmail($email)->setRoles($roles);
    }

    private function vote(User $caller, User $target): int
    {
        $token = new UsernamePasswordToken($caller, 'main', $caller->getRoles());

        return $this->voter->vote($token, $target, [SwitchUserVoter::SWITCH]);
    }
}
