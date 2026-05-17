<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Document;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Security\Voter\LifecycleVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class LifecycleVoterTest extends TestCase
{
    public function testGrantsWhenUserHasRequiredRole(): void
    {
        $voter = $this->makeVoter(yamlRoles: ['ROLE_MANAGER'], userRoles: ['ROLE_MANAGER']);
        $result = $voter->vote($this->mockToken(), new Document(), ['lifecycle.document_lifecycle.approve']);
        $this->assertSame(Voter::ACCESS_GRANTED, $result);
    }

    public function testDeniesWhenUserMissingRole(): void
    {
        $voter = $this->makeVoter(yamlRoles: ['ROLE_MANAGER'], userRoles: ['ROLE_USER']);
        $result = $voter->vote($this->mockToken(), new Document(), ['lifecycle.document_lifecycle.approve']);
        $this->assertSame(Voter::ACCESS_DENIED, $result);
    }

    public function testEmptyRolesListDeniesAll(): void
    {
        $voter = $this->makeVoter(yamlRoles: [], userRoles: ['ROLE_ADMIN']);
        $result = $voter->vote($this->mockToken(), new Document(), ['lifecycle.document_lifecycle.approve']);
        $this->assertSame(Voter::ACCESS_DENIED, $result);
    }

    public function testAbstainsOnNonLifecycleAttribute(): void
    {
        $voter = $this->makeVoter(yamlRoles: ['ROLE_MANAGER'], userRoles: ['ROLE_MANAGER']);
        $result = $voter->vote($this->mockToken(), new Document(), ['ROLE_ADMIN']);
        $this->assertSame(Voter::ACCESS_ABSTAIN, $result);
    }

    private function makeVoter(array $yamlRoles, array $userRoles): LifecycleVoter
    {
        $resolver = $this->createStub(LifecycleConfigResolverInterface::class);
        $resolver->method('resolve')->willReturn(['roles' => $yamlRoles]);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(fn (string $r) => in_array($r, $userRoles, true));

        return new LifecycleVoter($security, $resolver);
    }

    private function mockToken(): TokenInterface
    {
        return $this->createStub(TokenInterface::class);
    }
}
