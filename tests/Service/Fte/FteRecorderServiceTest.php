<?php

declare(strict_types=1);

namespace App\Tests\Service\Fte;

use App\Entity\Document;
use App\Entity\Fte\FteTrackingMetric;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Fte\FteCalculationService;
use App\Service\Fte\FteRecorderService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FteRecorderServiceTest extends TestCase
{
    private FteCalculationService $calculator;
    private FteRecorderService $recorder;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(FteCalculationService::class);
        $this->recorder = new FteRecorderService($this->calculator, new NullLogger());
    }

    #[Test]
    public function recordSsoJitSkipsUserWithoutTenant(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn(null);

        $this->calculator->expects($this->never())->method('recordMetric');

        $this->recorder->recordSsoJit($user);
    }

    #[Test]
    public function recordSsoJitCallsCalculatorWhenTenantPresent(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');

        $this->calculator->method('calculateSsoJitSavings')->willReturn(20);
        $this->calculator->expects($this->once())
            ->method('recordMetric')
            ->with(
                FteTrackingMetric::SOURCE_SSO_JIT,
                'User',
                1,
                $this->anything(),
                0,
                $tenant,
                $this->anything()
            )
            ->willReturn($this->createStub(FteTrackingMetric::class));

        $this->recorder->recordSsoJit($user);
    }

    #[Test]
    public function recordBulkImportSkipsZeroRows(): void
    {
        $tenant = $this->createStub(Tenant::class);

        $this->calculator->expects($this->never())->method('recordMetric');

        $this->recorder->recordBulkImport(0, 'risk', $tenant);
    }

    #[Test]
    public function recordBulkImportCallsCalculatorForPositiveRows(): void
    {
        $tenant = $this->createStub(Tenant::class);

        $this->calculator->method('calculateBulkImportSavings')->willReturn(100);
        $this->calculator->expects($this->once())
            ->method('recordMetric')
            ->with(FteTrackingMetric::SOURCE_BULK_IMPORT, 'risk', null, 101, 1, $tenant, $this->anything())
            ->willReturn($this->createStub(FteTrackingMetric::class));

        $this->recorder->recordBulkImport(25, 'risk', $tenant);
    }

    #[Test]
    public function recordEvidenceReuseSkipsWhenReuseCountLessThanTwo(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $doc = $this->createMock(Document::class);
        $doc->method('getTenant')->willReturn($tenant);

        $this->calculator->expects($this->never())->method('recordMetric');

        $this->recorder->recordEvidenceReuse($doc, 1);
    }

    #[Test]
    public function recordEvidenceReuseSkipsWhenDocHasNoTenant(): void
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getTenant')->willReturn(null);

        $this->calculator->expects($this->never())->method('recordMetric');

        $this->recorder->recordEvidenceReuse($doc, 5);
    }

    #[Test]
    public function recorderSwallowsExceptionsFromCalculator(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($this->createStub(Tenant::class));
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('x@x.com');

        $this->calculator->method('calculateSsoJitSavings')->willReturn(10);
        $this->calculator->method('recordMetric')->willThrowException(new \RuntimeException('DB down'));

        // Should NOT throw — errors are logged and swallowed
        $this->recorder->recordSsoJit($user);
        $this->addToAssertionCount(1); // reached = pass
    }
}
