<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\RiskAcceptanceVoter;
use App\Service\RiskApprovalConfigResolver;
use App\Service\RiskApprovalConfigView;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Approving a requested risk acceptance follows the organisation's approval
 * thresholds (same tiers that pick the approver): residual score up to the
 * manager threshold → ROLE_MANAGER, above → ROLE_TOP_MGMT (top management, ISO 27001 6.1.3 f); role hierarchy applies.
 */
#[AllowMockObjectsWithoutExpectations]
final class RiskAcceptanceVoterTest extends TestCase
{
    private RiskAcceptanceVoter $voter;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $resolver = $this->createMock(RiskApprovalConfigResolver::class);
        $resolver->method('resolveFor')->willReturn(new RiskApprovalConfigView(3, 7, 25));
        $this->voter = new RiskAcceptanceVoter(VoterTestHelper::createRoleHierarchy(), $resolver);

        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(1);
    }

    private function user(array $roles, ?Tenant $tenant = null): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getTenant')->willReturn($tenant ?? $this->tenant);

        return $user;
    }

    private function risk(int $residual): Risk
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getResidualRiskLevel')->willReturn($residual);
        $risk->method('getTenant')->willReturn($this->tenant);

        return $risk;
    }

    private function vote(User $user, mixed $subject, string $attribute = RiskAcceptanceVoter::APPROVE): int
    {
        return $this->voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $subject, [$attribute]);
    }

    /** @return iterable<string, array{int, list<string>, int}> */
    public static function tiers(): iterable
    {
        yield 'manager tier, user' => [6, ['ROLE_USER'], VoterInterface::ACCESS_DENIED];
        yield 'manager tier, manager' => [7, ['ROLE_MANAGER'], VoterInterface::ACCESS_GRANTED];
        yield 'executive tier, manager' => [8, ['ROLE_MANAGER'], VoterInterface::ACCESS_DENIED];
        yield 'executive tier, top management' => [12, ['ROLE_TOP_MGMT'], VoterInterface::ACCESS_GRANTED];
        yield 'manager tier, top management only' => [5, ['ROLE_TOP_MGMT'], VoterInterface::ACCESS_DENIED];
        // ISO 27001 5.1: accepting a high residual risk is a top-management decision — admin does not inherit it.
        yield 'executive tier, admin without Leitung role' => [16, ['ROLE_ADMIN'], VoterInterface::ACCESS_DENIED];
        yield 'executive tier, super admin without Leitung role' => [25, ['ROLE_SUPER_ADMIN'], VoterInterface::ACCESS_DENIED];
        yield 'executive tier, admin with Leitung role' => [16, ['ROLE_ADMIN', 'ROLE_TOP_MGMT'], VoterInterface::ACCESS_GRANTED];
        yield 'manager tier, admin via hierarchy' => [5, ['ROLE_ADMIN'], VoterInterface::ACCESS_GRANTED];
    }

    #[Test]
    #[DataProvider('tiers')]
    public function followsTheApprovalThresholds(int $residual, array $roles, int $expected): void
    {
        self::assertSame($expected, $this->vote($this->user($roles), $this->risk($residual)));
    }

    #[Test]
    public function adminOfAnotherOrganisationIsDenied(): void
    {
        $other = $this->createMock(Tenant::class);
        $other->method('getId')->willReturn(2);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->user(['ROLE_ADMIN'], $other), $this->risk(5)));
    }

    #[Test]
    public function abstainsOnOtherAttributesAndSubjects(): void
    {
        $admin = $this->user(['ROLE_SUPER_ADMIN']);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($admin, $this->risk(10), 'other'));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($admin, new \stdClass()));
    }

    #[Test]
    public function deniesWithoutAppUser(): void
    {
        $token = new UsernamePasswordToken(new \Symfony\Component\Security\Core\User\InMemoryUser('anon', null, []), 'main', []);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $this->risk(3), [RiskAcceptanceVoter::APPROVE]));
    }
}
