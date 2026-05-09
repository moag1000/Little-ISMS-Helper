<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\DocumentSectionVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * DocumentSectionVoter coverage matrix — Phase 4-C / W3-C.
 *
 * ┌──────────────────────────┬─────────────────────────────────┬────────┐
 * │ Attribute                │ Role / Scenario                 │ Result │
 * ├──────────────────────────┼─────────────────────────────────┼────────┤
 * │ DOCUMENT_SECTION_APPROVE │ ROLE_DPO + privacy section      │ GRANT  │
 * │ DOCUMENT_SECTION_APPROVE │ ROLE_CISO + privacy section     │ DENY   │
 * │ DOCUMENT_SECTION_APPROVE │ ROLE_TOP_MGMT + privacy section │ DENY   │
 * │ DOCUMENT_SECTION_REJECT  │ ROLE_DPO + privacy section      │ GRANT  │
 * │ DOCUMENT_SECTION_APPROVE │ ROLE_DPO cross-tenant           │ DENY   │
 * │ DOCUMENT_SECTION_APPROVE │ ROLE_SUPER_ADMIN bypass         │ GRANT  │
 * └──────────────────────────┴─────────────────────────────────┴────────┘
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentSectionVoterTest extends TestCase
{
    private const int TENANT_ID = 42;
    private const int OTHER_TENANT_ID = 99;

    private function makeVoter(): DocumentSectionVoter
    {
        return new DocumentSectionVoter();
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(
        array $roles = ['ROLE_USER'],
        bool $isActive = true,
        ?Tenant $tenant = null,
    ): User {
        $tenant ??= $this->makeTenant(self::TENANT_ID);
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(array_unique([...$roles, 'ROLE_USER']));
        $user->method('isActive')->willReturn($isActive);
        $user->method('getTenant')->willReturn($tenant);
        return $user;
    }

    private function makeTenant(int $id): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('isChildOf')->willReturn(false);
        return $tenant;
    }

    private function makeSection(?Tenant $tenant = null, string $sectionKey = 'privacy_addendum'): DocumentSection
    {
        $tenant ??= $this->makeTenant(self::TENANT_ID);
        $section = new DocumentSection();
        $section->setTenant($tenant);
        $section->setSectionKey($sectionKey);
        return $section;
    }

    private function makeToken(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        return $token;
    }

    #[Test]
    public function testDpoCanApprovePrivacySection(): void
    {
        $voter = $this->makeVoter();
        $tenant = $this->makeTenant(self::TENANT_ID);
        $user = $this->makeUser(['ROLE_DPO'], tenant: $tenant);
        $section = $this->makeSection($tenant, 'privacy_addendum');

        $result = $voter->vote($this->makeToken($user), $section, [DocumentSectionVoter::APPROVE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testCisoCannotApprovePrivacySection(): void
    {
        // GDPR Art. 38(3): even the CISO must NOT bypass the DPO veto
        // gate for a privacy section, regardless of host-document role.
        $voter = $this->makeVoter();
        $tenant = $this->makeTenant(self::TENANT_ID);
        $user = $this->makeUser(['ROLE_CISO'], tenant: $tenant);
        $section = $this->makeSection($tenant, 'privacy_addendum_breach');

        $result = $voter->vote($this->makeToken($user), $section, [DocumentSectionVoter::APPROVE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testTopMgmtCannotApprovePrivacySection(): void
    {
        $voter = $this->makeVoter();
        $tenant = $this->makeTenant(self::TENANT_ID);
        $user = $this->makeUser(['ROLE_TOP_MGMT'], tenant: $tenant);
        $section = $this->makeSection($tenant, 'privacy_addendum');

        $result = $voter->vote($this->makeToken($user), $section, [DocumentSectionVoter::APPROVE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testDpoCanRejectPrivacySection(): void
    {
        $voter = $this->makeVoter();
        $tenant = $this->makeTenant(self::TENANT_ID);
        $user = $this->makeUser(['ROLE_DPO'], tenant: $tenant);
        $section = $this->makeSection($tenant, 'privacy_addendum');

        $result = $voter->vote($this->makeToken($user), $section, [DocumentSectionVoter::REJECT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testRoleScopeRespectedAcrossTenants(): void
    {
        // DPO of tenant A must not approve a section that lives in
        // tenant B — even with the right role, tenant isolation wins.
        $voter = $this->makeVoter();
        $userTenant = $this->makeTenant(self::TENANT_ID);
        $sectionTenant = $this->makeTenant(self::OTHER_TENANT_ID);
        $user = $this->makeUser(['ROLE_DPO'], tenant: $userTenant);
        $section = $this->makeSection($sectionTenant, 'privacy_addendum');

        $result = $voter->vote($this->makeToken($user), $section, [DocumentSectionVoter::APPROVE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testSuperAdminBypass(): void
    {
        // ROLE_SUPER_ADMIN ignores both tenant scope and section-type
        // restrictions — consistent with every other voter in the suite.
        $voter = $this->makeVoter();
        $userTenant = $this->makeTenant(self::TENANT_ID);
        $sectionTenant = $this->makeTenant(self::OTHER_TENANT_ID);
        $user = $this->makeUser(['ROLE_SUPER_ADMIN'], tenant: $userTenant);
        $section = $this->makeSection($sectionTenant, 'privacy_addendum');

        $result = $voter->vote($this->makeToken($user), $section, [DocumentSectionVoter::APPROVE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testViewIsBroaderThanApprove(): void
    {
        // GDPR Art. 38(3) carve-out applies to *write* (approve/reject)
        // — read access is allowed for the curator roles.
        $voter = $this->makeVoter();
        $tenant = $this->makeTenant(self::TENANT_ID);
        $user = $this->makeUser(['ROLE_CISO'], tenant: $tenant);
        $section = $this->makeSection($tenant, 'privacy_addendum');

        $result = $voter->vote($this->makeToken($user), $section, [DocumentSectionVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }
}
