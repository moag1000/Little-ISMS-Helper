<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\FulfillmentInheritanceLogRepository;
use App\Service\AuditLogger;
use App\Service\ComplianceInheritanceService;
use App\Service\Compliance\Fulfillment\FulfillmentDerivationService;
use App\Service\CompliancePolicyService;
use App\Service\FourEyesApprovalService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * S34 (R3b): an inheritance override is a request. The fulfilment keeps its value until the second person
 * approved; then the value is written through the one writer, and only if the suggestion is still open.
 */
#[AllowMockObjectsWithoutExpectations]
final class ComplianceInheritanceOverrideApprovalTest extends TestCase
{
    private FulfillmentDerivationService&MockObject $derivation;
    private FourEyesApprovalService&MockObject $fourEyes;
    private FulfillmentInheritanceLogRepository&MockObject $logs;
    private Security&MockObject $security;

    #[Test]
    public function anOverrideRequestWritesNothing(): void
    {
        $service = $this->service();
        [$log, $reviewer, $approver] = $this->openSuggestion();
        $this->security->method('isGrantedForUser')->willReturn(true);
        $this->derivation->expects(self::never())->method('override');
        $this->derivation->expects(self::never())->method('setManual');
        $this->fourEyes->expects(self::once())->method('requestApproval')
            ->with(FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE, self::anything(), $reviewer, $approver);

        $service->overrideInheritance($log, $reviewer, 80, 'Valid override reason that passes the twenty char minimum.', $approver);

        self::assertSame(FulfillmentInheritanceLog::STATUS_PENDING_REVIEW, $log->getReviewStatus());
    }

    #[Test]
    public function anApproverWithoutManagerRoleIsRefused(): void
    {
        $service = $this->service();
        [$log, $reviewer, $approver] = $this->openSuggestion();
        $this->security->method('isGrantedForUser')->willReturn(false);
        $this->fourEyes->expects(self::never())->method('requestApproval');

        $this->expectException(\App\Exception\BusinessRule\BusinessRuleException::class);
        $service->overrideInheritance($log, $reviewer, 80, 'Valid override reason that passes the twenty char minimum.', $approver);
    }

    #[Test]
    public function theValueReachesTheFulfilmentOnlyAfterApproval(): void
    {
        $service = $this->service();
        [$log, $reviewer, $approver, $tenant] = $this->openSuggestion();
        $request = (new FourEyesApprovalRequest())
            ->setTenant($tenant)
            ->setActionType(FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE)
            ->setRequestedBy($reviewer)
            ->setPayload(['inheritance_log_id' => 7, 'override_value' => 130, 'reason' => 'Valid override reason that passes the twenty char minimum.']);
        $this->logs->method('find')->with(7)->willReturn($log);
        // derived requirement: the writer records an explicit override, value capped to 100
        $this->derivation->expects(self::once())->method('setManual')->with($log->getFulfillment(), 100);

        $service->applyApprovedOverride($request, $approver);

        self::assertSame(FulfillmentInheritanceLog::STATUS_OVERRIDDEN, $log->getReviewStatus());
    }

    #[Test]
    public function aSuggestionDecidedMeanwhileIsLeftAlone(): void
    {
        $service = $this->service();
        [$log, $reviewer, $approver, $tenant] = $this->openSuggestion();
        $log->setReviewStatus(FulfillmentInheritanceLog::STATUS_REJECTED);
        $request = (new FourEyesApprovalRequest())
            ->setTenant($tenant)
            ->setActionType(FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE)
            ->setRequestedBy($reviewer)
            ->setPayload(['inheritance_log_id' => 7, 'override_value' => 50, 'reason' => 'x']);
        $this->logs->method('find')->willReturn($log);
        $this->derivation->expects(self::never())->method('setManual');
        $this->derivation->expects(self::never())->method('override');

        $service->applyApprovedOverride($request, $approver);

        self::assertSame(FulfillmentInheritanceLog::STATUS_REJECTED, $log->getReviewStatus());
    }

    private function service(): ComplianceInheritanceService
    {
        $this->derivation = $this->createMock(FulfillmentDerivationService::class);
        $this->fourEyes = $this->createMock(FourEyesApprovalService::class);
        $this->logs = $this->createMock(FulfillmentInheritanceLogRepository::class);
        $this->security = $this->createMock(Security::class);
        $policy = $this->createStub(CompliancePolicyService::class);
        $policy->method('getInt')->willReturnCallback(static fn (string $key, int $fallback = 0): int => $fallback);

        return new ComplianceInheritanceService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ComplianceMappingRepository::class),
            $this->createMock(ComplianceRequirementRepository::class),
            $this->createMock(ComplianceRequirementFulfillmentRepository::class),
            $this->logs,
            $this->fourEyes,
            $this->createMock(AuditLogger::class),
            $policy,
            $this->derivation,
            $this->security,
        );
    }

    /** @return array{FulfillmentInheritanceLog, User, User, Tenant} */
    private function openSuggestion(): array
    {
        $tenant = new Tenant();
        $this->setId($tenant, 3);
        $fulfillment = (new ComplianceRequirementFulfillment())->setTenant($tenant);
        $log = (new FulfillmentInheritanceLog())->setTenant($tenant)->setFulfillment($fulfillment)->setSuggestedPercentage(60);
        $this->setId($log, 7);
        $reviewer = new User();
        $this->setId($reviewer, 1);
        $approver = new User();
        $this->setId($approver, 2);

        return [$log, $reviewer, $approver, $tenant];
    }

    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
