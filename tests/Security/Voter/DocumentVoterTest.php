<?php

namespace App\Tests\Security\Voter;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\DocumentVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class DocumentVoterTest extends TestCase
{
    private DocumentVoter $voter;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->voter = new DocumentVoter();
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

    private function createDocument(?User $uploadedBy = null): Document
    {
        $document = $this->createMock(Document::class);
        $document->method('getUploadedBy')->willReturn($uploadedBy);
        return $document;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testAdminCanViewAnyDocument(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $otherUser = $this->createUser(['ROLE_USER'], $this->otherTenant);
        $document = $this->createDocument($otherUser);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyDocument(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $otherUser = $this->createUser(['ROLE_USER'], $this->otherTenant);
        $document = $this->createDocument($otherUser);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteAnyDocument(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $otherUser = $this->createUser(['ROLE_USER'], $this->otherTenant);
        $document = $this->createDocument($otherUser);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCanViewOwnDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($user);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCanViewSameTenantDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $uploaderSameTenant = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($uploaderSameTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCannotViewOtherTenantDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $uploaderOtherTenant = $this->createUser(['ROLE_USER'], $this->otherTenant);
        $document = $this->createDocument($uploaderOtherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserCanEditOwnDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($user);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testUserCannotEditOthersDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $uploaderSameTenant = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($uploaderSameTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUserCannotDeleteDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($user);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoterAbstainsForNonDocumentSubject(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
