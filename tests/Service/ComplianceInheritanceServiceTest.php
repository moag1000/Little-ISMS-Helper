<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\FulfillmentInheritanceLog;
use App\Entity\User;
use App\Service\ComplianceInheritanceService;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Minimal contract-level tests for the inheritance service guard clauses.
 * Full integration tests (E2E 27001 → NIS2) in tests/E2e once fixtures load.
 */
final class ComplianceInheritanceServiceTest extends TestCase
{
    public function testConfirmRejectsShortComment(): void
    {
        $service = $this->createPartialService();
        $log = $this->createMock(FulfillmentInheritanceLog::class);
        $log->method('isPendingReview')->willReturn(true);
        $log->method('getReviewStatus')->willReturn(FulfillmentInheritanceLog::STATUS_PENDING_REVIEW);

        $this->expectException(InvalidArgumentException::class);
        $service->confirmInheritance($log, $this->createMock(User::class), 'too short');
    }

    public function testConfirmRejectsOnTerminalStatus(): void
    {
        $service = $this->createPartialService();
        $log = $this->createMock(FulfillmentInheritanceLog::class);
        $log->method('isPendingReview')->willReturn(false);
        $log->method('getReviewStatus')->willReturn(FulfillmentInheritanceLog::STATUS_REJECTED);

        $this->expectException(LogicException::class);
        $service->confirmInheritance(
            $log,
            $this->createMock(User::class),
            'This comment is certainly long enough to pass the 20-char guard clause.',
        );
    }

    public function testOverrideRequiresDifferentApprover(): void
    {
        $service = $this->createPartialService();
        $log = $this->createMock(FulfillmentInheritanceLog::class);
        $log->method('isPendingReview')->willReturn(true);
        $log->method('getReviewStatus')->willReturn(FulfillmentInheritanceLog::STATUS_PENDING_REVIEW);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->expectException(InvalidArgumentException::class);
        $service->overrideInheritance(
            $log,
            $user,
            80,
            'Valid override reason that passes the twenty char minimum.',
            $user,
        );
    }

    public function testOverrideRejectsOutOfRangeValue(): void
    {
        $service = $this->createPartialService();
        $log = $this->createMock(FulfillmentInheritanceLog::class);
        $log->method('isPendingReview')->willReturn(true);
        $log->method('getReviewStatus')->willReturn(FulfillmentInheritanceLog::STATUS_PENDING_REVIEW);

        $reviewer = $this->createMock(User::class);
        $reviewer->method('getId')->willReturn(1);
        $approver = $this->createMock(User::class);
        $approver->method('getId')->willReturn(2);

        $this->expectException(InvalidArgumentException::class);
        $service->overrideInheritance(
            $log,
            $reviewer,
            200,
            'Valid override reason that passes the twenty char minimum.',
            $approver,
        );
    }

    private function createPartialService(): ComplianceInheritanceService
    {
        /** @var ComplianceInheritanceService $service */
        $service = $this->getMockBuilder(ComplianceInheritanceService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        return $service;
    }
}
