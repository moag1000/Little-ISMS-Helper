<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Risk;
use App\Entity\User;
use App\Security\Voter\RiskAcceptanceVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Junior-ISB-Audit-2026-05-22 #582-followup: RiskAcceptanceVoter hierarchy-aware.
 *
 * Verifies the score-tier ladder honours Symfony's role_hierarchy, so an admin
 * with only `ROLE_ADMIN` in the DB still satisfies ROLE_MANAGER / ROLE_USER
 * checks via {@see VoterTestHelper::createRoleHierarchy()}.
 */
#[AllowMockObjectsWithoutExpectations]
class RiskAcceptanceVoterTest extends TestCase
{
    private RiskAcceptanceVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new RiskAcceptanceVoter(VoterTestHelper::createRoleHierarchy());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createUser(array $roles): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        return $user;
    }

    private function createRisk(int $score): Risk
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getRiskScore')->willReturn($score);
        return $risk;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    // ------------------------------------------------------------------
    // Score → required role mapping
    // ------------------------------------------------------------------

    #[Test]
    public function testLowScoreGrantedForRoleUser(): void
    {
        $user  = $this->createUser(['ROLE_USER']);
        $risk  = $this->createRisk(5); // 1-6 tier → ROLE_USER
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testMediumScoreDeniedForRoleUser(): void
    {
        $user  = $this->createUser(['ROLE_USER']);
        $risk  = $this->createRisk(10); // 7-12 tier → ROLE_MANAGER
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testMediumScoreGrantedForRoleManager(): void
    {
        $user  = $this->createUser(['ROLE_MANAGER']);
        $risk  = $this->createRisk(10);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testHighScoreDeniedForRoleManager(): void
    {
        $user  = $this->createUser(['ROLE_MANAGER']);
        $risk  = $this->createRisk(15); // 13-19 tier → ROLE_ADMIN
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testHighScoreGrantedForRoleAdmin(): void
    {
        $user  = $this->createUser(['ROLE_ADMIN']);
        $risk  = $this->createRisk(15);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testCriticalScoreDeniedForRoleAdmin(): void
    {
        $user  = $this->createUser(['ROLE_ADMIN']);
        $risk  = $this->createRisk(22); // 20-25 tier → ROLE_SUPER_ADMIN
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testCriticalScoreGrantedForRoleSuperAdmin(): void
    {
        $user  = $this->createUser(['ROLE_SUPER_ADMIN']);
        $risk  = $this->createRisk(22);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ------------------------------------------------------------------
    // role_hierarchy expansion — the bug fixed by this voter rewrite
    // ------------------------------------------------------------------

    /**
     * Admin user with ONLY `ROLE_ADMIN` in the DB row must satisfy
     * a ROLE_MANAGER-tier check via role_hierarchy expansion.
     */
    #[Test]
    public function testRoleAdminSatisfiesManagerTierViaHierarchy(): void
    {
        $user  = $this->createUser(['ROLE_ADMIN']);
        $risk  = $this->createRisk(10); // requires ROLE_MANAGER
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /**
     * Admin user with ONLY `ROLE_ADMIN` must satisfy a ROLE_USER-tier
     * check (lowest privilege).
     */
    #[Test]
    public function testRoleAdminSatisfiesUserTierViaHierarchy(): void
    {
        $user  = $this->createUser(['ROLE_ADMIN']);
        $risk  = $this->createRisk(3); // requires ROLE_USER
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /**
     * ROLE_SUPER_ADMIN reaches every other role — top of the ladder.
     */
    #[Test]
    public function testRoleSuperAdminSatisfiesAllTiers(): void
    {
        $user = $this->createUser(['ROLE_SUPER_ADMIN']);

        foreach ([3, 10, 15, 22] as $score) {
            $risk   = $this->createRisk($score);
            $token  = $this->createToken($user);
            $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);
            $this->assertSame(VoterInterface::ACCESS_GRANTED, $result, "Expected GRANTED for score $score");
        }
    }

    // ------------------------------------------------------------------
    // Subject + attribute filtering
    // ------------------------------------------------------------------

    #[Test]
    public function testAbstainsOnUnsupportedAttribute(): void
    {
        $user  = $this->createUser(['ROLE_SUPER_ADMIN']);
        $risk  = $this->createRisk(10);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $risk, ['some_other_attribute']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function testAbstainsOnNonRiskSubject(): void
    {
        $user  = $this->createUser(['ROLE_SUPER_ADMIN']);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, new \stdClass(), [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function testDeniesWhenTokenHasNoUser(): void
    {
        $risk  = $this->createRisk(3);
        $token = new UsernamePasswordToken(
            new \Symfony\Component\Security\Core\User\InMemoryUser('anon', null, []),
            'main',
            [],
        );

        $result = $this->voter->vote($token, $risk, [RiskAcceptanceVoter::APPROVE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}
