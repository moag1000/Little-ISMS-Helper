<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\User;
use App\Service\FourEyesApprovalService;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class FourEyesApprovalServiceTest extends TestCase
{
    public function testSelfApprovalBlocked(): void
    {
        $service = $this->getMockBuilder(FourEyesApprovalService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->expectException(InvalidArgumentException::class);
        $service->requestApproval(
            actionType: FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE,
            payload: [],
            requester: $user,
            specificApprover: $user,
        );
    }

    public function testRejectRequiresMinLength(): void
    {
        $service = $this->getMockBuilder(FourEyesApprovalService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $requester = $this->createMock(User::class);
        $requester->method('getId')->willReturn(1);
        $approver = $this->createMock(User::class);
        $approver->method('getId')->willReturn(2);

        $request = $this->createMock(FourEyesApprovalRequest::class);
        $request->method('isPending')->willReturn(true);
        $request->method('getRequestedBy')->willReturn($requester);

        $this->expectException(InvalidArgumentException::class);
        $service->reject($request, $approver, 'too short');
    }

    public function testApproveNonPendingFails(): void
    {
        $service = $this->getMockBuilder(FourEyesApprovalService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $approver = $this->createMock(User::class);
        $approver->method('getId')->willReturn(2);

        $request = $this->createMock(FourEyesApprovalRequest::class);
        $request->method('isPending')->willReturn(false);
        $request->method('getStatus')->willReturn(FourEyesApprovalRequest::STATUS_APPROVED);

        $this->expectException(LogicException::class);
        $service->approve($request, $approver);
    }
}
