<?php

declare(strict_types=1);

namespace App\Tests\Entity\Fte;

use App\Entity\Fte\FteCalibrationConstant;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FteCalibrationConstantTest extends TestCase
{
    #[Test]
    public function itHasCorrectOperationTypeConstants(): void
    {
        $this->assertSame('manual_user_provisioning', FteCalibrationConstant::OP_MANUAL_USER_PROVISIONING);
        $this->assertSame('manual_asset_creation', FteCalibrationConstant::OP_MANUAL_ASSET_CREATION);
        $this->assertSame('manual_risk_creation', FteCalibrationConstant::OP_MANUAL_RISK_CREATION);
        $this->assertSame('manual_control_mapping', FteCalibrationConstant::OP_MANUAL_CONTROL_MAPPING);
        $this->assertSame('single_framework_evidence_maintenance', FteCalibrationConstant::OP_SINGLE_FRAMEWORK_EVIDENCE_MAINTENANCE);
        $this->assertSame('manual_business_process_creation', FteCalibrationConstant::OP_MANUAL_BUSINESS_PROCESS_CREATION);
    }

    #[Test]
    public function itInitializesWithNullTenantAndTimestamp(): void
    {
        $constant = new FteCalibrationConstant();

        $this->assertNull($constant->getTenant());
        $this->assertNull($constant->getLastUpdatedBy());
        $this->assertInstanceOf(DateTimeImmutable::class, $constant->getLastUpdatedAt());
    }

    #[Test]
    public function itMutatesAllFields(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $user = $this->createStub(User::class);
        $now = new DateTimeImmutable();

        $constant = new FteCalibrationConstant();
        $constant->setTenant($tenant);
        $constant->setOperationType(FteCalibrationConstant::OP_MANUAL_USER_PROVISIONING);
        $constant->setMinutesPerOperation(25.0);
        $constant->setLastUpdatedBy($user);
        $constant->setLastUpdatedAt($now);

        $this->assertSame($tenant, $constant->getTenant());
        $this->assertSame(FteCalibrationConstant::OP_MANUAL_USER_PROVISIONING, $constant->getOperationType());
        $this->assertEqualsWithDelta(25.0, $constant->getMinutesPerOperation(), 0.001);
        $this->assertSame($user, $constant->getLastUpdatedBy());
        $this->assertSame($now, $constant->getLastUpdatedAt());
    }

    #[Test]
    public function itReturnsFloatFromStringStoredDecimal(): void
    {
        $constant = new FteCalibrationConstant();
        $constant->setMinutesPerOperation(8.5);

        $this->assertIsFloat($constant->getMinutesPerOperation());
        $this->assertEqualsWithDelta(8.5, $constant->getMinutesPerOperation(), 0.001);
    }
}
