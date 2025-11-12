<?php

namespace App\Tests\Entity;

use App\Entity\ManagementReview;
use PHPUnit\Framework\TestCase;

class ManagementReviewTest extends TestCase
{
    public function testNewManagementReviewHasDefaultValues(): void
    {
        $review = new ManagementReview();

        $this->assertNull($review->getId());
        $this->assertNull($review->getTitle());
        $this->assertNull($review->getReviewDate());
        $this->assertNull($review->getParticipants());
        $this->assertNull($review->getChangesRelevantToISMS());
        $this->assertNull($review->getFeedbackFromInterestedParties());
        $this->assertNull($review->getAuditResults());
        $this->assertNull($review->getPerformanceEvaluation());
        $this->assertNull($review->getNonConformitiesStatus());
        $this->assertNull($review->getCorrectiveActionsStatus());
        $this->assertNull($review->getPreviousReviewActions());
        $this->assertNull($review->getOpportunitiesForImprovement());
        $this->assertNull($review->getResourceNeeds());
        $this->assertNull($review->getDecisions());
        $this->assertNull($review->getActionItems());
        $this->assertEquals('planned', $review->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $review->getCreatedAt());
        $this->assertNull($review->getUpdatedAt());
    }

    public function testSetAndGetTitle(): void
    {
        $review = new ManagementReview();
        $review->setTitle('Q4 2024 ISMS Management Review');

        $this->assertEquals('Q4 2024 ISMS Management Review', $review->getTitle());
    }

    public function testSetAndGetReviewDate(): void
    {
        $review = new ManagementReview();
        $date = new \DateTime('2024-12-15');

        $review->setReviewDate($date);

        $this->assertEquals($date, $review->getReviewDate());
    }

    public function testSetAndGetParticipants(): void
    {
        $review = new ManagementReview();
        $participants = 'CEO, CIO, CISO, IT Manager, Compliance Officer';

        $review->setParticipants($participants);

        $this->assertEquals($participants, $review->getParticipants());
    }

    public function testSetAndGetChangesRelevantToISMS(): void
    {
        $review = new ManagementReview();
        $changes = 'New cloud infrastructure, updated DPA requirements, organizational restructuring';

        $review->setChangesRelevantToISMS($changes);

        $this->assertEquals($changes, $review->getChangesRelevantToISMS());
    }

    public function testSetAndGetFeedbackFromInterestedParties(): void
    {
        $review = new ManagementReview();
        $feedback = 'Customers request faster incident response. Employees need more security training.';

        $review->setFeedbackFromInterestedParties($feedback);

        $this->assertEquals($feedback, $review->getFeedbackFromInterestedParties());
    }

    public function testSetAndGetAuditResults(): void
    {
        $review = new ManagementReview();
        $results = 'Internal audit completed with 3 minor findings. All addressed within deadline.';

        $review->setAuditResults($results);

        $this->assertEquals($results, $review->getAuditResults());
    }

    public function testSetAndGetPerformanceEvaluation(): void
    {
        $review = new ManagementReview();
        $evaluation = 'Incident response improved by 25%. System availability at 99.8%.';

        $review->setPerformanceEvaluation($evaluation);

        $this->assertEquals($evaluation, $review->getPerformanceEvaluation());
    }

    public function testSetAndGetNonConformitiesStatus(): void
    {
        $review = new ManagementReview();
        $status = '2 open non-conformities from last review, both in progress';

        $review->setNonConformitiesStatus($status);

        $this->assertEquals($status, $review->getNonConformitiesStatus());
    }

    public function testSetAndGetCorrectiveActionsStatus(): void
    {
        $review = new ManagementReview();
        $status = '5 corrective actions completed, 2 in progress';

        $review->setCorrectiveActionsStatus($status);

        $this->assertEquals($status, $review->getCorrectiveActionsStatus());
    }

    public function testSetAndGetPreviousReviewActions(): void
    {
        $review = new ManagementReview();
        $actions = 'All action items from Q3 review completed on schedule';

        $review->setPreviousReviewActions($actions);

        $this->assertEquals($actions, $review->getPreviousReviewActions());
    }

    public function testSetAndGetOpportunitiesForImprovement(): void
    {
        $review = new ManagementReview();
        $opportunities = 'Implement automated vulnerability scanning, enhance backup procedures';

        $review->setOpportunitiesForImprovement($opportunities);

        $this->assertEquals($opportunities, $review->getOpportunitiesForImprovement());
    }

    public function testSetAndGetResourceNeeds(): void
    {
        $review = new ManagementReview();
        $needs = 'Additional security analyst position, budget for new SIEM tool';

        $review->setResourceNeeds($needs);

        $this->assertEquals($needs, $review->getResourceNeeds());
    }

    public function testSetAndGetDecisions(): void
    {
        $review = new ManagementReview();
        $decisions = 'Approve budget for SIEM, schedule security awareness training Q1 2025';

        $review->setDecisions($decisions);

        $this->assertEquals($decisions, $review->getDecisions());
    }

    public function testSetAndGetActionItems(): void
    {
        $review = new ManagementReview();
        $actions = '1. Procurement process for SIEM - IT Manager 2. Schedule training - HR Manager';

        $review->setActionItems($actions);

        $this->assertEquals($actions, $review->getActionItems());
    }

    public function testSetAndGetStatus(): void
    {
        $review = new ManagementReview();

        $review->setStatus('scheduled');
        $this->assertEquals('scheduled', $review->getStatus());

        $review->setStatus('completed');
        $this->assertEquals('completed', $review->getStatus());

        $review->setStatus('cancelled');
        $this->assertEquals('cancelled', $review->getStatus());
    }

    public function testSetAndGetCreatedAt(): void
    {
        $review = new ManagementReview();
        $date = new \DateTime('2024-01-01');

        $review->setCreatedAt($date);

        $this->assertEquals($date, $review->getCreatedAt());
    }

    public function testSetAndGetUpdatedAt(): void
    {
        $review = new ManagementReview();
        $date = new \DateTime('2024-06-15');

        $review->setUpdatedAt($date);

        $this->assertEquals($date, $review->getUpdatedAt());
    }

    public function testManagementReviewCanDocumentCompleteReview(): void
    {
        $review = new ManagementReview();

        $review->setTitle('Annual ISMS Management Review 2024');
        $review->setReviewDate(new \DateTime('2024-12-15'));
        $review->setParticipants('CEO, CIO, CISO, Compliance Team');
        $review->setChangesRelevantToISMS('New NIS2 requirements, cloud migration');
        $review->setAuditResults('All audits passed successfully');
        $review->setPerformanceEvaluation('KPIs met or exceeded');
        $review->setDecisions('Approve increased security budget for 2025');
        $review->setActionItems('Implement NIS2 controls by Q2 2025');
        $review->setStatus('completed');

        $this->assertEquals('Annual ISMS Management Review 2024', $review->getTitle());
        $this->assertNotNull($review->getReviewDate());
        $this->assertEquals('completed', $review->getStatus());
        $this->assertStringContainsString('NIS2', $review->getChangesRelevantToISMS());
        $this->assertStringContainsString('Approve', $review->getDecisions());
    }

    public function testManagementReviewStatusLifecycle(): void
    {
        $review = new ManagementReview();

        // Initial status
        $this->assertEquals('planned', $review->getStatus());

        // Progress through lifecycle
        $review->setStatus('scheduled');
        $this->assertEquals('scheduled', $review->getStatus());

        $review->setStatus('in_progress');
        $this->assertEquals('in_progress', $review->getStatus());

        $review->setStatus('completed');
        $this->assertEquals('completed', $review->getStatus());
    }
}
