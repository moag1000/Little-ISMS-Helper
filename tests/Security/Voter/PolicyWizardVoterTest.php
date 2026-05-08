<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\ComplianceFramework;
use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use App\Security\Voter\PolicyWizardVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Coverage matrix for PolicyWizardVoter (Phase 4-C / W1-B).
 *
 * Attribute / Role intersection — each row is one #[Test] in this class:
 *
 *  ┌───────────────────────────┬──────────────────────────┬────────┐
 *  │ Attribute                 │ Role / Scenario          │ Result │
 *  ├───────────────────────────┼──────────────────────────┼────────┤
 *  │ RUN_FULL                  │ ROLE_CISO                │ GRANT  │
 *  │ RUN_FULL                  │ ROLE_USER                │ DENY   │
 *  │ RUN_TARGETED              │ ROLE_MANAGER             │ GRANT  │
 *  │ RUN_TARGETED              │ ROLE_USER                │ DENY   │
 *  │ RUN_SANDBOX               │ ROLE_USER                │ GRANT  │
 *  │ RUN_SANDBOX               │ inactive                 │ DENY   │
 *  │ BULK_APPROVE              │ ROLE_TOP_MGMT (non-DORA) │ GRANT  │
 *  │ BULK_APPROVE              │ ROLE_USER                │ DENY   │
 *  │ BULK_APPROVE              │ ROLE_TOP_MGMT DORA no    │ DENY   │
 *  │                           │   dual-signoff           │        │
 *  │ BULK_APPROVE              │ ROLE_TOP_MGMT DORA with  │ GRANT  │
 *  │                           │   dual-signoff           │        │
 *  │ FUNCTION_OWNER_REVIEW     │ FO matching function     │ GRANT  │
 *  │ FUNCTION_OWNER_REVIEW     │ FO mismatching function  │ DENY   │
 *  │ FUNCTION_OWNER_REVIEW     │ ROLE_CISO override       │ GRANT  │
 *  │ KONZERN_DEFAULTS          │ ROLE_GROUP_BCM_OFFICER   │ GRANT  │
 *  │ KONZERN_DEFAULTS          │ ROLE_USER                │ DENY   │
 *  │ DPO_SECTION_VETO          │ ROLE_DPO + dpoSection on │ GRANT  │
 *  │ DPO_SECTION_VETO          │ ROLE_DPO + dpoSection off│ DENY   │
 *  │ DPO_SECTION_VETO          │ ROLE_USER                │ DENY   │
 *  │ SUPER_ADMIN               │ any                      │ GRANT  │
 *  │ ABSTAIN                   │ unsupported attribute    │ ABSTN  │
 *  └───────────────────────────┴──────────────────────────┴────────┘
 */
#[AllowMockObjectsWithoutExpectations]
class PolicyWizardVoterTest extends TestCase
{
    private const int TENANT_ID = 42;

    private function makeVoter(?bool $doraActive = null): PolicyWizardVoter
    {
        if ($doraActive === null) {
            return new PolicyWizardVoter(null);
        }

        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn($doraActive);

        $repo = $this->createMock(ComplianceFrameworkRepository::class);
        $repo->method('findOneBy')->willReturnCallback(
            static fn(array $criteria): ?ComplianceFramework =>
                ($criteria['code'] ?? null) === 'DORA' ? $framework : null,
        );

        return new PolicyWizardVoter($repo);
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(
        array $roles = ['ROLE_USER'],
        bool $isActive = true,
        ?Tenant $tenant = null,
        ?string $department = null,
    ): User {
        $tenant ??= $this->makeTenant(self::TENANT_ID);
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(array_unique([...$roles, 'ROLE_USER']));
        $user->method('isActive')->willReturn($isActive);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('getDepartment')->willReturn($department);
        return $user;
    }

    private function makeTenant(int $id): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('isChildOf')->willReturn(false);
        return $tenant;
    }

    /**
     * @param list<string>|null $affectedFunctions
     */
    private function makePolicyTemplate(
        ?array $affectedFunctions = null,
        bool $dpoSectionRequired = false,
    ): PolicyTemplate {
        $tpl = $this->createMock(PolicyTemplate::class);
        $tpl->method('getAffectedFunctions')->willReturn($affectedFunctions);
        $tpl->method('isDpoSectionRequired')->willReturn($dpoSectionRequired);
        return $tpl;
    }

    private function makeDocument(?Tenant $tenant = null, ?string $category = null): Document
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getTenant')->willReturn($tenant);
        $doc->method('getCategory')->willReturn($category);
        return $doc;
    }

    private function makeToken(User $user, array $attributes = []): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('hasAttribute')->willReturnCallback(
            static fn(string $name): bool => array_key_exists($name, $attributes),
        );
        $token->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => $attributes[$name] ?? null,
        );
        return $token;
    }

    // ─── RUN_FULL ────────────────────────────────────────────────────────────

    #[Test]
    public function ciso_can_run_full_wizard(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_CISO']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, $user->getTenant(), [PolicyWizardVoter::RUN_FULL]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function plain_user_cannot_run_full_wizard(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_USER']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, null, [PolicyWizardVoter::RUN_FULL]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ─── RUN_TARGETED ────────────────────────────────────────────────────────

    #[Test]
    public function manager_can_run_targeted_wizard(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_MANAGER']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, null, [PolicyWizardVoter::RUN_TARGETED]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function plain_user_cannot_run_targeted_wizard(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_USER']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, null, [PolicyWizardVoter::RUN_TARGETED]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ─── RUN_SANDBOX ─────────────────────────────────────────────────────────

    #[Test]
    public function plain_user_can_run_sandbox(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_USER']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, null, [PolicyWizardVoter::RUN_SANDBOX]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function inactive_user_cannot_run_sandbox(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_USER'], isActive: false);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, null, [PolicyWizardVoter::RUN_SANDBOX]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ─── BULK_APPROVE ────────────────────────────────────────────────────────

    #[Test]
    public function top_mgmt_can_bulk_approve_in_non_dora_tenant(): void
    {
        $voter = $this->makeVoter(doraActive: false);
        $user = $this->makeUser(['ROLE_TOP_MGMT']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, $user->getTenant(), [PolicyWizardVoter::BULK_APPROVE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function plain_user_cannot_bulk_approve(): void
    {
        $voter = $this->makeVoter(doraActive: false);
        $user = $this->makeUser(['ROLE_USER']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, $user->getTenant(), [PolicyWizardVoter::BULK_APPROVE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function dora_tenant_top_mgmt_bulk_approve_without_dual_signoff_denied(): void
    {
        $voter = $this->makeVoter(doraActive: true);
        $user = $this->makeUser(['ROLE_TOP_MGMT']);
        $token = $this->makeToken($user); // keine dual_signoff Attribut

        $result = $voter->vote($token, $user->getTenant(), [PolicyWizardVoter::BULK_APPROVE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function dora_tenant_top_mgmt_bulk_approve_with_dual_signoff_granted(): void
    {
        $voter = $this->makeVoter(doraActive: true);
        $user = $this->makeUser(['ROLE_TOP_MGMT']);
        $token = $this->makeToken($user, ['policy_wizard.dual_signoff' => true]);

        $result = $voter->vote($token, $user->getTenant(), [PolicyWizardVoter::BULK_APPROVE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ─── FUNCTION_OWNER_REVIEW ───────────────────────────────────────────────

    #[Test]
    public function function_owner_can_review_policy_in_their_function(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_FUNCTION_OWNER'], department: 'HR');
        $token = $this->makeToken($user);
        $tpl = $this->makePolicyTemplate(affectedFunctions: ['HR', 'IT_OPERATIONS']);

        $result = $voter->vote($token, $tpl, [PolicyWizardVoter::FUNCTION_OWNER_REVIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function function_owner_cannot_review_policy_outside_their_function(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_FUNCTION_OWNER'], department: 'HR');
        $token = $this->makeToken($user);
        $tpl = $this->makePolicyTemplate(affectedFunctions: ['IT_OPERATIONS']);

        $result = $voter->vote($token, $tpl, [PolicyWizardVoter::FUNCTION_OWNER_REVIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function ciso_can_function_owner_review_any_policy(): void
    {
        $voter = $this->makeVoter();
        // CISO ohne Department, ohne ROLE_FUNCTION_OWNER — Compliance-Override
        $user = $this->makeUser(['ROLE_CISO']);
        $token = $this->makeToken($user);
        $tpl = $this->makePolicyTemplate(affectedFunctions: ['HR']);

        $result = $voter->vote($token, $tpl, [PolicyWizardVoter::FUNCTION_OWNER_REVIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function function_owner_review_on_document_uses_category_as_function(): void
    {
        $voter = $this->makeVoter();
        $tenant = $this->makeTenant(self::TENANT_ID);
        $user = $this->makeUser(['ROLE_FUNCTION_OWNER'], tenant: $tenant, department: 'HR');
        $token = $this->makeToken($user);
        $doc = $this->makeDocument($tenant, category: 'HR');

        $result = $voter->vote($token, $doc, [PolicyWizardVoter::FUNCTION_OWNER_REVIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ─── KONZERN_DEFAULTS ────────────────────────────────────────────────────

    #[Test]
    public function group_bcm_officer_can_manage_konzern_defaults(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_GROUP_BCM_OFFICER']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, $user->getTenant(), [PolicyWizardVoter::KONZERN_DEFAULTS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function group_ciso_can_manage_konzern_defaults(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_GROUP_CISO']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, $user->getTenant(), [PolicyWizardVoter::KONZERN_DEFAULTS]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function plain_user_cannot_manage_konzern_defaults(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_USER']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, $user->getTenant(), [PolicyWizardVoter::KONZERN_DEFAULTS]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ─── DPO_SECTION_VETO ────────────────────────────────────────────────────

    #[Test]
    public function dpo_can_veto_dpo_section_when_template_requires_it(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_DPO']);
        $token = $this->makeToken($user);
        $tpl = $this->makePolicyTemplate(dpoSectionRequired: true);

        $result = $voter->vote($token, $tpl, [PolicyWizardVoter::DPO_SECTION_VETO]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function dpo_cannot_veto_when_template_has_no_dpo_section(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_DPO']);
        $token = $this->makeToken($user);
        $tpl = $this->makePolicyTemplate(dpoSectionRequired: false);

        $result = $voter->vote($token, $tpl, [PolicyWizardVoter::DPO_SECTION_VETO]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function plain_user_cannot_veto_dpo_section(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_USER']);
        $token = $this->makeToken($user);
        $tpl = $this->makePolicyTemplate(dpoSectionRequired: true);

        $result = $voter->vote($token, $tpl, [PolicyWizardVoter::DPO_SECTION_VETO]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ─── SUPER_ADMIN BYPASS ──────────────────────────────────────────────────

    #[Test]
    public function super_admin_is_granted_anything(): void
    {
        $voter = $this->makeVoter(doraActive: true);
        $user = $this->makeUser(['ROLE_SUPER_ADMIN']);
        $token = $this->makeToken($user); // keine dual_signoff Attribut

        $result = $voter->vote($token, $user->getTenant(), [PolicyWizardVoter::BULK_APPROVE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ─── ABSTAIN ─────────────────────────────────────────────────────────────

    #[Test]
    public function voter_abstains_for_unsupported_attribute(): void
    {
        $voter = $this->makeVoter();
        $user = $this->makeUser(['ROLE_ADMIN']);
        $token = $this->makeToken($user);

        $result = $voter->vote($token, null, ['UNSUPPORTED_ATTRIBUTE']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
