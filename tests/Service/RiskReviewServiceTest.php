<?php

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\RiskRepository;
use App\Service\RiskReviewService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RiskReviewServiceTest extends TestCase
{
    private MockObject $riskRepository;
    private MockObject $entityManager;
    private MockObject $logger;
    private RiskReviewService $service;

    protected function setUp(): void
    {
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RiskReviewService(
            $this->riskRepository,
            $this->entityManager,
            $this->logger
        );
    }

    public function testGetReviewScheduleReturnsCorrectIntervals(): void
    {
        $schedule = $this->service->getReviewSchedule();

        $this->assertArrayHasKey('critical', $schedule);
        $this->assertArrayHasKey('high', $schedule);
        $this->assertArrayHasKey('medium', $schedule);
        $this->assertArrayHasKey('low', $schedule);

        $this->assertSame(90, $schedule['critical']);
        $this->assertSame(180, $schedule['high']);
        $this->assertSame(365, $schedule['medium']);
        $this->assertSame(730, $schedule['low']);
    }

    /**
     * Note: Tests for getOverdueReviews and getUpcomingReviews require complex
     * query builder mocking with final Query class which is not suitable for
     * unit tests. These are better covered by integration tests.
     */

    public function testScheduleNextReviewForCriticalRisk(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getTitle')->willReturn('Critical Risk');
        $risk->method('getProbability')->willReturn(5);
        $risk->method('getImpact')->willReturn(5); // Score 25 = critical

        $risk->expects($this->once())
            ->method('setReviewDate')
            ->with($this->callback(function ($date) {
                $expectedDate = (new DateTime())->modify('+90 days');
                return abs($date->getTimestamp() - $expectedDate->getTimestamp()) < 86400;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->scheduleNextReview($risk);

        $this->assertInstanceOf(\DateTimeInterface::class, $result);
    }

    public function testScheduleNextReviewForHighRisk(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getTitle')->willReturn('High Risk');
        $risk->method('getProbability')->willReturn(4);
        $risk->method('getImpact')->willReturn(4); // Score 16 = high

        $risk->expects($this->once())
            ->method('setReviewDate')
            ->with($this->callback(function ($date) {
                $expectedDate = (new DateTime())->modify('+180 days');
                return abs($date->getTimestamp() - $expectedDate->getTimestamp()) < 86400;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->scheduleNextReview($risk);

        $this->assertInstanceOf(\DateTimeInterface::class, $result);
    }

    public function testScheduleNextReviewForMediumRisk(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getTitle')->willReturn('Medium Risk');
        $risk->method('getProbability')->willReturn(3);
        $risk->method('getImpact')->willReturn(3); // Score 9 = medium

        $risk->expects($this->once())
            ->method('setReviewDate')
            ->with($this->callback(function ($date) {
                $expectedDate = (new DateTime())->modify('+365 days');
                return abs($date->getTimestamp() - $expectedDate->getTimestamp()) < 86400;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->scheduleNextReview($risk);

        $this->assertInstanceOf(\DateTimeInterface::class, $result);
    }

    public function testScheduleNextReviewForLowRisk(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getTitle')->willReturn('Low Risk');
        $risk->method('getProbability')->willReturn(1);
        $risk->method('getImpact')->willReturn(2); // Score 2 = low

        $risk->expects($this->once())
            ->method('setReviewDate')
            ->with($this->callback(function ($date) {
                $expectedDate = (new DateTime())->modify('+730 days');
                return abs($date->getTimestamp() - $expectedDate->getTimestamp()) < 86400;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->scheduleNextReview($risk);

        $this->assertInstanceOf(\DateTimeInterface::class, $result);
    }

    public function testScheduleNextReviewWithoutFlush(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getTitle')->willReturn('Test Risk');
        $risk->method('getProbability')->willReturn(2);
        $risk->method('getImpact')->willReturn(2);

        $risk->expects($this->once())->method('setReviewDate');
        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->service->scheduleNextReview($risk, false);

        $this->assertInstanceOf(\DateTimeInterface::class, $result);
    }

    /**
     * Note: Tests for getReviewStatistics and bulkScheduleReviews require complex
     * query builder mocking with final Query class which is not suitable for
     * unit tests. These are better covered by integration tests.
     */
}
