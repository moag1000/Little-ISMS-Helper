<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\Tenant;
use App\Enum\FourEyesApprovalRequestStatus;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Service\AuditLogger;
use App\Service\CompliancePolicyService;
use App\Service\FourEyesApprovalService;
use App\Service\Import\ImportCommitApproval;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "Import 4-Augen-Pflicht ab Zeilen" is a duty (W9): from the threshold the
 * commit needs an approval of exactly this file, used once.
 */
final class ImportCommitApprovalTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'w9');
        file_put_contents($this->file, "source_framework,target_framework\nISO27001,NIS2\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    #[Test]
    public function belowTheThresholdOrSwitchedOffNoApprovalIsNeeded(): void
    {
        self::assertSame(ImportCommitApproval::STATE_NOT_REQUIRED, $this->approval([], 50)->state($this->file, 49));
        self::assertSame(ImportCommitApproval::STATE_NOT_REQUIRED, $this->approval([], 0)->state($this->file, 5000), '0 = off');
        self::assertSame(ImportCommitApproval::STATE_MISSING, $this->approval([], 50)->state($this->file, 50));
    }

    #[Test]
    public function onlyAnApprovalOfThisFileOpensTheCommit(): void
    {
        $hash = (string) hash_file('sha256', $this->file);
        $other = $this->request('other-file-hash', FourEyesApprovalRequestStatus::Approved);
        self::assertSame(ImportCommitApproval::STATE_MISSING, $this->approval([$other])->state($this->file, 80));

        $pending = $this->request($hash, FourEyesApprovalRequestStatus::Pending);
        self::assertSame(ImportCommitApproval::STATE_PENDING, $this->approval([$pending])->state($this->file, 80));

        $expired = $this->request($hash, FourEyesApprovalRequestStatus::Pending, '-1 day');
        self::assertSame(ImportCommitApproval::STATE_MISSING, $this->approval([$expired])->state($this->file, 80));

        $rejected = $this->request($hash, FourEyesApprovalRequestStatus::Rejected);
        self::assertSame(ImportCommitApproval::STATE_MISSING, $this->approval([$rejected])->state($this->file, 80));

        $approved = $this->request($hash, FourEyesApprovalRequestStatus::Approved);
        self::assertSame(ImportCommitApproval::STATE_APPROVED, $this->approval([$approved])->state($this->file, 80));
    }

    #[Test]
    public function anApprovalOpensOneCommit(): void
    {
        $approved = $this->request((string) hash_file('sha256', $this->file), FourEyesApprovalRequestStatus::Approved);
        $approval = $this->approval([$approved]);

        $approval->consume($this->file);

        self::assertArrayHasKey('consumed_at', $approved->getPayload());
        self::assertSame(ImportCommitApproval::STATE_MISSING, $approval->state($this->file, 80), 'used approvals do not count again');
    }

    /** @param list<FourEyesApprovalRequest> $requests */
    private function approval(array $requests, int $threshold = 50): ImportCommitApproval
    {
        $policy = $this->createStub(CompliancePolicyService::class);
        $policy->method('getInt')->willReturn($threshold);
        $repository = $this->createStub(FourEyesApprovalRequestRepository::class);
        $repository->method('findByActionType')->willReturn($requests);
        $tenantContext = $this->createStub(TenantContext::class);
        $tenantContext->method('getCurrentTenant')->willReturn(new Tenant());

        return new ImportCommitApproval(
            $policy,
            $this->createStub(FourEyesApprovalService::class),
            $repository,
            $tenantContext,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AuditLogger::class),
        );
    }

    private function request(string $hash, FourEyesApprovalRequestStatus $status, string $expires = '+7 days'): FourEyesApprovalRequest
    {
        return (new FourEyesApprovalRequest())
            ->setActionType(FourEyesApprovalRequest::ACTION_IMPORT_LARGE_COMMIT)
            ->setPayload(['file_sha256' => $hash, 'rows' => 80])
            ->setStatus($status)
            ->setExpiresAt(new DateTimeImmutable($expires));
    }
}
