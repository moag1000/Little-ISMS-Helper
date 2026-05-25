<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AuditFinding;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S17 B4 — Smoke tests for Hybrid JSON Nonconformity-Details on AuditFinding.
 * Covers field round-trip (getters/setters) and the isNonconformity() helper.
 */
final class AuditFindingTest extends TestCase
{
    #[Test]
    public function isNonconformityReturnsTrueForMajorAndMinorNc(): void
    {
        $finding = new AuditFinding();
        $finding->setType(AuditFinding::TYPE_MAJOR_NC);
        self::assertTrue($finding->isNonconformity());

        $finding->setType(AuditFinding::TYPE_MINOR_NC);
        self::assertTrue($finding->isNonconformity());
    }

    #[Test]
    public function isNonconformityReturnsFalseForObservationAndOpportunity(): void
    {
        $finding = new AuditFinding();
        $finding->setType(AuditFinding::TYPE_OBSERVATION);
        self::assertFalse($finding->isNonconformity());

        $finding->setType(AuditFinding::TYPE_OPPORTUNITY);
        self::assertFalse($finding->isNonconformity());
    }

    #[Test]
    public function ncFieldsAreNullByDefault(): void
    {
        $finding = new AuditFinding();
        self::assertNull($finding->getNcRootCauseSummary());
        self::assertNull($finding->getNcCorrectionDueDate());
        self::assertNull($finding->getNcVerifiedAt());
        self::assertNull($finding->getNcVerifiedBy());
        self::assertNull($finding->getNonconformityDetails());
    }

    #[Test]
    public function ncFieldsRoundTrip(): void
    {
        $finding = new AuditFinding();
        $verifier = new User();
        $dueDate = new DateTimeImmutable('2026-07-01');
        $verifiedAt = new DateTimeImmutable('2026-08-15 10:30:00');

        $finding
            ->setType(AuditFinding::TYPE_MAJOR_NC)
            ->setNcRootCauseSummary('5-Why pointed at missing review gate.')
            ->setNcCorrectionDueDate($dueDate)
            ->setNcVerifiedAt($verifiedAt)
            ->setNcVerifiedBy($verifier);

        self::assertSame('5-Why pointed at missing review gate.', $finding->getNcRootCauseSummary());
        self::assertSame($dueDate, $finding->getNcCorrectionDueDate());
        self::assertSame($verifiedAt, $finding->getNcVerifiedAt());
        self::assertSame($verifier, $finding->getNcVerifiedBy());
    }

    #[Test]
    public function nonconformityDetailsJsonRoundTrip(): void
    {
        $finding = new AuditFinding();
        $payload = [
            'rootCauseAnalysisMethod' => AuditFinding::RCA_METHOD_5_WHY,
            'correctiveActions' => [
                ['description' => 'Add CI gate', 'owner_id' => 42, 'deadline' => '2026-07-01'],
            ],
            'verificationMethod' => AuditFinding::VERIFICATION_DOCUMENT_REVIEW,
            'verificationEvidence' => 'Reviewed gate config in pipeline.yaml',
        ];

        $finding->setNonconformityDetails($payload);

        self::assertSame($payload, $finding->getNonconformityDetails());
        self::assertSame('5-why', $finding->getNonconformityDetails()['rootCauseAnalysisMethod']);
    }

    #[Test]
    public function rcaMethodConstantsAreStable(): void
    {
        // Constants are referenced from migrations + AlvaHint rules — pin them
        // so a rename triggers a test failure.
        self::assertSame('5-why', AuditFinding::RCA_METHOD_5_WHY);
        self::assertSame('ishikawa', AuditFinding::RCA_METHOD_ISHIKAWA);
        self::assertSame('fmea', AuditFinding::RCA_METHOD_FMEA);
        self::assertSame('other', AuditFinding::RCA_METHOD_OTHER);

        self::assertSame('document-review', AuditFinding::VERIFICATION_DOCUMENT_REVIEW);
        self::assertSame('walkthrough', AuditFinding::VERIFICATION_WALKTHROUGH);
        self::assertSame('test', AuditFinding::VERIFICATION_TEST);
        self::assertSame('metrics-monitoring', AuditFinding::VERIFICATION_METRICS_MONITORING);
    }
}
