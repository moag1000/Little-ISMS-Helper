<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\BulkImportBatch;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\BulkImportVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class BulkImportVoterTest extends TestCase
{
    private BulkImportVoter $voter;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->voter       = new BulkImportVoter();
        $this->tenant      = $this->createMock(Tenant::class);
        $this->otherTenant = $this->createMock(Tenant::class);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createUser(array $roles = ['ROLE_USER'], ?Tenant $tenant = null): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getTenant')->willReturn($tenant ?? $this->tenant);
        return $user;
    }

    private function createBatch(string $status = BulkImportBatch::STATUS_UPLOADED, ?Tenant $tenant = null, ?User $owner = null): BulkImportBatch
    {
        $batch = $this->createMock(BulkImportBatch::class);
        $batch->method('getTenant')->willReturn($tenant ?? $this->tenant);
        $batch->method('getStatus')->willReturn($status);
        $batch->method('getExecutedBy')->willReturn($owner);
        return $batch;
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    // ------------------------------------------------------------------
    // VIEW
    // ------------------------------------------------------------------

    #[Test]
    public function testViewSameTenant(): void
    {
        $user  = $this->createUser(['ROLE_USER', 'ROLE_MANAGER'], $this->tenant);
        $batch = $this->createBatch(BulkImportBatch::STATUS_UPLOADED, $this->tenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $batch, [BulkImportVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function testViewOtherTenantDenied(): void
    {
        $user  = $this->createUser(['ROLE_USER', 'ROLE_MANAGER'], $this->tenant);
        $batch = $this->createBatch(BulkImportBatch::STATUS_UPLOADED, $this->otherTenant);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $batch, [BulkImportVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testViewOwnerWithoutManagerRoleGranted(): void
    {
        $user  = $this->createUser(['ROLE_USER'], $this->tenant);
        $batch = $this->createBatch(BulkImportBatch::STATUS_UPLOADED, $this->tenant, $user);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $batch, [BulkImportVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ------------------------------------------------------------------
    // EDIT
    // ------------------------------------------------------------------

    #[Test]
    public function testEditOnlyDuringPrepStatus(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_MANAGER'], $this->tenant);

        foreach ([BulkImportBatch::STATUS_UPLOADED, BulkImportBatch::STATUS_MAPPED, BulkImportBatch::STATUS_PREVIEW] as $status) {
            $batch  = $this->createBatch($status, $this->tenant);
            $token  = $this->createToken($user);
            $result = $this->voter->vote($token, $batch, [BulkImportVoter::EDIT]);
            $this->assertSame(VoterInterface::ACCESS_GRANTED, $result, "Expected EDIT to be granted for status: $status");
        }

        foreach ([BulkImportBatch::STATUS_COMPLETED, BulkImportBatch::STATUS_FAILED, BulkImportBatch::STATUS_COMMITTING, BulkImportBatch::STATUS_CANCELLED] as $status) {
            $batch  = $this->createBatch($status, $this->tenant);
            $token  = $this->createToken($user);
            $result = $this->voter->vote($token, $batch, [BulkImportVoter::EDIT]);
            $this->assertSame(VoterInterface::ACCESS_DENIED, $result, "Expected EDIT to be denied for status: $status");
        }
    }

    // ------------------------------------------------------------------
    // COMMIT
    // ------------------------------------------------------------------

    #[Test]
    public function testCommitOnlyOnPreview(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_MANAGER'], $this->tenant);

        // Allowed on preview
        $batch  = $this->createBatch(BulkImportBatch::STATUS_PREVIEW, $this->tenant);
        $token  = $this->createToken($user);
        $result = $this->voter->vote($token, $batch, [BulkImportVoter::COMMIT]);
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);

        // Denied on other statuses
        $batch  = $this->createBatch(BulkImportBatch::STATUS_UPLOADED, $this->tenant);
        $token  = $this->createToken($user);
        $result = $this->voter->vote($token, $batch, [BulkImportVoter::COMMIT]);
        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ------------------------------------------------------------------
    // CANCEL
    // ------------------------------------------------------------------

    #[Test]
    public function testCancelDenyAfterCompleted(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_MANAGER'], $this->tenant);

        $batch  = $this->createBatch(BulkImportBatch::STATUS_COMPLETED, $this->tenant);
        $token  = $this->createToken($user);
        $result = $this->voter->vote($token, $batch, [BulkImportVoter::CANCEL]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function testCancelAllowedDuringPrep(): void
    {
        $user   = $this->createUser(['ROLE_USER', 'ROLE_MANAGER'], $this->tenant);
        $batch  = $this->createBatch(BulkImportBatch::STATUS_MAPPED, $this->tenant);
        $token  = $this->createToken($user);
        $result = $this->voter->vote($token, $batch, [BulkImportVoter::CANCEL]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ------------------------------------------------------------------
    // DELETE
    // ------------------------------------------------------------------

    #[Test]
    public function testDeleteRequiresRoleAdmin(): void
    {
        $manager = $this->createUser(['ROLE_USER', 'ROLE_MANAGER'], $this->tenant);
        $admin   = $this->createUser(['ROLE_USER', 'ROLE_MANAGER', 'ROLE_ADMIN'], $this->tenant);

        $batch = $this->createBatch(BulkImportBatch::STATUS_COMPLETED, $this->tenant);

        $managerToken = $this->createToken($manager);
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($managerToken, $batch, [BulkImportVoter::DELETE]));

        $adminToken = $this->createToken($admin);
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($adminToken, $batch, [BulkImportVoter::DELETE]));
    }

    // ------------------------------------------------------------------
    // TRIGGER CTA (null subject)
    // ------------------------------------------------------------------

    #[Test]
    public function testTriggerCtaForRoleManager(): void
    {
        $manager  = $this->createUser(['ROLE_USER', 'ROLE_MANAGER'], $this->tenant);
        $readOnly = $this->createUser(['ROLE_USER'], $this->tenant);

        $managerToken  = $this->createToken($manager);
        $readOnlyToken = $this->createToken($readOnly);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($managerToken, null, [BulkImportVoter::BULK_IMPORT_TRIGGER]),
        );

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($readOnlyToken, null, [BulkImportVoter::BULK_IMPORT_TRIGGER]),
        );
    }
}
