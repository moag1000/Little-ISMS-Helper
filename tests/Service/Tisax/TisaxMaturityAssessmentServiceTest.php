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
    // Per-tier assessment model tests
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function category_model_map_covers_all_three_tiers(): void
    {
        $map = TisaxMaturityAssessmentService::CATEGORY_MODEL_MAP;
        self::assertArrayHasKey('information_security', $map);
        self::assertArrayHasKey('prototype_protection', $map);
        self::assertArrayHasKey('data_protection', $map);
        self::assertSame('information_security', $map['information_security']);
        self::assertSame('prototype_protection', $map['prototype_protection']);
        self::assertSame('data_protection', $map['data_protection']);
    }

    #[Test]
    public function getAssessmentModelForCategory_returns_correct_model(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new TisaxMaturityAssessmentService($em);

        self::assertSame('information_security', $service->getAssessmentModelForCategory('information_security'));
        self::assertSame('prototype_protection', $service->getAssessmentModelForCategory('prototype_protection'));
        self::assertSame('data_protection', $service->getAssessmentModelForCategory('data_protection'));
        // Unknown category falls back to IS model
        self::assertSame('information_security', $service->getAssessmentModelForCategory('unknown_tier'));
    }

    #[Test]
    public function testBulkSetCompliance_BinaryModel_AcceptsCompliantValue(): void
    {
        $tenant = $this->createTenantWithId(1);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenant);
        $req->setCategory('prototype_protection');
        $req->setAssessmentValue(null);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::once())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        $user    = new User();

        $updated = $service->bulkSetCompliance([42 => 'compliant'], $user, $tenant, 'prototype_protection');

        self::assertSame(1, $updated, 'One PP-tier requirement must be updated');
        self::assertSame('compliant', $req->getAssessmentValue(), 'assessmentValue must be "compliant"');
    }

    #[Test]
    public function testBulkSetCompliance_GdprModel_AcceptsInPlaceValue(): void
    {
        $tenant = $this->createTenantWithId(1);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenant);
        $req->setCategory('data_protection');
        $req->setAssessmentValue(null);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::once())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        $user    = new User();

        $updated = $service->bulkSetCompliance([99 => 'in_place'], $user, $tenant, 'data_protection');

        self::assertSame(1, $updated, 'One DP-tier requirement must be updated');
        self::assertSame('in_place', $req->getAssessmentValue(), 'assessmentValue must be "in_place"');
    }

    #[Test]
    public function testBulkSetCompliance_RejectsCrossModelValue(): void
    {
        // Submitting a Reifegrad string 'established' (IS-model value) for a PP-tier requirement
        $tenant = $this->createTenantWithId(1);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenant);
        $req->setCategory('prototype_protection');
        $req->setAssessmentValue(null);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        // flush must NOT be called — cross-model value is silently rejected
        $em->expects(self::never())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        $user    = new User();

        // 'established' is a valid IS-tier value but invalid for prototype_protection
        $updated = $service->bulkSetCompliance([42 => 'established'], $user, $tenant, 'prototype_protection');

        self::assertSame(0, $updated, 'Cross-model value must be silently rejected');
        self::assertNull($req->getAssessmentValue(), 'assessmentValue must remain null');
    }

    #[Test]
    public function testBulkSetCompliance_RejectsIsValueForDpTier(): void
    {
        // Submitting numeric-string IS value '3' for a DP-tier requirement
        $tenant = $this->createTenantWithId(1);

        $req = new ComplianceRequirement();
        $req->setUploadTenant($tenant);
        $req->setCategory('data_protection');
        $req->setAssessmentValue(null);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($req);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::never())->method('flush');

        $service = new TisaxMaturityAssessmentService($em);
        $user    = new User();

        // 'managed' is valid for IS model but not for data_protection
        $updated = $service->bulkSetCompliance([99 => 'managed'], $user, $tenant, 'data_protection');

        self::assertSame(0, $updated, 'IS value submitted against DP model must be rejected');
        self::assertNull($req->getAssessmentValue(), 'assessmentValue must remain null');
    }

    #[Test]
    public function testBulkSetCompliance_UnknownModel_ThrowsInvalidArgumentException(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new TisaxMaturityAssessmentService($em);
        $user    = new User();
        $tenant  = $this->createTenantWithId(1);

        $this->expectException(\InvalidArgumentException::class);
        $service->bulkSetCompliance([1 => 'compliant'], $user, $tenant, 'unknown_model');
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
