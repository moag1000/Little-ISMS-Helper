<?php

namespace App\Tests\Entity;

use App\Entity\AuditChecklist;
use App\Entity\ComplianceRequirement;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

class AuditChecklistTest extends TestCase
{
    public function testConstructor(): void
    {
        $checklist = new AuditChecklist();

        $this->assertNotNull($checklist->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $checklist->getCreatedAt());
        $this->assertNull($checklist->getUpdatedAt());
        $this->assertEquals('not_checked', $checklist->getVerificationStatus());
        $this->assertEquals(0, $checklist->getComplianceScore());
    }

    public function testAuditRelationship(): void
    {
        $checklist = new AuditChecklist();
        $audit = new InternalAudit();

        $this->assertNull($checklist->getAudit());

        $checklist->setAudit($audit);
        $this->assertSame($audit, $checklist->getAudit());

        $checklist->setAudit(null);
        $this->assertNull($checklist->getAudit());
    }

    public function testRequirementRelationship(): void
    {
        $checklist = new AuditChecklist();
        $requirement = new ComplianceRequirement();

        $this->assertNull($checklist->getRequirement());

        $checklist->setRequirement($requirement);
        $this->assertSame($requirement, $checklist->getRequirement());

        $checklist->setRequirement(null);
        $this->assertNull($checklist->getRequirement());
    }

    public function testVerificationStatus(): void
    {
        $checklist = new AuditChecklist();

        $this->assertEquals('not_checked', $checklist->getVerificationStatus());

        $checklist->setVerificationStatus('compliant');
        $this->assertEquals('compliant', $checklist->getVerificationStatus());

        $checklist->setVerificationStatus('partial');
        $this->assertEquals('partial', $checklist->getVerificationStatus());

        $checklist->setVerificationStatus('non_compliant');
        $this->assertEquals('non_compliant', $checklist->getVerificationStatus());

        $checklist->setVerificationStatus('not_applicable');
        $this->assertEquals('not_applicable', $checklist->getVerificationStatus());

        $checklist->setVerificationStatus('not_checked');
        $this->assertEquals('not_checked', $checklist->getVerificationStatus());
    }

    public function testAuditNotes(): void
    {
        $checklist = new AuditChecklist();

        $this->assertNull($checklist->getAuditNotes());

        $checklist->setAuditNotes('This requirement is fully implemented');
        $this->assertEquals('This requirement is fully implemented', $checklist->getAuditNotes());

        $checklist->setAuditNotes(null);
        $this->assertNull($checklist->getAuditNotes());
    }

    public function testEvidenceFound(): void
    {
        $checklist = new AuditChecklist();

        $this->assertNull($checklist->getEvidenceFound());

        $checklist->setEvidenceFound('Policy documents, training records');
        $this->assertEquals('Policy documents, training records', $checklist->getEvidenceFound());

        $checklist->setEvidenceFound(null);
        $this->assertNull($checklist->getEvidenceFound());
    }

    public function testFindings(): void
    {
        $checklist = new AuditChecklist();

        $this->assertNull($checklist->getFindings());

        $checklist->setFindings('Missing documentation for quarterly reviews');
        $this->assertEquals('Missing documentation for quarterly reviews', $checklist->getFindings());

        $checklist->setFindings(null);
        $this->assertNull($checklist->getFindings());
    }

    public function testRecommendations(): void
    {
        $checklist = new AuditChecklist();

        $this->assertNull($checklist->getRecommendations());

        $checklist->setRecommendations('Implement quarterly review process');
        $this->assertEquals('Implement quarterly review process', $checklist->getRecommendations());

        $checklist->setRecommendations(null);
        $this->assertNull($checklist->getRecommendations());
    }

    public function testComplianceScore(): void
    {
        $checklist = new AuditChecklist();

        $this->assertEquals(0, $checklist->getComplianceScore());

        $checklist->setComplianceScore(75);
        $this->assertEquals(75, $checklist->getComplianceScore());
    }

    public function testComplianceScoreClampingMin(): void
    {
        $checklist = new AuditChecklist();

        $checklist->setComplianceScore(-10);
        $this->assertEquals(0, $checklist->getComplianceScore());
    }

    public function testComplianceScoreClampingMax(): void
    {
        $checklist = new AuditChecklist();

        $checklist->setComplianceScore(150);
        $this->assertEquals(100, $checklist->getComplianceScore());
    }

    public function testAuditor(): void
    {
        $checklist = new AuditChecklist();

        $this->assertNull($checklist->getAuditor());

        $checklist->setAuditor('John Doe');
        $this->assertEquals('John Doe', $checklist->getAuditor());

        $checklist->setAuditor(null);
        $this->assertNull($checklist->getAuditor());
    }

    public function testVerifiedAt(): void
    {
        $checklist = new AuditChecklist();

        $this->assertNull($checklist->getVerifiedAt());

        $verifiedDate = new \DateTime('2024-06-15 14:30:00');
        $checklist->setVerifiedAt($verifiedDate);
        $this->assertEquals($verifiedDate, $checklist->getVerifiedAt());

        $checklist->setVerifiedAt(null);
        $this->assertNull($checklist->getVerifiedAt());
    }

    public function testTimestamps(): void
    {
        $checklist = new AuditChecklist();

        // createdAt set in constructor
        $this->assertNotNull($checklist->getCreatedAt());

        // updatedAt initially null
        $this->assertNull($checklist->getUpdatedAt());

        $now = new \DateTime();
        $checklist->setUpdatedAt($now);
        $this->assertEquals($now, $checklist->getUpdatedAt());

        $checklist->setUpdatedAt(null);
        $this->assertNull($checklist->getUpdatedAt());
    }

    public function testTenantRelationship(): void
    {
        $checklist = new AuditChecklist();
        $tenant = new Tenant();

        $this->assertNull($checklist->getTenant());

        $checklist->setTenant($tenant);
        $this->assertSame($tenant, $checklist->getTenant());

        $checklist->setTenant(null);
        $this->assertNull($checklist->getTenant());
    }

    public function testGetStatusBadgeClassCompliant(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('compliant');

        $this->assertEquals('success', $checklist->getStatusBadgeClass());
    }

    public function testGetStatusBadgeClassPartial(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('partial');

        $this->assertEquals('warning', $checklist->getStatusBadgeClass());
    }

    public function testGetStatusBadgeClassNonCompliant(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('non_compliant');

        $this->assertEquals('danger', $checklist->getStatusBadgeClass());
    }

    public function testGetStatusBadgeClassNotApplicable(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('not_applicable');

        $this->assertEquals('secondary', $checklist->getStatusBadgeClass());
    }

    public function testGetStatusBadgeClassNotChecked(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('not_checked');

        $this->assertEquals('info', $checklist->getStatusBadgeClass());
    }

    public function testGetStatusBadgeClassUnknown(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('unknown_status');

        $this->assertEquals('secondary', $checklist->getStatusBadgeClass());
    }

    public function testGetStatusLabelCompliant(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('compliant');

        $this->assertEquals('Konform', $checklist->getStatusLabel());
    }

    public function testGetStatusLabelPartial(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('partial');

        $this->assertEquals('Teilweise konform', $checklist->getStatusLabel());
    }

    public function testGetStatusLabelNonCompliant(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('non_compliant');

        $this->assertEquals('Nicht konform', $checklist->getStatusLabel());
    }

    public function testGetStatusLabelNotApplicable(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('not_applicable');

        $this->assertEquals('Nicht anwendbar', $checklist->getStatusLabel());
    }

    public function testGetStatusLabelNotChecked(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('not_checked');

        $this->assertEquals('Nicht geprüft', $checklist->getStatusLabel());
    }

    public function testGetStatusLabelUnknown(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerificationStatus('unknown_status');

        $this->assertEquals('Unbekannt', $checklist->getStatusLabel());
    }

    public function testMarkAsVerified(): void
    {
        $checklist = new AuditChecklist();

        $this->assertNull($checklist->getAuditor());
        $this->assertNull($checklist->getVerifiedAt());

        $result = $checklist->markAsVerified('Jane Smith');

        $this->assertSame($checklist, $result);
        $this->assertEquals('Jane Smith', $checklist->getAuditor());
        $this->assertNotNull($checklist->getVerifiedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $checklist->getVerifiedAt());
    }

    public function testIsVerifiedWhenNotVerified(): void
    {
        $checklist = new AuditChecklist();

        $this->assertFalse($checklist->isVerified());
    }

    public function testIsVerifiedWhenVerified(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setVerifiedAt(new \DateTime());

        $this->assertTrue($checklist->isVerified());
    }

    public function testHasFindingsWhenEmpty(): void
    {
        $checklist = new AuditChecklist();

        $this->assertFalse($checklist->hasFindings());
    }

    public function testHasFindingsWhenSet(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setFindings('Some findings');

        $this->assertTrue($checklist->hasFindings());
    }

    public function testHasFindingsWhenNull(): void
    {
        $checklist = new AuditChecklist();
        $checklist->setFindings(null);

        $this->assertFalse($checklist->hasFindings());
    }

    public function testFluentSetters(): void
    {
        $checklist = new AuditChecklist();
        $audit = new InternalAudit();
        $requirement = new ComplianceRequirement();
        $tenant = new Tenant();

        $result = $checklist
            ->setAudit($audit)
            ->setRequirement($requirement)
            ->setVerificationStatus('compliant')
            ->setAuditNotes('Notes')
            ->setComplianceScore(90)
            ->setAuditor('John Doe')
            ->setTenant($tenant);

        $this->assertSame($checklist, $result);
        $this->assertSame($audit, $checklist->getAudit());
        $this->assertSame($requirement, $checklist->getRequirement());
        $this->assertEquals('compliant', $checklist->getVerificationStatus());
        $this->assertEquals('Notes', $checklist->getAuditNotes());
        $this->assertEquals(90, $checklist->getComplianceScore());
        $this->assertEquals('John Doe', $checklist->getAuditor());
        $this->assertSame($tenant, $checklist->getTenant());
    }
}
