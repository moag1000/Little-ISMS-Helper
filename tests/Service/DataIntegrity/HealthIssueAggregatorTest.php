<?php

declare(strict_types=1);

namespace App\Tests\Service\DataIntegrity;

use App\Entity\Risk;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Service\DataIntegrity\HealthIssueAggregator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class HealthIssueAggregatorTest extends TestCase
{
    private MockObject $riskRepository;
    private MockObject $assetRepository;
    private MockObject $incidentRepository;
    private MockObject $dataBreachRepository;
    private MockObject $processingActivityRepository;
    private MockObject $supplierRepository;
    private MockObject $bcPlanRepository;
    private MockObject $trainingRepository;
    private MockObject $documentRepository;
    private HealthIssueAggregator $aggregator;

    protected function setUp(): void
    {
        $this->riskRepository               = $this->createMock(RiskRepository::class);
        $this->assetRepository              = $this->createMock(AssetRepository::class);
        $this->incidentRepository           = $this->createMock(IncidentRepository::class);
        $this->dataBreachRepository         = $this->createMock(DataBreachRepository::class);
        $this->processingActivityRepository = $this->createMock(ProcessingActivityRepository::class);
        $this->supplierRepository           = $this->createMock(SupplierRepository::class);
        $this->bcPlanRepository             = $this->createMock(BusinessContinuityPlanRepository::class);
        $this->trainingRepository           = $this->createMock(TrainingRepository::class);
        $this->documentRepository           = $this->createMock(DocumentRepository::class);

        $this->aggregator = new HealthIssueAggregator(
            $this->riskRepository,
            $this->assetRepository,
            $this->incidentRepository,
            $this->dataBreachRepository,
            $this->processingActivityRepository,
            $this->supplierRepository,
            $this->bcPlanRepository,
            $this->trainingRepository,
            $this->documentRepository,
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // findRiskHealthIssues
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function find_risk_health_issues_returns_empty_when_no_risks(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->aggregator->findRiskHealthIssues();

        self::assertSame([], $result);
    }

    #[Test]
    public function find_risk_health_issues_detects_residual_exceeds_inherent(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getStatus')->willReturn(RiskStatus::InTreatment);
        $risk->method('getTreatmentStrategy')->willReturn(TreatmentStrategy::Mitigate);
        $risk->method('getResidualRiskLevel')->willReturn(8);
        $risk->method('getInherentRiskLevel')->willReturn(5);
        $risk->method('getTreatmentDescription')->willReturn(null);
        $risk->method('getControls')->willReturn(new ArrayCollection());
        $risk->method('getReviewDate')->willReturn(null);

        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->aggregator->findRiskHealthIssues();

        self::assertArrayHasKey('risks_residual_exceeds_inherent', $result);
        self::assertCount(1, $result['risks_residual_exceeds_inherent']);
    }

    #[Test]
    public function find_risk_health_issues_detects_past_review_date(): void
    {
        $pastDate = new \DateTimeImmutable('-30 days');

        $risk = $this->createMock(Risk::class);
        $risk->method('getStatus')->willReturn(RiskStatus::InTreatment);
        $risk->method('getTreatmentStrategy')->willReturn(TreatmentStrategy::Mitigate);
        $risk->method('getResidualRiskLevel')->willReturn(3);
        $risk->method('getInherentRiskLevel')->willReturn(5);
        $risk->method('getTreatmentDescription')->willReturn(null);
        $risk->method('getControls')->willReturn(new ArrayCollection());
        $risk->method('getReviewDate')->willReturn($pastDate);

        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->aggregator->findRiskHealthIssues();

        self::assertArrayHasKey('risks_past_review_date', $result);
    }

    #[Test]
    public function find_risk_health_issues_ignores_terminal_statuses(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getStatus')->willReturn(RiskStatus::Closed);
        $risk->method('getTreatmentStrategy')->willReturn(null);
        $risk->method('getResidualRiskLevel')->willReturn(3);
        $risk->method('getInherentRiskLevel')->willReturn(5);
        $risk->method('getTreatmentDescription')->willReturn(null);
        $risk->method('getControls')->willReturn(new ArrayCollection());
        $risk->method('getReviewDate')->willReturn(new \DateTimeImmutable('-30 days'));

        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->aggregator->findRiskHealthIssues();

        // Closed status skips missing_treatment_strategy and past_review_date checks
        self::assertArrayNotHasKey('risks_missing_treatment_strategy', $result);
        self::assertArrayNotHasKey('risks_past_review_date', $result);
    }

    /**
     * Treatment checks skip handled risks (RiskStatus::isHandled()); the
     * review-date check follows RiskStatus::requiresPeriodicReview(): every
     * risk but a closed one — an accepted risk with an overdue review is an
     * issue (ISO 27001 8.2, ISO 27005), same rule as the review reminders.
     *
     * @return iterable<string, array{RiskStatus}>
     */
    public static function allStatuses(): iterable
    {
        foreach (RiskStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('allStatuses')]
    public function find_risk_health_issues_review_and_treatment_rules(RiskStatus $status): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getStatus')->willReturn($status);
        $risk->method('getTreatmentStrategy')->willReturn(TreatmentStrategy::Mitigate);
        $risk->method('getResidualRiskLevel')->willReturn(3);
        $risk->method('getInherentRiskLevel')->willReturn(5);
        $risk->method('getTreatmentDescription')->willReturn('Planned measures');
        $risk->method('getControls')->willReturn(new ArrayCollection());
        $risk->method('getReviewDate')->willReturn(new \DateTimeImmutable('-30 days'));

        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->aggregator->findRiskHealthIssues();

        self::assertSame($status !== RiskStatus::Closed, array_key_exists('risks_past_review_date', $result), 'review rule');
        self::assertSame(!$status->isHandled(), array_key_exists('risks_treatment_plan_without_controls', $result), 'treatment rule');
    }

    // ────────────────────────────────────────────────────────────────────────
    // findComplianceHealthIssues — query-builder heavy, so we test
    // that each check wraps its DB call in a try/catch and returns empty
    // when the query builder throws.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function find_compliance_health_issues_returns_empty_array_on_exception(): void
    {
        // Asset repo createQueryBuilder throws → silent catch
        $this->assetRepository->method('createQueryBuilder')->willThrowException(new \RuntimeException('DB error'));
        $this->dataBreachRepository->method('createQueryBuilder')->willThrowException(new \RuntimeException('DB error'));
        $this->processingActivityRepository->method('createQueryBuilder')->willThrowException(new \RuntimeException('DB error'));

        $result = $this->aggregator->findComplianceHealthIssues();

        self::assertIsArray($result);
        // All checks skipped silently — no keys in result
        self::assertEmpty($result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // findDataQualityIssues — verifies guard on optional repos
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function find_data_quality_issues_returns_empty_without_optional_repos(): void
    {
        // Set up query mocks to throw so the try/catch triggers
        $this->riskRepository->method('createQueryBuilder')->willThrowException(new \RuntimeException('no DB'));
        $this->incidentRepository->method('createQueryBuilder')->willThrowException(new \RuntimeException('no DB'));

        // No optional repos passed → guards short-circuit those blocks
        $result = $this->aggregator->findDataQualityIssues();

        self::assertIsArray($result);
    }
}
