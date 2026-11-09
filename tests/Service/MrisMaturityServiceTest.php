<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Exception\BusinessRule\BusinessRuleException;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Service\AuditLogger;
use App\Service\Compliance\RequirementMaturity;
use App\Service\MrisMaturityService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MrisMaturityServiceTest extends TestCase
{
    private RequirementMaturity $maturity;

    protected function setUp(): void
    {
        $this->maturity = $this->freshMaturity();
    }

    /** Real RequirementMaturity over a repository that knows no stored rows yet. */
    private function freshMaturity(): RequirementMaturity
    {
        $repo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $repo->method('findBy')->willReturn([]);
        $repo->method('findOrCreateForTenantAndRequirement')->willReturnCallback(
            static fn(Tenant $t, ComplianceRequirement $r): ComplianceRequirementFulfillment
                => (new ComplianceRequirementFulfillment())->setTenant($t)->setRequirement($r),
        );

        return new RequirementMaturity($repo, $this->createStub(EntityManagerInterface::class));
    }

    private function makeService(?AuditLogger $log = null): MrisMaturityService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $log ??= $this->createStub(AuditLogger::class);
        return new MrisMaturityService($em, $log, $this->maturity);
    }

    #[Test]
    public function testSetCurrentValidStageStoresAndStampsReviewedAt(): void
    {
        $req = new ComplianceRequirement();
        $tenant = new Tenant();

        $this->makeService()->setCurrent($req, $tenant, 'defined');

        self::assertSame('defined', $this->maturity->current($req, $tenant));
        self::assertNotNull($this->maturity->reviewedAt($req, $tenant));
    }

    #[Test]
    public function testSetCurrentNullClearsField(): void
    {
        $req = new ComplianceRequirement();
        $tenant = new Tenant();
        $service = $this->makeService();
        $service->setCurrent($req, $tenant, 'defined');

        $service->setCurrent($req, $tenant, null);

        self::assertNull($this->maturity->current($req, $tenant));
    }

    #[Test]
    public function testSetCurrentInvalidStageThrows(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->makeService()->setCurrent(new ComplianceRequirement(), new Tenant(), 'optimized'); // not in MRIS Kap. 9.5
    }

    #[Test]
    public function testSetTargetStoresValue(): void
    {
        $req = new ComplianceRequirement();
        $tenant = new Tenant();

        $this->makeService()->setTarget($req, $tenant, 'managed');

        self::assertSame('managed', $this->maturity->target($req, $tenant));
    }

    /** S18: the shared catalogue row is never written; every organisation keeps its own value. */
    #[Test]
    public function testOrganisationsKeepTheirOwnMaturityOfASharedRequirement(): void
    {
        $req = new ComplianceRequirement();
        $a = new Tenant();
        $b = new Tenant();
        $service = $this->makeService();

        $service->setCurrent($req, $a, 'managed');
        $service->setTarget($req, $a, 'managed');
        $service->setCurrent($req, $b, 'initial');

        self::assertNull($req->getMaturityCurrent(), 'the global row carries no organisation value');
        self::assertNull($req->getMaturityTarget());
        self::assertSame('managed', $this->maturity->current($req, $a));
        self::assertSame('initial', $this->maturity->current($req, $b));
        self::assertNull($this->maturity->target($req, $b));
        self::assertSame('on_target', $service->gapStatus($req, $a));
        self::assertSame('unset', $service->gapStatus($req, $b));
    }

    #[Test]
    public function testCatalogueTargetIsDefaultUntilTheOrganisationChoosesOne(): void
    {
        $req = (new ComplianceRequirement())->setMaturityTarget('defined');
        $tenant = new Tenant();

        self::assertSame('defined', $this->maturity->target($req, $tenant));
        $this->makeService()->setTarget($req, $tenant, 'managed');
        self::assertSame('managed', $this->maturity->target($req, $tenant));
        self::assertSame('defined', $req->getMaturityTarget(), 'catalogue default unchanged');
    }

    #[Test]
    public function testOwnUploadedRowKeepsItsValueOnTheRow(): void
    {
        $tenant = new Tenant();
        $req = (new ComplianceRequirement())->setUploadTenant($tenant);

        $this->makeService()->setCurrent($req, $tenant, 'defined');

        self::assertSame('defined', $req->getMaturityCurrent());
    }

    #[Test]
    public function testAnotherOrganisationsUploadedRowIsRefused(): void
    {
        $req = (new ComplianceRequirement())->setUploadTenant(new Tenant())->setMaturityCurrent('managed');
        $other = new Tenant();

        self::assertNull($this->maturity->current($req, $other));
        $this->expectException(BusinessRuleException::class);
        $this->makeService()->setCurrent($req, $other, 'initial');
    }

    #[Test]
    public function testDeltaReturnsPositiveWhenTargetAboveCurrent(): void
    {
        [$req, $tenant, $service] = $this->assessed('initial', 'managed');

        self::assertSame(2, $service->delta($req, $tenant));
        self::assertSame('gap', $service->gapStatus($req, $tenant));
    }

    #[Test]
    public function testDeltaReturnsZeroWhenOnTarget(): void
    {
        [$req, $tenant, $service] = $this->assessed('defined', 'defined');

        self::assertSame(0, $service->delta($req, $tenant));
        self::assertSame('on_target', $service->gapStatus($req, $tenant));
    }

    #[Test]
    public function testDeltaReturnsNullWhenAnyFieldMissing(): void
    {
        [$req, $tenant, $service] = $this->assessed('defined', null);

        self::assertNull($service->delta($req, $tenant));
        self::assertSame('unset', $service->gapStatus($req, $tenant));
    }

    #[Test]
    public function testGapStatusExceededWhenCurrentAboveTarget(): void
    {
        [$req, $tenant, $service] = $this->assessed('managed', 'defined');

        self::assertSame('exceeded', $service->gapStatus($req, $tenant));
    }

    #[Test]
    public function testResetCurrentEverywhereClearsEveryOrganisation(): void
    {
        $req = new ComplianceRequirement();
        $stored = [
            (new ComplianceRequirementFulfillment())->setMaturityCurrent('managed'),
            (new ComplianceRequirementFulfillment())->setMaturityCurrent('initial'),
        ];
        $repo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $repo->method('findBy')->willReturn($stored);
        $service = new MrisMaturityService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AuditLogger::class),
            new RequirementMaturity($repo, $this->createStub(EntityManagerInterface::class)),
        );

        $service->resetCurrentEverywhere($req, 'test');

        self::assertNull($stored[0]->getMaturityCurrent());
        self::assertNull($stored[1]->getMaturityCurrent());
    }

    /** @return array{ComplianceRequirement, Tenant, MrisMaturityService} */
    private function assessed(?string $current, ?string $target): array
    {
        $req = new ComplianceRequirement();
        $tenant = new Tenant();
        $service = $this->makeService();
        $service->setCurrent($req, $tenant, $current);
        $service->setTarget($req, $tenant, $target);

        return [$req, $tenant, $service];
    }
}
