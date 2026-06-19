<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\ComplianceCertificate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\ComplianceCertificateVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Unit tests for {@see ComplianceCertificateVoter}.
 *
 * Uses the shared {@see VoterTestHelper} role hierarchy so the reachable-role
 * expansion matches production security.yaml.
 */
#[AllowMockObjectsWithoutExpectations]
class ComplianceCertificateVoterTest extends TestCase
{
    private ComplianceCertificateVoter $voter;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->voter = new ComplianceCertificateVoter(VoterTestHelper::createRoleHierarchy());

        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(1);
    }

    private function createUser(array $roles): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getTenant')->willReturn($this->tenant);

        return $user;
    }

    private function createCertificate(?Tenant $tenant = null): ComplianceCertificate
    {
        $cert = new ComplianceCertificate();
        $cert->setTenant($tenant ?? $this->tenant);

        return $cert;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function vote(array $roles, string $attribute, ?Tenant $certTenant = null): int
    {
        $user = $this->createUser($roles);

        return $this->voter->vote(
            $this->token($user),
            $this->createCertificate($certTenant),
            [$attribute],
        );
    }

    #[Test]
    public function manageIsGrantedForManager(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote(['ROLE_MANAGER'], ComplianceCertificateVoter::CERT_MANAGE),
        );
    }

    #[Test]
    public function manageIsDeniedForPlainUser(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(['ROLE_USER'], ComplianceCertificateVoter::CERT_MANAGE),
        );
    }

    #[Test]
    public function deleteIsGrantedOnlyForAdmin(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote(['ROLE_ADMIN'], ComplianceCertificateVoter::CERT_DELETE),
        );
    }

    #[Test]
    public function deleteIsDeniedForManager(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(['ROLE_MANAGER'], ComplianceCertificateVoter::CERT_DELETE),
        );
    }

    #[Test]
    public function viewIsGrantedForAuditor(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote(['ROLE_AUDITOR'], ComplianceCertificateVoter::CERT_VIEW),
        );
    }

    #[Test]
    public function viewIsGrantedForManagerAndAbove(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote(['ROLE_MANAGER'], ComplianceCertificateVoter::CERT_VIEW),
        );
    }

    #[Test]
    public function viewIsDeniedForPlainUser(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(['ROLE_USER'], ComplianceCertificateVoter::CERT_VIEW),
        );
    }

    #[Test]
    public function crossTenantAccessIsDenied(): void
    {
        $otherTenant = $this->createMock(Tenant::class);
        $otherTenant->method('getId')->willReturn(2);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(['ROLE_ADMIN'], ComplianceCertificateVoter::CERT_VIEW, $otherTenant),
        );
    }

    #[Test]
    public function nullSubjectGrantsViewForAuditorRoleOnly(): void
    {
        // Class-level #[IsGranted('CERT_VIEW')] passes a null subject — role-only gate.
        $user = $this->createUser(['ROLE_AUDITOR']);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->token($user), null, [ComplianceCertificateVoter::CERT_VIEW]),
        );
    }

    #[Test]
    public function nullSubjectDeniesViewForPlainUser(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->token($user), null, [ComplianceCertificateVoter::CERT_VIEW]),
        );
    }

    #[Test]
    public function voterAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote(['ROLE_ADMIN'], 'UNSUPPORTED'),
        );
    }

    #[Test]
    public function voterAbstainsForUnsupportedSubject(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->token($user), new \stdClass(), [ComplianceCertificateVoter::CERT_VIEW]),
        );
    }
}
