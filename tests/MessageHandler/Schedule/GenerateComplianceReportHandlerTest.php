<?php

namespace App\Tests\MessageHandler\Schedule;

use App\Message\Schedule\GenerateComplianceReportMessage;
use App\MessageHandler\Schedule\GenerateComplianceReportHandler;
use App\Repository\ComplianceRequirementRepository;
use App\Service\EmailNotificationService;
use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GenerateComplianceReportHandlerTest extends TestCase
{
    private GenerateComplianceReportHandler $handler;
    private ComplianceRequirementRepository $requirementRepository;
    private EmailNotificationService $emailService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new GenerateComplianceReportHandler(
            $this->requirementRepository,
            $this->emailService,
            $this->logger
        );
    }

    public function testInvokeGeneratesReportWithCompliantRequirements(): void
    {
        $message = new GenerateComplianceReportMessage(new DateTimeImmutable());

        $this->requirementRepository->expects($this->once())
            ->method('count')
            ->with([])
            ->willReturn(100);

        $this->requirementRepository->expects($this->once())
            ->method('countCompliant')
            ->willReturn(85);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        ($this->handler)($message);
    }

    public function testInvokeHandlesZeroRequirements(): void
    {
        $message = new GenerateComplianceReportMessage(new DateTimeImmutable());

        $this->requirementRepository->expects($this->once())
            ->method('count')
            ->with([])
            ->willReturn(0);

        $this->requirementRepository->expects($this->once())
            ->method('countCompliant')
            ->willReturn(0);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        ($this->handler)($message);
    }

    public function testInvokeLogsErrorOnException(): void
    {
        $message = new GenerateComplianceReportMessage(new DateTimeImmutable());

        $this->requirementRepository->expects($this->once())
            ->method('count')
            ->willThrowException(new Exception('Database error'));

        $this->logger->expects($this->once())
            ->method('error');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database error');

        ($this->handler)($message);
    }

    public function testInvokeCalculates100PercentCompliance(): void
    {
        $message = new GenerateComplianceReportMessage(new DateTimeImmutable());

        $this->requirementRepository->expects($this->once())
            ->method('count')
            ->with([])
            ->willReturn(50);

        $this->requirementRepository->expects($this->once())
            ->method('countCompliant')
            ->willReturn(50);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        ($this->handler)($message);
    }
}
