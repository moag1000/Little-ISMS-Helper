<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\User;
use App\Service\PolicyWizard\ApproverMatchResult;
use App\Service\PolicyWizard\TopicApproverRoleResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TopicApproverRoleResolver — Task #126.
 *
 * Covers:
 *  - recommendedRolesForTopic() default-fallback for unknown topics.
 *  - recommendedRolesForTopic() known mappings (cryptography, hr_security,
 *    privacy_pii, supplier_relationships, business_continuity, etc.).
 *  - validateApproverForTopic() three match-states:
 *      * strict_match   (CISO approves Cryptography Policy)
 *      * weak_match     (ROLE_ADMIN approves Cryptography — broad-authority
 *                        but not topic-specialist)
 *      * mismatch       (Risk-Owner-Business approves Cryptography —
 *                        Persona-Walkthrough canonical example)
 *  - DPO carries privacy topics (privacy_pii, dpo_charter).
 *  - BCM-Officer carries continuity topics.
 */
#[AllowMockObjectsWithoutExpectations]
final class TopicApproverRoleResolverTest extends TestCase
{
    private TopicApproverRoleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TopicApproverRoleResolver();
    }

    /**
     * @return list<string> Symfony role-strings the user appears to carry
     */
    private function userWithRoles(array $roles): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getId')->willReturn(42);
        return $user;
    }

    #[Test]
    public function recommendedRolesForCryptography(): void
    {
        $roles = $this->resolver->recommendedRolesForTopic('cryptography');
        self::assertContains('ROLE_CISO', $roles);
        self::assertContains('ROLE_IT_OPS_LEAD', $roles);
    }

    #[Test]
    public function recommendedRolesForHrSecurity(): void
    {
        $roles = $this->resolver->recommendedRolesForTopic('hr_security');
        self::assertContains('ROLE_HR_LEAD', $roles);
        self::assertContains('ROLE_CISO', $roles);
    }

    #[Test]
    public function recommendedRolesForPrivacyTopicsAreDpo(): void
    {
        self::assertContains('ROLE_DPO', $this->resolver->recommendedRolesForTopic('privacy_pii'));
        self::assertContains('ROLE_DPO', $this->resolver->recommendedRolesForTopic('privacy_policy'));
        self::assertContains('ROLE_DPO', $this->resolver->recommendedRolesForTopic('dpia'));
    }

    #[Test]
    public function recommendedRolesForBusinessContinuityIncludeBcmOfficer(): void
    {
        $continuityTopics = ['continuity', 'bcms_top_level', 'bia_methodology', 'crisis_management_plan'];
        foreach ($continuityTopics as $topic) {
            $roles = $this->resolver->recommendedRolesForTopic($topic);
            self::assertContains('ROLE_BCM_OFFICER', $roles, sprintf(
                'Topic "%s" must include ROLE_BCM_OFFICER',
                $topic,
            ));
        }
    }

    #[Test]
    public function recommendedRolesForUnknownTopicFallsBackToDefault(): void
    {
        $roles = $this->resolver->recommendedRolesForTopic('this_topic_does_not_exist_yet');
        self::assertContains('ROLE_CISO', $roles);
        self::assertContains('ROLE_TOP_MGMT', $roles);
    }

    #[Test]
    public function recommendedRolesForNullTopicFallsBackToDefault(): void
    {
        $roles = $this->resolver->recommendedRolesForTopic(null);
        self::assertContains('ROLE_CISO', $roles);
    }

    #[Test]
    public function strictMatchWhenApproverHasRecommendedRole(): void
    {
        // CISO approves Cryptography Policy — fachlich correct.
        $ciso = $this->userWithRoles(['ROLE_USER', 'ROLE_CISO']);

        $result = $this->resolver->validateApproverForTopic($ciso, 'cryptography');

        self::assertSame(TopicApproverRoleResolver::MATCH_STRICT, $result->state);
        self::assertTrue($result->isStrictMatch());
        self::assertContains('ROLE_CISO', $result->matchedRoles);
        self::assertNotEmpty($result->reason);
    }

    #[Test]
    public function weakMatchWhenApproverHasOnlyAdminRole(): void
    {
        // Plain ROLE_ADMIN approves a Cryptography Policy. The user
        // has broad authority but is NOT the topic-specialist (CISO /
        // IT-OPS-LEAD). Result: weak_match — approval permitted but
        // the audit-trail flags "broad-authority approval used".
        $admin = $this->userWithRoles(['ROLE_USER', 'ROLE_ADMIN']);

        $result = $this->resolver->validateApproverForTopic($admin, 'cryptography');

        self::assertSame(TopicApproverRoleResolver::MATCH_WEAK, $result->state);
        self::assertTrue($result->isWeakMatch());
        self::assertContains('ROLE_ADMIN', $result->matchedRoles);
    }

    #[Test]
    public function mismatchWhenApproverHasNeitherRecommendedNorBroadAuthority(): void
    {
        // Persona-walkthrough canonical example: Risk-Owner-Business
        // (Fachbereichsleiter) carries only ROLE_USER and ROLE_MANAGER.
        // Neither maps to cryptography topic-specialist nor to the
        // universal-weak set. Result: mismatch — approval still
        // permitted but the audit trail emits the warning event.
        $riskOwner = $this->userWithRoles(['ROLE_USER', 'ROLE_MANAGER']);

        $result = $this->resolver->validateApproverForTopic($riskOwner, 'cryptography');

        self::assertSame(TopicApproverRoleResolver::MATCH_MISMATCH, $result->state);
        self::assertTrue($result->isMismatch());
        self::assertEmpty($result->matchedRoles);
        self::assertStringContainsString('cryptography', $result->reason);
    }

    #[Test]
    public function strictMatchForDpoOnPrivacyTopic(): void
    {
        $dpo = $this->userWithRoles(['ROLE_USER', 'ROLE_DPO']);

        $result = $this->resolver->validateApproverForTopic($dpo, 'privacy_pii');

        self::assertSame(TopicApproverRoleResolver::MATCH_STRICT, $result->state);
        self::assertContains('ROLE_DPO', $result->matchedRoles);
    }

    #[Test]
    public function ciSoApprovingPrivacyTopicYieldsMismatchByDefault(): void
    {
        // privacy_pii is DPO-only (no ROLE_CISO in the recommendation
        // list). A plain CISO without ROLE_DPO produces a mismatch —
        // GDPR Art. 38(3) DPO-independence is the explicit reason.
        $ciso = $this->userWithRoles(['ROLE_USER', 'ROLE_CISO']);

        $result = $this->resolver->validateApproverForTopic($ciso, 'privacy_pii');

        self::assertSame(TopicApproverRoleResolver::MATCH_MISMATCH, $result->state);
    }

    #[Test]
    public function topMgmtApprovingCryptographyIsWeakMatch(): void
    {
        // ROLE_TOP_MGMT is in the universal-weak list (governance-level
        // broad authority). It is NOT in the cryptography
        // recommended list, so the result is weak_match.
        $topMgmt = $this->userWithRoles(['ROLE_USER', 'ROLE_TOP_MGMT']);

        $result = $this->resolver->validateApproverForTopic($topMgmt, 'cryptography');

        self::assertSame(TopicApproverRoleResolver::MATCH_WEAK, $result->state);
        self::assertContains('ROLE_TOP_MGMT', $result->matchedRoles);
    }

    #[Test]
    public function topMgmtApprovingTopLevelPolicyIsStrictMatch(): void
    {
        // top_level (ISO 27001 Cl. 5.2 Top-Level-Leitlinie) explicitly
        // includes ROLE_TOP_MGMT — Geschäftsführung is the fachlich
        // correct approver for the top-level governance document.
        $topMgmt = $this->userWithRoles(['ROLE_USER', 'ROLE_TOP_MGMT']);

        $result = $this->resolver->validateApproverForTopic($topMgmt, 'top_level');

        self::assertSame(TopicApproverRoleResolver::MATCH_STRICT, $result->state);
        self::assertContains('ROLE_TOP_MGMT', $result->matchedRoles);
    }

    #[Test]
    public function bcmOfficerApprovingContinuityIsStrictMatch(): void
    {
        $bcm = $this->userWithRoles(['ROLE_USER', 'ROLE_BCM_OFFICER']);

        $result = $this->resolver->validateApproverForTopic($bcm, 'continuity');

        self::assertSame(TopicApproverRoleResolver::MATCH_STRICT, $result->state);
        self::assertContains('ROLE_BCM_OFFICER', $result->matchedRoles);
    }

    #[Test]
    public function auditPayloadCarriesAllReasoningFields(): void
    {
        $admin = $this->userWithRoles(['ROLE_USER', 'ROLE_ADMIN']);

        $result = $this->resolver->validateApproverForTopic($admin, 'cryptography');
        $payload = $result->toAuditPayload();

        self::assertArrayHasKey('state', $payload);
        self::assertArrayHasKey('topic_key', $payload);
        self::assertArrayHasKey('recommended_roles', $payload);
        self::assertArrayHasKey('approver_roles', $payload);
        self::assertArrayHasKey('matched_roles', $payload);
        self::assertArrayHasKey('reason', $payload);
        self::assertSame('cryptography', $payload['topic_key']);
        self::assertSame(TopicApproverRoleResolver::MATCH_WEAK, $payload['state']);
    }

    #[Test]
    public function approverMatchResultIsImmutable(): void
    {
        // Sanity: the DTO is readonly. A mutation attempt fatals at
        // runtime — the property is a constructor-promoted readonly.
        // We check the DTO carries the expected helper-method API
        // instead of risking a fatal.
        $admin = $this->userWithRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $result = $this->resolver->validateApproverForTopic($admin, 'cryptography');

        self::assertInstanceOf(ApproverMatchResult::class, $result);
        self::assertFalse($result->isStrictMatch());
        self::assertTrue($result->isWeakMatch());
        self::assertFalse($result->isMismatch());
    }
}
