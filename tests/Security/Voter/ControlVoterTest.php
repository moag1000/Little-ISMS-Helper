<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\ControlVoter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class ControlVoterTest extends TestCase
{
    private ControlVoter $voter;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->voter = new ControlVoter(VoterTestHelper::createRoleHierarchy());
        $this->tenant = $this->createMock(Tenant::class);
        $this->otherTenant = $this->createMock(Tenant::class);
    }

    private function createUser(array $roles = ['ROLE_USER'], ?Tenant $tenant = null): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $user;
    }

    private function createControl(?Tenant $tenant = null): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $control;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    #[Test]
    public function testAdminCannotViewOtherTenantControl(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $control = $this->createControl($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testAdminCannotEditOtherTenantControl(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $control = $this->createControl($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testAdminCannotDeleteOtherTenantControl(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $control = $this->createControl($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testUserCanViewOwnTenantControl(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $control = $this->createControl($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testUserCannotViewOtherTenantControl(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $control = $this->createControl($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testUserCannotDeleteControl(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $control = $this->createControl($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $control, [ControlVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function editByRoleProvider(): iterable
    {
        yield 'user is read-only' => [['ROLE_USER'], VoterInterface::ACCESS_DENIED];
        yield 'auditor is read-only' => [['ROLE_AUDITOR'], VoterInterface::ACCESS_DENIED];
        yield 'holding auditor is read-only' => [['ROLE_KONZERN_AUDITOR'], VoterInterface::ACCESS_DENIED];
        yield 'group CISO is read-only' => [['ROLE_GROUP_CISO'], VoterInterface::ACCESS_DENIED];
        yield 'manager may edit' => [['ROLE_MANAGER'], VoterInterface::ACCESS_GRANTED];
        yield 'persona role inherits manager' => [['ROLE_DPO'], VoterInterface::ACCESS_GRANTED];
        yield 'admin may edit' => [['ROLE_ADMIN'], VoterInterface::ACCESS_GRANTED];
    }

    /**
     * @param list<string> $roles
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('editByRoleProvider')]
    public function editOwnTenantControlNeedsManagerScope(array $roles, int $expected): void
    {
        $user = $this->createUser($roles, $this->tenant);
        $control = $this->createControl($this->tenant);

        self::assertSame($expected, $this->voter->vote($this->createToken($user), $control, [ControlVoter::EDIT]));
    }

    #[Test]
    public function auditorStillViewsOwnTenantControl(): void
    {
        $user = $this->createUser(['ROLE_AUDITOR'], $this->tenant);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($user), $this->createControl($this->tenant), [ControlVoter::VIEW]),
        );
    }

    #[Test]
    public function controlEditPermissionGrantsEditBelowManager(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $user->method('getTenant')->willReturn($this->tenant);
        $user->method('hasPermission')->willReturnCallback(static fn(string $p): bool => $p === 'control.edit');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($user), $this->createControl($this->tenant), [ControlVoter::EDIT]),
        );
    }

    #[Test]
    public function testVoterAbstainsForNonControlSubject(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [ControlVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
