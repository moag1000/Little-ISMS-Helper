<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\DocumentVoter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class DocumentVoterTest extends TestCase
{
    private DocumentVoter $voter;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->voter = new DocumentVoter(VoterTestHelper::createRoleHierarchy());
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

    #[Test]
    public function testAdminCannotViewOtherTenantDocument(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $otherUser = $this->createUser(['ROLE_USER'], $this->otherTenant);
        $document = $this->createDocument($otherUser);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testAdminCannotEditOtherTenantDocument(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $otherUser = $this->createUser(['ROLE_USER'], $this->otherTenant);
        $document = $this->createDocument($otherUser);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testAdminCannotDeleteOtherTenantDocument(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $otherUser = $this->createUser(['ROLE_USER'], $this->otherTenant);
        $document = $this->createDocument($otherUser);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testUserCanViewOwnDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($user);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testUserCanViewSameTenantDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $uploaderSameTenant = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($uploaderSameTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testUserCannotViewOtherTenantDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $uploaderOtherTenant = $this->createUser(['ROLE_USER'], $this->otherTenant);
        $document = $this->createDocument($uploaderOtherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testUserCanEditOwnDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($user);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testUserCannotEditOthersDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $uploaderSameTenant = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($uploaderSameTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testUserCannotDeleteDocument(): void
    {
        $user = $this->createUser(['ROLE_USER'], $this->tenant);
        $document = $this->createDocument($user);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $document, [DocumentVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testVoterAbstainsForNonDocumentSubject(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [DocumentVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function testSubsidiaryCanViewInheritableHoldingDocument(): void
    {
        // Phase 9.P2.1: holding-owned document marked inheritable=true
        // is visible read-only in every subsidiary.
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $holding->addSubsidiary($sub);

        $uploader = $this->createMock(User::class);
        $uploader->method('getTenant')->willReturn($holding);

        $document = $this->createMock(Document::class);
        $document->method('getUploadedBy')->willReturn($uploader);
        $document->method('getTenant')->willReturn($holding);
        $document->method('isInheritable')->willReturn(true);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $user->method('getTenant')->willReturn($sub);

        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $document, [DocumentVoter::VIEW]));
    }

    #[Test]
    public function testSubsidiaryCannotViewNonInheritableHoldingDocument(): void
    {
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $holding->addSubsidiary($sub);

        $uploader = $this->createMock(User::class);
        $uploader->method('getTenant')->willReturn($holding);

        $document = $this->createMock(Document::class);
        $document->method('getUploadedBy')->willReturn($uploader);
        $document->method('getTenant')->willReturn($holding);
        $document->method('isInheritable')->willReturn(false);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $user->method('getTenant')->willReturn($sub);

        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $document, [DocumentVoter::VIEW]));
    }

    #[Test]
    public function testSubsidiaryCannotEditInheritableHoldingDocument(): void
    {
        // Inheritance is read-only; edit must go through the holding
        // user, not the subsidiary. Guards against a subsidiary user
        // silently rewriting a mandated group policy.
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $holding->addSubsidiary($sub);

        $uploader = $this->createMock(User::class);
        $uploader->method('getTenant')->willReturn($holding);

        $document = $this->createMock(Document::class);
        $document->method('getUploadedBy')->willReturn($uploader);
        $document->method('getTenant')->willReturn($holding);
        $document->method('isInheritable')->willReturn(true);

        $subUser = $this->createMock(User::class);
        $subUser->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_MANAGER']);
        $subUser->method('getTenant')->willReturn($sub);

        $token = new UsernamePasswordToken($subUser, 'main', $subUser->getRoles());
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $document, [DocumentVoter::EDIT]));
    }

    #[Test]
    public function testInheritableHoldingDocumentMatchesTheHoldingAsAnotherInstance(): void
    {
        // The document's tenant is a second instance (Doctrine proxy /
        // detached copy) of the subsidiary user's parent: compared by id.
        $holding = (new Tenant())->setCode('holding');
        $holdingCopy = (new Tenant())->setCode('holding');
        foreach ([$holding, $holdingCopy] as $tenant) {
            (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 501);
        }
        $sub = (new Tenant())->setCode('sub');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($sub, 502);
        $holding->addSubsidiary($sub);

        $document = $this->createMock(Document::class);
        $document->method('getTenant')->willReturn($holdingCopy);
        $document->method('isInheritable')->willReturn(true);

        $user = $this->createUser(['ROLE_USER'], $sub);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->createToken($user), $document, [DocumentVoter::VIEW]));
        // Inheritance only flows down: the holding never "inherits" from itself.
        $holdingUser = $this->createUser(['ROLE_USER'], $holding);
        $subDocument = $this->createMock(Document::class);
        $subDocument->method('getTenant')->willReturn($sub);
        $subDocument->method('isInheritable')->willReturn(true);
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->createToken($holdingUser), $subDocument, [DocumentVoter::VIEW]));
    }
}
