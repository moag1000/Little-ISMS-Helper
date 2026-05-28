<?php

declare(strict_types=1);

namespace App\Tests\Service\Setup;

use App\Entity\Tenant;
use App\Repository\AppliedBaselineRepository;
use App\Repository\AssetRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\IndustryBaselineRepository;
use App\Repository\RiskRepository;
use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use App\Service\IndustryBaselineApplier;
use App\Service\Setup\SetupBaselineApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Unit tests for SetupBaselineApplier.
 *
 * IndustryBaselineApplier is `final` with no interface, so we construct the real
 * object with all mocked dependencies. Tests target the early-return branches
 * that do NOT reach IndustryBaselineApplier::apply():
 *
 *   - Baseline not found + Kernel throws → exception caught → string returned
 *   - Baseline found but Tenant is null → "Kein Tenant" returned
 *
 * Branches that exercise apply() are integration-tested via KernelTestCase.
 *
 * @coverage-skip apply_returns_already_applied_message / apply_returns_counts_message
 *   → require live Symfony container (IndustryBaselineApplier is final, no interface).
 */
#[AllowMockObjectsWithoutExpectations]
final class SetupBaselineApplierTest extends TestCase
{
    private MockObject $baselineRepository;
    private MockObject $tenantRepository;
    private MockObject $entityManager;
    private MockObject $security;
    private MockObject $kernel;

    protected function setUp(): void
    {
        $this->baselineRepository = $this->createMock(IndustryBaselineRepository::class);
        $this->tenantRepository   = $this->createMock(TenantRepository::class);
        $this->entityManager      = $this->createMock(EntityManagerInterface::class);
        $this->security           = $this->createMock(Security::class);
        $this->kernel             = $this->createMock(KernelInterface::class);
    }

    // ────────────────────────────────────────────────────────────────────────
    // applyGenericStarterBaseline — early-return branches only
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function apply_returns_skip_message_when_no_tenant_exists(): void
    {
        $baseline = $this->createMock(\App\Entity\IndustryBaseline::class);
        $this->baselineRepository->method('findByCode')->with('BL-GENERIC-v1')->willReturn($baseline);
        $this->tenantRepository->method('findOneBy')->willReturn(null);

        $applier = $this->buildApplier();
        $result  = $applier->applyGenericStarterBaseline();

        self::assertStringContainsString('Kein Tenant', $result);
    }

    #[Test]
    public function apply_returns_non_empty_string_when_kernel_throws_and_baseline_null(): void
    {
        $this->baselineRepository->method('findByCode')->willReturn(null);
        $this->kernel->method('getBundles')->willThrowException(new \RuntimeException('no container'));

        $applier = $this->buildApplier();
        $result  = $applier->applyGenericStarterBaseline();

        self::assertIsString($result);
        self::assertNotEmpty($result);
    }

    #[Test]
    public function apply_always_returns_a_string(): void
    {
        // Both "not found" + "no tenant" paths return strings
        $this->baselineRepository->method('findByCode')->willReturn(null);
        $this->kernel->method('getBundles')->willThrowException(new \RuntimeException('seed failed'));

        $applier = $this->buildApplier();
        $result  = $applier->applyGenericStarterBaseline();

        self::assertIsString($result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Build a real SetupBaselineApplier with a real IndustryBaselineApplier
     * whose inner dependencies are all mocked. We rely on the fact that tests
     * only exercise branches that do NOT call IndustryBaselineApplier::apply().
     */
    private function buildApplier(): SetupBaselineApplier
    {
        $innerApplier = new IndustryBaselineApplier(
            $this->entityManager,
            $this->createMock(AppliedBaselineRepository::class),
            $this->createMock(RiskRepository::class),
            $this->createMock(AssetRepository::class),
            $this->createMock(ControlRepository::class),
            $this->createMock(ComplianceFrameworkRepository::class),
            $this->createMock(AuditLogger::class),
        );

        return new SetupBaselineApplier(
            $this->baselineRepository,
            $innerApplier,
            $this->tenantRepository,
            $this->entityManager,
            $this->security,
            $this->kernel,
        );
    }
}
