<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Document;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Security\Voter\LifecycleVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use PHPUnit\Framework\Attributes\Test;

class LifecycleVoterTest extends TestCase
{
    #[Test]
    public function testGrantsWhenUserHasRequiredRole(): void
    {
        $voter = $this->makeVoter(yamlRoles: ['ROLE_MANAGER'], userRoles: ['ROLE_MANAGER']);
        $result = $voter->vote($this->mockToken(), new Document(), ['lifecycle.document_lifecycle.approve']);
        $this->assertSame(Voter::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testDeniesWhenUserMissingRole(): void
    {
        $voter = $this->makeVoter(yamlRoles: ['ROLE_MANAGER'], userRoles: ['ROLE_USER']);
        $result = $voter->vote($this->mockToken(), new Document(), ['lifecycle.document_lifecycle.approve']);
        $this->assertSame(Voter::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testEmptyRolesListDeniesAll(): void
    {
        $voter = $this->makeVoter(yamlRoles: [], userRoles: ['ROLE_ADMIN']);
        $result = $voter->vote($this->mockToken(), new Document(), ['lifecycle.document_lifecycle.approve']);
        $this->assertSame(Voter::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testAbstainsOnNonLifecycleAttribute(): void
    {
        $voter = $this->makeVoter(yamlRoles: ['ROLE_MANAGER'], userRoles: ['ROLE_MANAGER']);
        $result = $voter->vote($this->mockToken(), new Document(), ['ROLE_ADMIN']);
        $this->assertSame(Voter::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function responsiblePersonMayFireATransitionMarkedResponsible(): void
    {
        // S15: isms_objective achieve — manager OR the objective's responsible person.
        $voter = $this->makeVoter(yamlRoles: ['ROLE_MANAGER'], userRoles: ['ROLE_USER', 'RECORD_RESPONSIBLE'], responsible: true);
        $this->assertSame(Voter::ACCESS_GRANTED, $voter->vote($this->mockToken(), new Document(), ['lifecycle.document_lifecycle.approve']));

        $notResponsible = $this->makeVoter(yamlRoles: ['ROLE_MANAGER'], userRoles: ['ROLE_USER'], responsible: true);
        $this->assertSame(Voter::ACCESS_DENIED, $notResponsible->vote($this->mockToken(), new Document(), ['lifecycle.document_lifecycle.approve']));

        $unmarked = $this->makeVoter(yamlRoles: ['ROLE_MANAGER'], userRoles: ['ROLE_USER', 'RECORD_RESPONSIBLE']);
        $this->assertSame(Voter::ACCESS_DENIED, $unmarked->vote($this->mockToken(), new Document(), ['lifecycle.document_lifecycle.approve']));
    }

    private function makeVoter(array $yamlRoles, array $userRoles, bool $responsible = false): LifecycleVoter
    {
        $resolver = $this->createStub(LifecycleConfigResolverInterface::class);
        $resolver->method('resolve')->willReturn(['roles' => $yamlRoles] + ($responsible ? ['responsible' => true] : []));

        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturnCallback(
            fn (TokenInterface $token, array $attributes) => in_array($attributes[0], $userRoles, true),
        );

        return new LifecycleVoter($decisions, $resolver);
    }

    private function mockToken(): TokenInterface
    {
        return $this->createStub(TokenInterface::class);
    }
}
