<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\User;
use App\Exception\InvalidArgument\InvalidArgumentException as AppInvalidArgumentException;
use App\Service\CompliancePolicyService;
use App\Service\FourEyesApprovalService;
use App\Exception\BusinessRule\BusinessRuleException;
use LogicException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use ReflectionProperty;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
final class FourEyesApprovalServiceTest extends TestCase
{
    private function createPartialService(): FourEyesApprovalService
    {
        /** @var FourEyesApprovalService $service */
        $service = $this->getMockBuilder(FourEyesApprovalService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $policy = $this->createStub(CompliancePolicyService::class);
        $policy->method('getInt')->willReturnCallback(
            static fn (string $key, int $fallback = 0): int => $fallback,
        );

        $policyProp = new ReflectionProperty(FourEyesApprovalService::class, 'policy');
        $policyProp->setValue($service, $policy);

        return $service;
    }

    #[Test]
    public function testSelfApprovalBlocked(): void
    {
        $service = $this->createPartialService();

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->expectException(BusinessRuleException::class);
        $service->requestApproval(
            actionType: FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE,
            payload: [],
            requester: $user,
            specificApprover: $user,
        );
    }

    private static function tenant(int $id): \App\Entity\Tenant
    {
        $tenant = new \App\Entity\Tenant();
        (new ReflectionProperty(\App\Entity\Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function serviceInTenant(\App\Entity\Tenant $tenant): FourEyesApprovalService
    {
        $service = $this->createPartialService();
        $context = $this->createStub(\App\Service\TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($tenant);
        (new ReflectionProperty(FourEyesApprovalService::class, 'tenantContext'))->setValue($service, $context);
        (new ReflectionProperty(FourEyesApprovalService::class, 'entityManager'))
            ->setValue($service, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class));
        (new ReflectionProperty(FourEyesApprovalService::class, 'auditLogger'))
            ->setValue($service, $this->createStub(\App\Service\AuditLogger::class));

        return $service;
    }

    #[Test]
    public function designatedApproverOfAnotherTenantIsRejected(): void
    {
        $service = $this->serviceInTenant(self::tenant(1));
        $requester = (new User())->setTenant(self::tenant(1));
        (new ReflectionProperty(User::class, 'id'))->setValue($requester, 1);
        $foreign = (new User())->setTenant(self::tenant(2));
        (new ReflectionProperty(User::class, 'id'))->setValue($foreign, 2);

        try {
            $service->requestApproval(FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE, [], $requester, $foreign);
            self::fail('approver of another organisation was accepted');
        } catch (BusinessRuleException $e) {
            self::assertSame('approver_other_tenant', $e->getRuleCode());
        }
    }

    #[Test]
    public function designatedApproverOfTheSameTenantIsAccepted(): void
    {
        $service = $this->serviceInTenant(self::tenant(1));
        $requester = (new User())->setTenant(self::tenant(1));
        (new ReflectionProperty(User::class, 'id'))->setValue($requester, 1);
        $approver = (new User())->setTenant(self::tenant(1));
        (new ReflectionProperty(User::class, 'id'))->setValue($approver, 2);

        $request = $service->requestApproval(FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE, [], $requester, $approver);

        self::assertSame($approver, $request->getRequestedApprover());
    }

    #[Test]
    public function testRejectRequiresMinLength(): void
    {
        $service = $this->createPartialService();

        $requester = $this->createMock(User::class);
        $requester->method('getId')->willReturn(1);
        $approver = $this->createMock(User::class);
        $approver->method('getId')->willReturn(2);

        $request = $this->createMock(FourEyesApprovalRequest::class);
        $request->method('isPending')->willReturn(true);
        $request->method('getRequestedBy')->willReturn($requester);

        $this->expectException(AppInvalidArgumentException::class);
        $service->reject($request, $approver, 'too short');
    }

    #[Test]
    public function testApproveNonPendingFails(): void
    {
        $service = $this->createPartialService();

        $approver = $this->createMock(User::class);
        $approver->method('getId')->willReturn(2);

        $request = $this->createMock(FourEyesApprovalRequest::class);
        $request->method('isPending')->willReturn(false);
        $request->method('getStatus')->willReturn(FourEyesApprovalRequest::STATUS_APPROVED);

        $this->expectException(\App\Exception\Workflow\InvalidStatusTransitionException::class);
        $service->approve($request, $approver);
    }
}
