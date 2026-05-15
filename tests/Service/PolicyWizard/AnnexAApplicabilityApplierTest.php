<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\AnnexAApplicabilityApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AnnexAApplicabilityApplier.
 *
 * Covers:
 *  - applyToTenant updates all referenced controls that changed
 *  - applyToTenant skips controls absent from the map
 *  - applyToTenant emits an audit-log entry per changed control
 *  - applyToTenant handles control-not-found gracefully (no exception)
 *  - controls whose applicable flag already matches desired value are skipped
 */
#[AllowMockObjectsWithoutExpectations]
final class AnnexAApplicabilityApplierTest extends TestCase
{
    private function makeTenant(int $id = 1): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        return $tenant;
    }

    private function makeControl(string $controlId, ?bool $currentApplicable = null): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getId')->willReturn(crc32($controlId));
        $control->method('isApplicable')->willReturn($currentApplicable);
        return $control;
    }

    #[Test]
    public function applyToTenant_updatesAllReferencedControls(): void
    {
        $tenant = $this->makeTenant();

        $controlA51 = $this->makeControl('5.1', false); // currently false → should flip to true
        $controlA52 = $this->makeControl('5.2', true);  // currently true  → should flip to false

        $controlA51->expects(self::once())->method('setApplicable')->with(true);
        $controlA52->expects(self::once())->method('setApplicable')->with(false);

        $repo = $this->createMock(ControlRepository::class);
        $repo->method('findByControlIdAndTenant')
            ->willReturnMap([
                ['5.1', $tenant, $controlA51],
                ['5.2', $tenant, $controlA52],
            ]);

        $em = $this->createStub(EntityManagerInterface::class);

        $applier = new AnnexAApplicabilityApplier($repo, $em);
        $stats = $applier->applyToTenant($tenant, ['5.1' => true, '5.2' => false]);

        self::assertSame(2, $stats['updated']);
        self::assertSame(0, $stats['not_found']);
    }

    #[Test]
    public function applyToTenant_skipsControlsNotInMap(): void
    {
        $tenant = $this->makeTenant();

        // Repository should never be called for controls outside the map.
        $repo = $this->createMock(ControlRepository::class);
        $repo->expects(self::never())->method('findByControlIdAndTenant');

        $em = $this->createStub(EntityManagerInterface::class);

        $applier = new AnnexAApplicabilityApplier($repo, $em);
        $stats = $applier->applyToTenant($tenant, []);

        self::assertSame(0, $stats['updated']);
        self::assertSame(0, $stats['not_found']);
    }

    #[Test]
    public function applyToTenant_emitsAuditEventPerChange(): void
    {
        $tenant = $this->makeTenant(42);

        $control = $this->makeControl('5.1', false); // false → true = change
        $control->method('setApplicable')->willReturnSelf();

        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findByControlIdAndTenant')->willReturn($control);

        $em = $this->createStub(EntityManagerInterface::class);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())
            ->method('logUpdate')
            ->with(
                'Control',
                self::anything(),
                ['applicable' => false],
                ['applicable' => true],
                self::stringContains('5.1'),
            );

        $applier = new AnnexAApplicabilityApplier($repo, $em, $auditLogger);
        $applier->applyToTenant($tenant, ['5.1' => true]);
    }

    #[Test]
    public function applyToTenant_handlesControlNotFoundGracefully(): void
    {
        $tenant = $this->makeTenant();

        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findByControlIdAndTenant')->willReturn(null); // control not seeded

        $em = $this->createStub(EntityManagerInterface::class);

        $applier = new AnnexAApplicabilityApplier($repo, $em);
        $stats = $applier->applyToTenant($tenant, ['99.99' => true]);

        self::assertSame(0, $stats['updated']);
        self::assertSame(1, $stats['not_found']);
    }

    #[Test]
    public function applyToTenant_skipsControlsWhereValueAlreadyMatches(): void
    {
        $tenant = $this->makeTenant();

        $control = $this->makeControl('5.1', true); // already true — no change expected
        $control->expects(self::never())->method('setApplicable');

        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findByControlIdAndTenant')->willReturn($control);

        $em = $this->createStub(EntityManagerInterface::class);

        $applier = new AnnexAApplicabilityApplier($repo, $em);
        $stats = $applier->applyToTenant($tenant, ['5.1' => true]);

        self::assertSame(0, $stats['updated']);
    }
}
