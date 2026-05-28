<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Tisax\TisaxMaturityAssessmentService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TisaxMaturityAssessmentService.
 *
 * These tests cover pure business logic (no DB) via the static helpers,
 * the level-map contract, and the tenant-scoping guard in bulkSetReifegrad.
 */
class TisaxMaturityAssessmentServiceTest extends TestCase
{
    #[Test]
    public function level_map_covers_all_six_levels(): void
    {
        self::assertCount(6, TisaxMaturityAssessmentService::LEVEL_MAP);

        foreach (TisaxMaturityAssessmentService::REIFEGRAD_LEVELS as $level) {
            self::assertArrayHasKey($level, TisaxMaturityAssessmentService::LEVEL_MAP,
                sprintf('Level %d missing from LEVEL_MAP', $level));
        }
    }

    #[Test]
    public function level_for_string_returns_correct_int(): void
    {
        self::assertSame(0, TisaxMaturityAssessmentService::levelForString('incomplete'));
        self::assertSame(1, TisaxMaturityAssessmentService::levelForString('performed'));
        self::assertSame(2, TisaxMaturityAssessmentService::levelForString('managed'));
        self::assertSame(3, TisaxMaturityAssessmentService::levelForString('established'));
        self::assertSame(4, TisaxMaturityAssessmentService::levelForString('predictable'));
        self::assertSame(5, TisaxMaturityAssessmentService::levelForString('optimising'));
    }

    #[Test]
    public function level_for_string_returns_null_for_unknown(): void
    {
        self::assertNull(TisaxMaturityAssessmentService::levelForString('unknown_value'));
        self::assertNull(TisaxMaturityAssessmentService::levelForString(''));
    }

    #[Test]
    public function reifegrad_levels_constant_contains_zero_to_five(): void
    {
        self::assertSame([0, 1, 2, 3, 4, 5], TisaxMaturityAssessmentService::REIFEGRAD_LEVELS);
    }

    #[Test]
    public function level_map_values_are_unique_strings(): void
    {
        $values = array_values(TisaxMaturityAssessmentService::LEVEL_MAP);
        self::assertCount(count(array_unique($values)), $values, 'LEVEL_MAP values must be unique');
    }

    #[Test]
    public function level_map_is_ordered_ascending(): void
    {
        $keys = array_keys(TisaxMaturityAssessmentService::LEVEL_MAP);
        $expected = range(0, 5);
        self::assertSame($expected, $keys, 'LEVEL_MAP keys must be 0..5 in order');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cross-tenant security guard tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function testBulkSetReifegradRejectsForeignTenant(): void
    {
        // The caller's tenant (tenant A)
        $tenantA = $this->createTenantWithId(1);
        // The requirement belongs to a different tenant (tenant B)
        $tenantB = $this->createTenantWithId(2);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenantB);
        $req->setMaturityCurrent('incomplete');

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        // flush must NOT be called — no update should happen
        $em->expects(self::never())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        $user    = new User();

        $updated = $service->bulkSetReifegrad([42 => 3], $user, $tenantA);

        self::assertSame(0, $updated, 'Foreign-tenant requirement must be silently skipped');
        self::assertSame('incomplete', $req->getMaturityCurrent(), 'Maturity must not change for foreign-tenant requirement');
    }

    #[Test]
    public function testBulkSetReifegradRejectsNullUploadTenantSystemRow(): void
    {
        // A system-seeded requirement has uploadTenant = null
        $req = new ComplianceRequirement();
        $req->setUploadTenant(null);
        $req->setMaturityCurrent('incomplete');

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::never())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        $user    = new User();
        $tenant  = $this->createTenantWithId(1);

        $updated = $service->bulkSetReifegrad([99 => 2], $user, $tenant);

        self::assertSame(0, $updated, 'System-seeded requirement (uploadTenant=null) must be silently skipped');
        self::assertSame('incomplete', $req->getMaturityCurrent(), 'Maturity must not change for system row');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DP_STATES constant contract
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function dp_states_constant_contains_three_states(): void
    {
        self::assertCount(3, TisaxMaturityAssessmentService::DP_STATES);
        self::assertContains('not_applicable', TisaxMaturityAssessmentService::DP_STATES);
        self::assertContains('compliant', TisaxMaturityAssessmentService::DP_STATES);
        self::assertContains('non_compliant', TisaxMaturityAssessmentService::DP_STATES);
    }

    #[Test]
    public function reifegrad_categories_constant_excludes_data_protection(): void
    {
        self::assertNotContains('data_protection', TisaxMaturityAssessmentService::REIFEGRAD_CATEGORIES);
        self::assertContains('information_security', TisaxMaturityAssessmentService::REIFEGRAD_CATEGORIES);
        self::assertContains('prototype_protection', TisaxMaturityAssessmentService::REIFEGRAD_CATEGORIES);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // bulkSetAssessment dispatch routing tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function testBulkSetAssessment_RoutesToReifegradForIS(): void
    {
        $tenant = $this->createTenantWithId(1);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenant);
        $req->setCategory('information_security');
        $req->setMaturityCurrent('incomplete');

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::atLeastOnce())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        $result  = $service->bulkSetAssessment([42 => 3], new User(), $tenant);

        self::assertSame(1, $result['reifegrad']);
        self::assertSame(0, $result['data_protection']);
        self::assertSame('established', $req->getMaturityCurrent());
    }

    #[Test]
    public function testBulkSetAssessment_RoutesToReifegradForPP(): void
    {
        $tenant = $this->createTenantWithId(1);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenant);
        $req->setCategory('prototype_protection');
        $req->setMaturityCurrent('incomplete');

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::atLeastOnce())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        $result  = $service->bulkSetAssessment([42 => 2], new User(), $tenant);

        self::assertSame(1, $result['reifegrad']);
        self::assertSame(0, $result['data_protection']);
        self::assertSame('managed', $req->getMaturityCurrent());
    }

    #[Test]
    public function testBulkSetAssessment_RoutesToTriStateForDP(): void
    {
        $tenant = $this->createTenantWithId(1);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenant);
        $req->setCategory('data_protection');
        $req->setAssessmentStateDp(null);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::atLeastOnce())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        $result  = $service->bulkSetAssessment([42 => 'compliant'], new User(), $tenant);

        self::assertSame(0, $result['reifegrad']);
        self::assertSame(1, $result['data_protection']);
        self::assertSame('compliant', $req->getAssessmentStateDp());
    }

    #[Test]
    public function testBulkSetAssessment_RejectsReifegradValueOnDpRequirement(): void
    {
        $tenant = $this->createTenantWithId(1);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenant);
        $req->setCategory('data_protection');
        $req->setAssessmentStateDp(null);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        // flush must NOT be called — no valid write should happen
        $em->expects(self::never())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        // Supplying int 3 for a DP requirement is rejected
        $result  = $service->bulkSetAssessment([42 => 3], new User(), $tenant);

        self::assertSame(0, $result['reifegrad']);
        self::assertSame(0, $result['data_protection']);
        self::assertSame(1, $result['rejected']);
        self::assertNull($req->getAssessmentStateDp(), 'DP state must not change when a Reifegrad int is submitted for a DP requirement');
    }

    #[Test]
    public function testBulkSetAssessment_RejectsTriStateValueOnIsRequirement(): void
    {
        $tenant = $this->createTenantWithId(1);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenant);
        $req->setCategory('information_security');
        $req->setMaturityCurrent('incomplete');

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::never())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        // Supplying 'compliant' (a DP tristate string) for an IS requirement is rejected
        $result  = $service->bulkSetAssessment([42 => 'compliant'], new User(), $tenant);

        self::assertSame(0, $result['reifegrad']);
        self::assertSame(0, $result['data_protection']);
        self::assertSame(1, $result['rejected']);
        self::assertSame('incomplete', $req->getMaturityCurrent(), 'Maturity must not change when tristate string is submitted for IS requirement');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createTenantWithId(int $id): Tenant
    {
        $tenant = new Tenant();
        // Use reflection to set the private id field (no public setter on Tenant)
        $ref = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);
        return $tenant;
    }
}
