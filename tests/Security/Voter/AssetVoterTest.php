<?php

namespace App\Tests\Security\Voter;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\AssetVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class AssetVoterTest extends TestCase
{
    private AssetVoter $voter;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->voter = new AssetVoter();
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

    private function createAsset(?Tenant $tenant = null): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $asset;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testAdminCanViewAnyAsset(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $asset = $this->createAsset($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $asset, [AssetVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyAsset(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $asset = $this->createAsset($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $asset, [AssetVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteAnyAsset(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $asset = $this->createAsset($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $asset, [AssetVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCanViewOwnTenantAsset(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $asset = $this->createAsset($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $asset, [AssetVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCannotViewOtherTenantAsset(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $asset = $this->createAsset($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $asset, [AssetVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserCanEditOwnTenantAsset(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $asset = $this->createAsset($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $asset, [AssetVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCannotEditOtherTenantAsset(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $asset = $this->createAsset($this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $asset, [AssetVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserCannotDeleteAsset(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $asset = $this->createAsset($this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $asset, [AssetVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoterAbstainsForNonAssetSubject(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [AssetVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoterAbstainsForUnsupportedAttribute(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $asset = $this->createAsset();
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $asset, ['UNSUPPORTED']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
