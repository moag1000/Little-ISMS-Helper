<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceRequirement;
use App\Service\AuditLogger;
use App\Service\MrisMaturityService;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
final class MrisMaturityServiceTest extends TestCase
{
    private function makeService(?AuditLogger $log = null): MrisMaturityService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $log ??= $this->createMock(AuditLogger::class);
        return new MrisMaturityService($em, $log);
    }

    #[Test]
    public function testSetCurrentValidStageStoresAndStampsReviewedAt(): void
    {
        $req = new ComplianceRequirement();
        $service = $this->makeService();

        $service->setCurrent($req, 'defined');

        self::assertSame('defined', $req->getMaturityCurrent());
        self::assertNotNull($req->getMaturityReviewedAt());
    }

    #[Test]
    public function testSetCurrentNullClearsField(): void
    {
        $req = new ComplianceRequirement();
        $req->setMaturityCurrent('defined');
        $service = $this->makeService();

        $service->setCurrent($req, null);

        self::assertNull($req->getMaturityCurrent());
    }

    #[Test]
    public function testSetCurrentInvalidStageThrows(): void
    {
        $service = $this->makeService();
        $req = new ComplianceRequirement();

        $this->expectException(DomainException::class);
        $service->setCurrent($req, 'optimized'); // not in MRIS Kap. 9.5
    }

    #[Test]
    public function testSetTargetStoresValue(): void
    {
        $req = new ComplianceRequirement();
        $service = $this->makeService();

        $service->setTarget($req, 'managed');

        self::assertSame('managed', $req->getMaturityTarget());
    }

    #[Test]
    public function testDeltaReturnsPositiveWhenTargetAboveCurrent(): void
    {
        $req = new ComplianceRequirement();
        $req->setMaturityCurrent('initial');
        $req->setMaturityTarget('managed');
        $service = $this->makeService();

        self::assertSame(2, $service->delta($req));
        self::assertSame('gap', $service->gapStatus($req));
    }

    #[Test]
    public function testDeltaReturnsZeroWhenOnTarget(): void
    {
        $req = new ComplianceRequirement();
        $req->setMaturityCurrent('defined');
        $req->setMaturityTarget('defined');
        $service = $this->makeService();

        self::assertSame(0, $service->delta($req));
        self::assertSame('on_target', $service->gapStatus($req));
    }

    #[Test]
    public function testDeltaReturnsNullWhenAnyFieldMissing(): void
    {
        $req = new ComplianceRequirement();
        $req->setMaturityCurrent('defined');
        $service = $this->makeService();

        self::assertNull($service->delta($req));
        self::assertSame('unset', $service->gapStatus($req));
    }

    #[Test]
    public function testGapStatusExceededWhenCurrentAboveTarget(): void
    {
        $req = new ComplianceRequirement();
        $req->setMaturityCurrent('managed');
        $req->setMaturityTarget('defined');
        $service = $this->makeService();

        self::assertSame('exceeded', $service->gapStatus($req));
    }
}
