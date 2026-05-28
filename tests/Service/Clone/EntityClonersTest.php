<?php

declare(strict_types=1);

namespace App\Tests\Service\Clone;

use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\InternalAudit;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Service\Clone\AssetCloner;
use App\Service\Clone\AuditFindingCloner;
use App\Service\Clone\BCExerciseCloner;
use App\Service\Clone\BusinessContinuityPlanCloner;
use App\Service\Clone\DocumentCloner;
use App\Service\Clone\RiskCloner;
use App\Service\Clone\SupplierCloner;
use App\Service\Clone\TrainingCloner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * C4-C1 — covers all 8 EntityCloner services. Each cloner must:
 *   - Copy template fields (title, description, configuration scaffolding)
 *   - Reset lifecycle status to the initial marking
 *   - Append " (Kopie)" to the title when no override given
 *   - Persist the clone via EntityManagerInterface
 *   - Reject mismatched input types with InvalidArgumentException
 */
final class EntityClonersTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects(self::any())->method('persist');
        $this->tenant = new Tenant();
    }

    // ─── Risk ────────────────────────────────────────────────────────────────

    #[Test]
    public function riskClonerCopiesTemplateFieldsAndResetsStatus(): void
    {
        $source = (new Risk())
            ->setTitle('Phishing → CRM')
            ->setTenant($this->tenant)
            ->setDescription('test desc')
            ->setThreat('phishing')
            ->setProbability(4)
            ->setImpact(5)
            ->setResidualProbability(2)
            ->setResidualImpact(2)
            ->setTreatmentStrategy(TreatmentStrategy::Mitigate)
            ->setTreatmentDescription('train staff')
            ->setStatus(RiskStatus::Treated);

        $clone = (new RiskCloner($this->em))->clone($source);

        self::assertInstanceOf(Risk::class, $clone);
        self::assertSame('Phishing → CRM (Kopie)', $clone->getTitle());
        self::assertSame($this->tenant, $clone->getTenant());
        self::assertSame(4, $clone->getProbability());
        self::assertSame(5, $clone->getImpact());
        self::assertSame(1, $clone->getResidualProbability(), 'residual reset to 1');
        self::assertSame(1, $clone->getResidualImpact(), 'residual reset to 1');
        self::assertSame(TreatmentStrategy::Mitigate, $clone->getTreatmentStrategy());
        self::assertSame(RiskStatus::Identified, $clone->getStatus(), 'status reset to initial');
        self::assertNull($clone->getReviewDate());
    }

    #[Test]
    public function riskClonerRespectsTitleOverride(): void
    {
        $source = (new Risk())
            ->setTitle('Original')
            ->setProbability(1)->setImpact(1);

        $clone = (new RiskCloner($this->em))->clone($source, null, 'Custom Title');

        self::assertSame('Custom Title', $clone->getTitle());
    }

    #[Test]
    public function riskClonerRejectsWrongType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RiskCloner($this->em))->clone(new Asset());
    }

    // ─── Asset ───────────────────────────────────────────────────────────────

    #[Test]
    public function assetClonerCopiesCIAAndResetsStatus(): void
    {
        $source = (new Asset())
            ->setName('Production DB')
            ->setTenant($this->tenant)
            ->setAssetType('database')
            ->setOwner('IT-Team')
            ->setConfidentialityValue(5)
            ->setIntegrityValue(5)
            ->setAvailabilityValue(4)
            ->setAcquisitionValue('15000.00')
            ->setCurrentValue('8000.00')
            ->setStatus('retired')
            ->setIsDoraRelevant(true);

        $clone = (new AssetCloner($this->em))->clone($source);

        self::assertInstanceOf(Asset::class, $clone);
        self::assertSame('Production DB (Kopie)', $clone->getName());
        self::assertSame(5, $clone->getConfidentialityValue());
        self::assertSame('15000.00', $clone->getCurrentValue(), 'reset to acquisition baseline');
        self::assertSame('active', $clone->getStatus(), 'status reset to active');
        self::assertNull($clone->getReturnDate());
        self::assertTrue($clone->isDoraRelevant());
    }

    // ─── Document ────────────────────────────────────────────────────────────

    #[Test]
    public function documentClonerClearsBinaryAndResetsLifecycle(): void
    {
        $source = (new Document())
            ->setTenant($this->tenant)
            ->setOriginalFilename('ISMS-Policy.pdf')
            ->setFilename('uuid-abc.pdf')
            ->setFilePath('/var/docs/uuid-abc.pdf')
            ->setSha256Hash('deadbeef')
            ->setMimeType('application/pdf')
            ->setFileSize(123456)
            ->setPolicyBody('# Policy body markdown')
            ->setReviewIntervalMonths(12)
            ->setStatus('published')
            ->setIsImmutable(true);

        $clone = (new DocumentCloner($this->em))->clone($source);

        self::assertSame('ISMS-Policy.pdf (Kopie)', $clone->getOriginalFilename());
        self::assertSame('# Policy body markdown', $clone->getPolicyBody());
        self::assertSame(12, $clone->getReviewIntervalMonths());
        self::assertSame('draft', $clone->getStatus(), 'lifecycle reset to draft');
        self::assertFalse($clone->isImmutable(), 'clone starts editable');
        // filename + filePath are NOT NULL on the document table — cloner
        // mirrors the source pointers until the user re-uploads. Hash is
        // still cleared so the next save recomputes integrity metadata.
        self::assertSame('uuid-abc.pdf', $clone->getFilename(), 'pointer mirrored — NOT NULL guard');
        self::assertSame('/var/docs/uuid-abc.pdf', $clone->getFilePath());
        self::assertNull($clone->getSha256Hash(), 'hash cleared — re-derived on next save');
        self::assertSame('1.0', $clone->getVersion());
    }

    // ─── Training ────────────────────────────────────────────────────────────

    #[Test]
    public function trainingClonerKeepsM2MCoverageAndResetsExecution(): void
    {
        $control = new Control();
        $req = new ComplianceRequirement();
        $req->setRequirementId('A.6.3');
        $req->setTitle('Awareness')->setDescription('test')->setPriority('high');

        $source = (new Training())
            ->setTitle('Annual Awareness Training')
            ->setTenant($this->tenant)
            ->setTrainingType('awareness')
            ->setTrainer('CISO')
            ->setDurationMinutes(60)
            ->setMandatory(true)
            ->setStatus('completed')
            ->setAttendeeCount(42)
            ->setFeedback('Great session')
            ->setRecurrenceMonths(12);
        $source->addCoveredControl($control);
        $source->addComplianceRequirement($req);

        $clone = (new TrainingCloner($this->em))->clone($source);

        self::assertSame('Annual Awareness Training (Kopie)', $clone->getTitle());
        self::assertSame('CISO', $clone->getTrainer());
        self::assertSame(60, $clone->getDurationMinutes());
        self::assertTrue($clone->isMandatory());
        self::assertSame(12, $clone->getRecurrenceMonths());
        self::assertSame('planned', $clone->getStatus(), 'status reset to planned');
        // scheduledDate is NOT NULL on the training table — cloner mirrors
        // the source value forward so the row is persistable; user re-plans
        // via the edit form. completionDate is nullable → cleared.
        self::assertNotNull($clone->getScheduledDate(), 'date mirrored — NOT NULL guard');
        self::assertNull($clone->getCompletionDate());
        self::assertSame(0, $clone->getAttendeeCount());
        self::assertNull($clone->getFeedback());
        self::assertCount(1, $clone->getCoveredControls());
        self::assertCount(1, $clone->getComplianceRequirements());
    }

    // ─── Supplier ────────────────────────────────────────────────────────────

    #[Test]
    public function supplierClonerKeepsCertificationsAndResetsEvaluation(): void
    {
        $source = (new Supplier())
            ->setName('CloudCo')
            ->setTenant($this->tenant)
            ->setServiceProvided('SaaS hosting')
            ->setCriticality('high')
            ->setStatus('active')
            ->setSecurityScore(85)
            ->setAssessmentFindings('No major findings')
            ->setHasISO27001(true)
            ->setHasDPA(true)
            ->setIsDoraRelevant(true);

        $clone = (new SupplierCloner($this->em))->clone($source);

        self::assertSame('CloudCo (Kopie)', $clone->getName());
        self::assertSame('SaaS hosting', $clone->getServiceProvided());
        self::assertSame('high', $clone->getCriticality());
        self::assertSame('evaluation', $clone->getStatus(), 'status reset to evaluation');
        self::assertNull($clone->getSecurityScore());
        self::assertNull($clone->getAssessmentFindings());
        self::assertNull($clone->getContractStartDate());
        self::assertTrue($clone->isHasISO27001());
        self::assertTrue($clone->isHasDPA());
    }

    // ─── BCPlan ──────────────────────────────────────────────────────────────

    #[Test]
    public function bcPlanClonerKeepsOperationalTemplateAndResetsCadence(): void
    {
        $source = (new BusinessContinuityPlan())
            ->setName('Datacenter Failover Plan')
            ->setTenant($this->tenant)
            ->setRecoveryProcedures('Step 1: switch DNS …')
            ->setRto(240)
            ->setRpo(60)
            ->setResponseTeamMembers([['role' => 'CISO']])
            ->setStatus('active')
            ->setVersion('2.3');

        $clone = (new BusinessContinuityPlanCloner($this->em))->clone($source);

        self::assertSame('Datacenter Failover Plan (Kopie)', $clone->getName());
        self::assertSame(240, $clone->getRto());
        self::assertSame(60, $clone->getRpo());
        self::assertSame([['role' => 'CISO']], $clone->getResponseTeamMembers());
        self::assertSame('draft', $clone->getStatus(), 'status reset to draft');
        self::assertSame('1.0', $clone->getVersion(), 'version reset');
        self::assertNull($clone->getLastTested());
        self::assertNull($clone->getNextTestDate());
    }

    // ─── BCExercise ─────────────────────────────────────────────────────────

    #[Test]
    public function bcExerciseClonerKeepsScenarioAndResetsExecution(): void
    {
        $source = (new BCExercise())
            ->setName('Q1 Tabletop')
            ->setTenant($this->tenant)
            ->setExerciseType('tabletop')
            ->setScope('IT-Ops')
            ->setObjectives('Validate DR failover')
            ->setScenario('Ransomware on file-server')
            ->setDurationHours(4)
            ->setFacilitator('External consultant')
            ->setStatus('completed')
            ->setResults('All steps passed')
            ->setSuccessRating(85)
            ->setReportCompleted(true);

        $clone = (new BCExerciseCloner($this->em))->clone($source);

        self::assertSame('Q1 Tabletop (Kopie)', $clone->getName());
        self::assertSame('tabletop', $clone->getExerciseType());
        self::assertSame('Ransomware on file-server', $clone->getScenario());
        self::assertSame(4, $clone->getDurationHours());
        self::assertSame('External consultant', $clone->getFacilitator());
        self::assertSame('planned', $clone->getStatus(), 'status reset to planned');
        self::assertNull($clone->getExerciseDate());
        self::assertNull($clone->getResults());
        self::assertNull($clone->getSuccessRating());
        self::assertFalse($clone->isReportCompleted());
    }

    // ─── AuditFinding ───────────────────────────────────────────────────────

    #[Test]
    public function auditFindingClonerKeepsTemplateAndClearsNcVerification(): void
    {
        $audit = new InternalAudit();
        $source = (new AuditFinding())
            ->setTitle('Access control gap')
            ->setTenant($this->tenant)
            ->setAudit($audit)
            ->setDescription('Privilege not revoked on offboarding')
            ->setType(AuditFinding::TYPE_MINOR_NC)
            ->setSeverity(AuditFinding::SEVERITY_HIGH)
            ->setClauseReference('A.5.18')
            ->setEvidence('email-log-2026-04.txt')
            ->setFindingNumber('F-2026-001')
            ->setStatus('closed')
            ->setNcRootCauseSummary('forgotten step in offboarding playbook');

        $clone = (new AuditFindingCloner($this->em))->clone($source);

        self::assertSame('Access control gap (Kopie)', $clone->getTitle());
        self::assertSame(AuditFinding::TYPE_MINOR_NC, $clone->getType());
        self::assertSame(AuditFinding::SEVERITY_HIGH, $clone->getSeverity());
        self::assertSame('A.5.18', $clone->getClauseReference());
        self::assertSame($audit, $clone->getAudit(), 'audit ref preserved');
        self::assertSame('open', $clone->getStatus(), 'status reset to open');
        self::assertNull($clone->getFindingNumber(), 'finding number cleared (auto-generated)');
        self::assertNull($clone->getEvidence(), 'evidence cleared');
        self::assertNull($clone->getDueDate());
        self::assertNull($clone->getClosedAt());
        self::assertNull($clone->getNcRootCauseSummary());
        self::assertNull($clone->getNcVerifiedAt());
    }
}
