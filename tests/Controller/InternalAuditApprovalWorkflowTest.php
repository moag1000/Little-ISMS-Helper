<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * S3 P0-26 — Audit-Bericht 4-Augen-Approval-Workflow tests.
 *
 * Covers the lifecycle:
 *   planned → conducted → reported → approved → closed
 *                              ↓
 *                          rejected → reported (rework loop)
 *
 * Server-side 4-eyes enforcement is the security-critical invariant —
 * tests must hit the actual controller (WebTestCase) not just the
 * Entity helpers, otherwise the cross-cutting check could regress
 * without the test failing.
 */
class InternalAuditApprovalWorkflowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $auditorUser = null;
    private ?User $approverUser = null;
    private ?User $managerUser = null;
    private ?InternalAudit $testAudit = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testAudit) {
            try {
                $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
                if ($audit) {
                    $this->entityManager->remove($audit);
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        foreach ([$this->auditorUser, $this->approverUser, $this->managerUser] as $u) {
            if ($u === null) {
                continue;
            }
            try {
                $user = $this->entityManager->find(User::class, $u->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Ignore flush errors during cleanup
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('p0_26_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Approval-WF Tenant ' . $uniqueId);
        $this->testTenant->setCode('p0_26_tenant_' . substr($uniqueId, -8));
        $this->entityManager->persist($this->testTenant);

        // Auditor — reports the audit (YAML report/rework: ROLE_AUDITOR).
        $this->auditorUser = new User();
        $this->auditorUser->setEmail('auditor_' . $uniqueId . '@example.com');
        $this->auditorUser->setFirstName('Audit');
        $this->auditorUser->setLastName('Or');
        $this->auditorUser->setRoles(['ROLE_AUDITOR']);
        $this->auditorUser->setPassword('hashed_password');
        $this->auditorUser->setTenant($this->testTenant);
        $this->auditorUser->setIsActive(true);
        $this->entityManager->persist($this->auditorUser);

        // Approver — different user with ROLE_CISO to satisfy 4-eyes
        // (YAML `internal_audit.yaml` requires the approver to carry ROLE_CISO).
        $this->approverUser = new User();
        $this->approverUser->setEmail('approver_' . $uniqueId . '@example.com');
        $this->approverUser->setFirstName('App');
        $this->approverUser->setLastName('Rover');
        $this->approverUser->setRoles(['ROLE_CISO']);
        $this->approverUser->setPassword('hashed_password');
        $this->approverUser->setTenant($this->testTenant);
        $this->approverUser->setIsActive(true);
        $this->entityManager->persist($this->approverUser);

        // Manager — may NOT close (YAML close: ROLE_CISO), S21.
        $this->managerUser = new User();
        $this->managerUser->setEmail('manager_' . $uniqueId . '@example.com');
        $this->managerUser->setFirstName('Mana');
        $this->managerUser->setLastName('Ger');
        $this->managerUser->setRoles(['ROLE_MANAGER']);
        $this->managerUser->setPassword('hashed_password');
        $this->managerUser->setTenant($this->testTenant);
        $this->managerUser->setIsActive(true);
        $this->entityManager->persist($this->managerUser);

        $this->testAudit = new InternalAudit();
        $this->testAudit->setAuditNumber('AUDIT-WF-' . substr($uniqueId, -6));
        $this->testAudit->setTitle('Approval Workflow Test Audit ' . $uniqueId);
        $this->testAudit->setScope('Test scope');
        $this->testAudit->setScopeType('full_isms');
        $this->testAudit->setStatus('conducted');
        $this->testAudit->setPlannedDate(new DateTime('+30 days'));
        $this->testAudit->setTenant($this->testTenant);
        $this->testAudit->setLeadAuditorUser($this->managerUser);
        $this->entityManager->persist($this->testAudit);

        $this->entityManager->flush();
    }

    private function loginAndCsrf(User $user, string $tokenId): string
    {
        $this->client->loginUser($user);
        // First GET to establish session.
        $this->client->request('GET', '/en/audit');
        $session = $this->client->getRequest()->getSession();

        $tokenGenerator = new UriSafeTokenGenerator();
        $token = $tokenGenerator->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        return $token;
    }

    // ==========================================================================
    // Entity-level lifecycle invariants (no HTTP needed)
    // ==========================================================================

    #[Test]
    public function testLifecycleStagesContainsExpectedKeys(): void
    {
        $stages = InternalAudit::LIFECYCLE_STAGES;
        foreach (['planned', 'conducted', 'reported', 'approved', 'rejected', 'closed', 'cancelled'] as $expected) {
            self::assertArrayHasKey($expected, $stages, sprintf('Lifecycle stage "%s" missing', $expected));
        }
    }

    #[Test]
    public function testCanTransitionToReportedFromConducted(): void
    {
        self::assertTrue($this->testAudit->canTransitionTo('reported'));
    }

    #[Test]
    public function testCannotTransitionDirectlyFromPlannedToApproved(): void
    {
        $this->testAudit->setStatus('planned');
        self::assertFalse($this->testAudit->canTransitionTo('approved'));
    }

    #[Test]
    public function testRejectedCanTransitionBackToReported(): void
    {
        $this->testAudit->setStatus('rejected');
        self::assertTrue($this->testAudit->canTransitionTo('reported'));
    }

    #[Test]
    public function testClosedIsTerminalState(): void
    {
        $this->testAudit->setStatus('closed');
        self::assertSame([], $this->testAudit->getAllowedTransitions());
    }

    // ==========================================================================
    // Happy-path: full lifecycle through HTTP
    // ==========================================================================

    #[Test]
    public function testHappyPathSubmitApproveClose(): void
    {
        // 1) Auditor submits the report (conducted → reported).
        $token = $this->loginAndCsrf($this->auditorUser, 'audit_submit_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/submit-report', [
            '_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('reported', $audit->getStatus());
        self::assertNotNull($audit->getReportedBy());
        self::assertSame($this->auditorUser->getId(), $audit->getReportedBy()->getId());
        self::assertNotNull($audit->getReportedAt());

        // 2) Approver (different user) approves the report (reported → approved).
        $token = $this->loginAndCsrf($this->approverUser, 'audit_approve_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/approve', [
            '_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('approved', $audit->getStatus());
        self::assertNotNull($audit->getApprovedBy());
        self::assertSame($this->approverUser->getId(), $audit->getApprovedBy()->getId());
        self::assertNotSame(
            $audit->getReportedBy()->getId(),
            $audit->getApprovedBy()->getId(),
            '4-eyes invariant: approver MUST differ from reporter.'
        );

        // 3) The CISO closes the audit (approved → closed; YAML roles: ROLE_CISO).
        $token = $this->loginAndCsrf($this->approverUser, 'audit_close');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/close', [
            '_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('closed', $audit->getStatus());
        self::assertNotNull($audit->getClosedBy());
        self::assertSame($this->approverUser->getId(), $audit->getClosedBy()->getId());
    }

    // ==========================================================================
    // S21 — the lifecycle roles of internal_audit.yaml decide, not ROLE_USER
    // ==========================================================================

    #[Test]
    public function testPlainUserCannotSubmitTheReport(): void
    {
        $plain = new User();
        $plain->setEmail('plain_' . uniqid('', true) . '@example.com');
        $plain->setFirstName('Plain');
        $plain->setLastName('User');
        $plain->setRoles(['ROLE_USER']);
        $plain->setPassword('hashed_password');
        $plain->setTenant($this->testTenant);
        $plain->setIsActive(true);
        $this->entityManager->persist($plain);
        $this->entityManager->flush();

        try {
            $token = $this->loginAndCsrf($plain, 'audit_submit_report');
            $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/submit-report', [
                '_token' => $token,
            ]);
            self::assertResponseStatusCodeSame(403);

            $this->entityManager->clear();
            $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
            self::assertSame('conducted', $audit->getStatus());
            self::assertNull($audit->getReportedBy(), 'A refused step must not leave the reporter stamped.');
            self::assertNull($audit->getReportedAt());
        } finally {
            $this->entityManager->clear();
            $stale = $this->entityManager->find(User::class, $plain->getId());
            if ($stale !== null) {
                $this->entityManager->remove($stale);
                $this->entityManager->flush();
            }
        }
    }

    #[Test]
    public function testManagerWithoutCisoRoleCannotCloseTheAudit(): void
    {
        $this->testAudit->setStatus('approved');
        $this->entityManager->flush();

        $token = $this->loginAndCsrf($this->managerUser, 'audit_close');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/close', [
            '_token' => $token,
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('approved', $audit->getStatus());
        self::assertNull($audit->getClosedBy());
    }

    #[Test]
    public function testAuditorCannotRejectTheReport(): void
    {
        $this->testAudit->setStatus('reported');
        $this->entityManager->flush();

        $token = $this->loginAndCsrf($this->auditorUser, 'audit_reject_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/reject', [
            '_token' => $token,
            'rejection_reason' => 'Own report rejected by its auditor.',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('reported', $audit->getStatus());
        self::assertNull($audit->getRejectionReason());
    }

    // ==========================================================================
    // 4-eyes enforcement
    // ==========================================================================

    #[Test]
    public function testApproverMustDifferFromReporter(): void
    {
        // The CISO (who reaches ROLE_AUDITOR) submits the report …
        $token = $this->loginAndCsrf($this->approverUser, 'audit_submit_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/submit-report', [
            '_token' => $token,
        ]);
        $this->client->followRedirect();

        // … and tries to approve it — the 4-eyes guard must refuse.
        $token = $this->loginAndCsrf($this->approverUser, 'audit_approve_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/approve', [
            '_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('reported', $audit->getStatus(), '4-eyes guard must keep audit in "reported" when same user tries to approve own report.');
        self::assertNull($audit->getApprovedBy(), 'approvedBy must remain NULL after blocked self-approval.');
    }

    // ==========================================================================
    // Rejection path
    // ==========================================================================

    #[Test]
    public function testRejectRequiresReason(): void
    {
        // Auditor submits.
        $token = $this->loginAndCsrf($this->auditorUser, 'audit_submit_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/submit-report', [
            '_token' => $token,
        ]);
        $this->client->followRedirect();

        // Approver tries to reject WITHOUT reason — must remain "reported".
        $token = $this->loginAndCsrf($this->approverUser, 'audit_reject_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/reject', [
            '_token' => $token,
            'rejection_reason' => '',
        ]);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('reported', $audit->getStatus(), 'Reject without reason must NOT transition to "rejected".');
        self::assertNull($audit->getRejectionReason());
    }

    #[Test]
    public function testRejectWithReasonAndResubmit(): void
    {
        // Auditor submits.
        $token = $this->loginAndCsrf($this->auditorUser, 'audit_submit_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/submit-report', [
            '_token' => $token,
        ]);
        $this->client->followRedirect();

        // Approver rejects with reason.
        $token = $this->loginAndCsrf($this->approverUser, 'audit_reject_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/reject', [
            '_token' => $token,
            'rejection_reason' => 'Section 3 of the report is missing the evidence-mapping for control A.5.1.',
        ]);
        $this->client->followRedirect();

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('rejected', $audit->getStatus());
        self::assertNotNull($audit->getRejectionReason());
        self::assertStringContainsString('A.5.1', $audit->getRejectionReason());

        // Auditor resubmits the revised report (rejected → reported).
        $token = $this->loginAndCsrf($this->auditorUser, 'audit_resubmit_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/resubmit', [
            '_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('reported', $audit->getStatus());
    }

    // ==========================================================================
    // Invalid transitions
    // ==========================================================================

    #[Test]
    public function testCannotApproveAuditInPlannedStatus(): void
    {
        $this->testAudit->setStatus('planned');
        $this->entityManager->flush();

        $token = $this->loginAndCsrf($this->approverUser, 'audit_approve_report');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/approve', [
            '_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('planned', $audit->getStatus());
        self::assertNull($audit->getApprovedBy());
    }

    #[Test]
    public function testCannotCloseAuditNotYetApproved(): void
    {
        $this->testAudit->setStatus('reported');
        $this->entityManager->flush();

        $token = $this->loginAndCsrf($this->approverUser, 'audit_close');
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/close', [
            '_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
        self::assertSame('reported', $audit->getStatus());
        self::assertNull($audit->getClosedBy());
    }
}
