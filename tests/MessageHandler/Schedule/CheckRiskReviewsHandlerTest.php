<?php

namespace App\Tests\MessageHandler\Schedule;

use App\Message\Schedule\CheckRiskReviewsMessage;
use App\MessageHandler\Schedule\CheckRiskReviewsHandler;
use App\Repository\RiskRepository;
use App\Service\EmailNotificationService;
use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CheckRiskReviewsHandler
 *
 * Note: The handler uses methods (sendEmail, getOwner, getNextReviewDate) that may not
 * be implemented yet in the target classes. These tests verify the handler's error handling
 * and repository interaction patterns.
 */
class CheckRiskReviewsHandlerTest extends TestCase
{
    private CheckRiskReviewsHandler $handler;
    private RiskRepository $riskRepository;
    private EmailNotificationService $emailService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new CheckRiskReviewsHandler(
            $this->riskRepository,
            $this->emailService,
            $this->logger
        );
    }

    public function testInvokeWithNoRisksDueForReview(): void
    {
        $message = new CheckRiskReviewsMessage(new DateTimeImmutable());

        $this->riskRepository->expects($this->once())
            ->method('findDueForReview')
            ->willReturn([]);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        ($this->handler)($message);
    }

    public function testInvokeLogsErrorOnRepositoryException(): void
    {
        $message = new CheckRiskReviewsMessage(new DateTimeImmutable());

        $this->riskRepository->expects($this->once())
            ->method('findDueForReview')
            ->willThrowException(new Exception('Database error'));

        $this->logger->expects($this->once())
            ->method('error');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database error');

        ($this->handler)($message);
    }

    public function testMessageCreation(): void
    {
        $scheduledAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $message = new CheckRiskReviewsMessage($scheduledAt);

        $this->assertSame($scheduledAt, $message->getScheduledAt());
    }

    public function testMessageDefaultScheduledAt(): void
    {
        $before = new DateTimeImmutable();
        $message = new CheckRiskReviewsMessage();
        $after = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $message->getScheduledAt());
        $this->assertLessThanOrEqual($after, $message->getScheduledAt());
    }
}
