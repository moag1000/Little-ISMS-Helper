<?php

namespace App\Tests\MessageHandler\Schedule;

use App\Message\Schedule\CleanupExpiredSessionsMessage;
use App\MessageHandler\Schedule\CleanupExpiredSessionsHandler;
use App\Repository\UserSessionRepository;
use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupExpiredSessionsHandlerTest extends TestCase
{
    private CleanupExpiredSessionsHandler $handler;
    private UserSessionRepository $sessionRepository;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->sessionRepository = $this->createMock(UserSessionRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new CleanupExpiredSessionsHandler(
            $this->sessionRepository,
            $this->logger
        );
    }

    public function testInvokeCleanupsSessions(): void
    {
        $message = new CleanupExpiredSessionsMessage(new DateTimeImmutable());

        $this->sessionRepository->expects($this->once())
            ->method('cleanupExpiredSessions')
            ->willReturn(5);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        ($this->handler)($message);
    }

    public function testInvokeLogsErrorOnException(): void
    {
        $message = new CleanupExpiredSessionsMessage(new DateTimeImmutable());

        $this->sessionRepository->expects($this->once())
            ->method('cleanupExpiredSessions')
            ->willThrowException(new Exception('Database error'));

        $this->logger->expects($this->once())
            ->method('error');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database error');

        ($this->handler)($message);
    }

    public function testInvokeReturnsZeroCleanedSessions(): void
    {
        $message = new CleanupExpiredSessionsMessage(new DateTimeImmutable());

        $this->sessionRepository->expects($this->once())
            ->method('cleanupExpiredSessions')
            ->willReturn(0);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        ($this->handler)($message);
    }
}
