<?php

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Voter\EntityVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class EntityVoterTest extends TestCase
{
    private EntityVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new EntityVoter();
    }

    private function createUser(array $roles = ['ROLE_USER'], bool $isActive = true, array $permissions = []): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('isActive')->willReturn($isActive);
        $user->method('hasPermission')->willReturnCallback(function ($perm) use ($permissions) {
            return in_array($perm, $permissions);
        });
        return $user;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testAdminCanViewAnyEntity(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [EntityVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyEntity(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [EntityVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testInactiveUserCannotPerformActions(): void
    {
        $user = $this->createUser(['ROLE_USER'], false);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [EntityVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserWithPermissionCanView(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, ['stdclass.view']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [EntityVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserWithoutPermissionCannotView(): void
    {
        $user = $this->createUser(['ROLE_USER'], true, []);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [EntityVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoterAbstainsForUnsupportedAttribute(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), ['UNSUPPORTED']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoterAbstainsForNonObjectSubject(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'string', [EntityVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
