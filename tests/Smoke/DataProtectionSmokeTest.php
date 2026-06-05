<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Entity\Consent;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\DataSubjectRequest;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\ConsentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use App\Service\DataProtectionImpactAssessmentService;
use App\Service\DataSubjectRequestService;
use App\Service\PersonalDataExportService;
use App\Service\RetentionEnforcementService;
use App\Service\TenantContext;
use App\Exception\BusinessRule\BusinessRuleException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Validation;

/**
 * Data-protection smoke test — each method is one end-to-end causal chain:
 * "GIVEN X, THEN Y must happen — does it?". Verifies the audit fixes hold
 * together at runtime, not just that fields exist.
 */
#[AllowMockObjectsWithoutExpectations]
final class DataProtectionSmokeTest extends TestCase
{
    private function validator(): \Symfony\Component\Validator\Validator\ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /** @return list<string> property paths of the violations */
    private function violationPaths(object $entity): array
    {
        return array_map(
            static fn ($v): string => $v->getPropertyPath(),
            iterator_to_array($this->validator()->validate($entity)),
        );
    }

    private function dsrService(EntityManagerInterface $em, LifecycleTransitionInterface $lifecycle): DataSubjectRequestService
    {
        return new DataSubjectRequestService(
            $em,
            $this->createMock(DataSubjectRequestRepository::class),
            $this->createMock(TenantContext::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(LoggerInterface::class),
            $lifecycle,
        );
    }

    private function dpiaService(EntityManagerInterface $em, LifecycleTransitionInterface $lifecycle): DataProtectionImpactAssessmentService
    {
        return new DataProtectionImpactAssessmentService(
            $this->createMock(DataProtectionImpactAssessmentRepository::class),
            $em,
            $this->createMock(TenantContext::class),
            $this->createMock(Security::class),
            $this->createMock(AuditLogger::class),
            $lifecycle,
        );
    }

    // ── H-1 — DSR identity gate (Art. 12(6)) ─────────────────────────────

    #[Test]
    #[TestDox('GIVEN an access DSR with UNVERIFIED identity, WHEN completed, THEN it is blocked AND nothing is released')]
    public function dsrCompleteUnverifiedAccessIsBlockedAndReleasesNothing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $lifecycle = $this->createMock(LifecycleTransitionInterface::class);

        $dsr = $this->createMock(DataSubjectRequest::class);
        $dsr->method('getStatus')->willReturn('in_progress');
        $dsr->method('getRequestType')->willReturn('access');
        $dsr->method('isIdentityVerified')->willReturn(false);

        // THEN: no persistence, no lifecycle transition — data stays put.
        $em->expects($this->never())->method('flush');
        $lifecycle->expects($this->never())->method('transition');

        $this->expectException(BusinessRuleException::class);
        $this->dsrService($em, $lifecycle)->complete($dsr, 'here is your data');
    }

    #[Test]
    #[TestDox('GIVEN an objection DSR (acts on processing, not release), WHEN completed UNVERIFIED, THEN it is allowed')]
    public function dsrCompleteUnverifiedObjectionIsAllowed(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $lifecycle = $this->createMock(LifecycleTransitionInterface::class);
        $now = new DateTimeImmutable();

        $dsr = $this->createMock(DataSubjectRequest::class);
        $dsr->method('getStatus')->willReturn('in_progress');
        $dsr->method('getRequestType')->willReturn('objection');
        $dsr->method('isIdentityVerified')->willReturn(false);
        $dsr->method('getReceivedAt')->willReturn($now);
        $dsr->method('getCompletedAt')->willReturn($now);

        $lifecycle->expects($this->once())->method('transition');
        $em->expects($this->once())->method('flush');

        $this->dsrService($em, $lifecycle)->complete($dsr, 'objection upheld');
    }

    // ── H-2 — DPIA consultation gates (Art. 35(2)/36) ────────────────────

    #[Test]
    #[TestDox('GIVEN a DPIA in review WITHOUT recorded DPO consultation, WHEN approved, THEN it is blocked')]
    public function dpiaApproveWithoutDpoConsultationIsBlocked(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $lifecycle = $this->createMock(LifecycleTransitionInterface::class);

        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getStatus')->willReturn('in_review');
        $dpia->method('getDpoConsultationDate')->willReturn(null);

        $em->expects($this->never())->method('flush');
        $lifecycle->expects($this->never())->method('transition');

        $this->expectException(BusinessRuleException::class);
        $this->dpiaService($em, $lifecycle)->approve($dpia, $this->createMock(\App\Entity\User::class));
    }

    #[Test]
    #[TestDox('GIVEN a DPIA with HIGH residual risk, DPO consulted but supervisory NOT, WHEN approved, THEN it is blocked (Art. 36)')]
    public function dpiaApproveHighResidualWithoutSupervisoryIsBlocked(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $lifecycle = $this->createMock(LifecycleTransitionInterface::class);

        $dpia = $this->createMock(DataProtectionImpactAssessment::class);
        $dpia->method('getStatus')->willReturn('in_review');
        $dpia->method('getDpoConsultationDate')->willReturn(new \DateTime());
        $dpia->method('getResidualRiskLevel')->willReturn('high');
        $dpia->method('getSupervisoryConsultationDate')->willReturn(null);

        $em->expects($this->never())->method('flush');

        $this->expectException(BusinessRuleException::class);
        $this->dpiaService($em, $lifecycle)->approve($dpia, $this->createMock(\App\Entity\User::class));
    }

    // ── M-1 — Consent withdrawal consistency (Art. 7(3)) ─────────────────

    #[Test]
    #[TestDox('GIVEN a consent, WHEN withdrawn, THEN BOTH field groups are set AND isValid() is false')]
    public function consentWithdrawalSetsBothGroupsAndInvalidates(): void
    {
        $consent = new Consent();
        $consent->setStatus('active');
        self::assertTrue($consent->isValid(), 'precondition: active consent is valid');

        $consent->recordWithdrawal('email', 'no longer needed');

        // THEN: revocation AND withdrawal groups agree, and it is no longer valid.
        self::assertTrue($consent->isRevoked());
        self::assertTrue($consent->isWithdrawn());
        self::assertSame($consent->getRevokedAt(), $consent->getWithdrawnAt());
        self::assertFalse($consent->isValid());
    }

    #[Test]
    #[TestDox('GIVEN a legacy consent with ONLY withdrawnAt set, THEN isValid() is still false (defense-in-depth)')]
    public function consentWithOnlyWithdrawnAtIsInvalid(): void
    {
        $consent = new Consent();
        $consent->setStatus('active');
        $consent->setWithdrawnAt(new DateTimeImmutable());

        self::assertFalse($consent->isRevoked());
        self::assertFalse($consent->isValid());
    }

    // ── M-2 — Personal-data export (Art. 15/20) ──────────────────────────

    #[Test]
    #[TestDox('GIVEN a subject with a consent on file, WHEN building the export, THEN it contains the record AND the manual-sources checklist')]
    public function personalDataExportAggregatesRecordsAndChecklist(): void
    {
        $consent = $this->createMock(Consent::class);
        $consent->method('getId')->willReturn(1);
        $consent->method('getPurposes')->willReturn(['marketing']);

        $consentRepo = $this->createMock(ConsentRepository::class);
        $consentRepo->method('findByDataSubject')->willReturn([$consent]);
        $dsrRepo = $this->createMock(DataSubjectRequestRepository::class);
        $dsrRepo->method('findBy')->willReturn([]);

        $source = $this->createMock(DataSubjectRequest::class);
        $source->method('getId')->willReturn(9);
        $source->method('getTenant')->willReturn(null);
        $source->method('getDataSubjectEmail')->willReturn('max@example.com');
        $source->method('getDataSubjectIdentifier')->willReturn('cust-1');
        $source->method('getDataSubjectName')->willReturn(null);

        $export = (new PersonalDataExportService($consentRepo, $dsrRepo))->buildExport($source);

        self::assertSame('max@example.com', $export['data_subject']['email']);
        self::assertCount(1, $export['records_held_in_tool']['consents']);
        self::assertArrayHasKey('manual_sources_checklist', $export);
        self::assertArrayHasKey('processors', $export['manual_sources_checklist']);
    }

    // ── M-4 — Retention enforcement scope (Art. 5(1)(e)) ─────────────────

    #[Test]
    #[TestDox('GIVEN a tenant with an UNKNOWN auto_delete type, WHEN enforcing, THEN it is reported as "no enforcer", never silently dropped')]
    public function retentionReportsNoEnforcerForUnknownType(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $tenant->method('getDataRetentionPolicies')->willReturn([
            'mystery' => ['retention_days' => 30, 'auto_delete' => true],
        ]);

        $tenantRepo = $this->createMock(TenantRepository::class);
        $tenantRepo->method('findAll')->willReturn([$tenant]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('createQueryBuilder');

        $report = (new RetentionEnforcementService($em, $tenantRepo, $this->createMock(AuditLogger::class), $this->createMock(LoggerInterface::class)))
            ->enforce(false);

        self::assertSame('no enforcer (manual cleanup required)', $report[0]['note']);
    }

    // ── M-6 / M-8 / N-7 / status (#875) — ProcessingActivity validation ──

    #[Test]
    #[TestDox('GIVEN a PA processing criminal data WITHOUT legal basis, THEN there is a criminalDataLegalBasis violation (Art. 10)')]
    public function criminalDataWithoutLegalBasisViolates(): void
    {
        $pa = (new ProcessingActivity())->setProcessesCriminalData(true);
        self::assertContains('criminalDataLegalBasis', $this->violationPaths($pa));
    }

    #[Test]
    #[TestDox('GIVEN a PA with NO recipient categories, THEN there is a recipientCategories violation (Art. 30(1)(d))')]
    public function emptyRecipientCategoriesViolates(): void
    {
        $pa = new ProcessingActivity(); // recipientCategories null by default
        self::assertContains('recipientCategories', $this->violationPaths($pa));
    }

    #[Test]
    #[TestDox('GIVEN a PA flagged as processor WITHOUT a client controller, THEN there is a processorClientController violation (Art. 30(2))')]
    public function processorWithoutClientViolates(): void
    {
        $pa = (new ProcessingActivity())->setIsProcessor(true);
        self::assertContains('processorClientController', $this->violationPaths($pa));
    }

    #[Test]
    #[TestDox('GIVEN a PA with the default status "draft" (the #875 bug), THEN there is NO status violation — edits work')]
    public function defaultStatusDraftHasNoViolation(): void
    {
        $pa = new ProcessingActivity();
        self::assertNotContains('status', $this->violationPaths($pa), 'status=draft must be a valid choice (regression #875)');
    }
}
