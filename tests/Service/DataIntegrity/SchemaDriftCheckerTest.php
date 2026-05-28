<?php

declare(strict_types=1);

namespace App\Tests\Service\DataIntegrity;

use App\Repository\TenantRepository;
use App\Service\DataIntegrity\SchemaDriftChecker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SchemaDriftCheckerTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $tenantRepository;
    private SchemaDriftChecker $checker;

    protected function setUp(): void
    {
        $this->entityManager    = $this->createMock(EntityManagerInterface::class);
        $this->tenantRepository = $this->createMock(TenantRepository::class);
        $this->checker          = new SchemaDriftChecker($this->entityManager, $this->tenantRepository);
    }

    // ────────────────────────────────────────────────────────────────────────
    // findJsonSchemaViolations — each block is wrapped in try/catch,
    // so we verify the return shape and graceful degradation.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function find_json_schema_violations_returns_four_keyed_array(): void
    {
        $this->tenantRepository->method('findAll')->willReturn([]);
        $this->entityManager->method('createQueryBuilder')->willThrowException(new \RuntimeException('no DB'));

        $result = $this->checker->findJsonSchemaViolations();

        self::assertArrayHasKey('tenant_settings', $result);
        self::assertArrayHasKey('tenant_policy_settings', $result);
        self::assertArrayHasKey('notification_rule_conditions', $result);
        self::assertArrayHasKey('workflow_step_metadata', $result);
    }

    #[Test]
    public function find_json_schema_violations_detects_non_object_tenant_settings(): void
    {
        $tenant = $this->createMock(\App\Entity\Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $tenant->method('getName')->willReturn('Test Tenant');
        // Returning a list (not k/v map) is a violation
        $tenant->method('getSettings')->willReturn(['item1', 'item2']);

        $this->tenantRepository->method('findAll')->willReturn([$tenant]);
        $this->entityManager->method('createQueryBuilder')->willThrowException(new \RuntimeException('no DB'));

        $result = $this->checker->findJsonSchemaViolations();

        self::assertNotEmpty($result['tenant_settings']);
        self::assertStringContainsString('list', $result['tenant_settings'][0]['error']);
    }

    #[Test]
    public function find_json_schema_violations_accepts_valid_kv_settings(): void
    {
        $tenant = $this->createMock(\App\Entity\Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $tenant->method('getName')->willReturn('Test Tenant');
        $tenant->method('getSettings')->willReturn(['key' => 'value', 'another' => 'setting']);

        $this->tenantRepository->method('findAll')->willReturn([$tenant]);
        $this->entityManager->method('createQueryBuilder')->willThrowException(new \RuntimeException('no DB'));

        $result = $this->checker->findJsonSchemaViolations();

        self::assertSame([], $result['tenant_settings']);
    }

    #[Test]
    public function find_json_schema_violations_accepts_null_tenant_settings(): void
    {
        $tenant = $this->createMock(\App\Entity\Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $tenant->method('getName')->willReturn('Test Tenant');
        $tenant->method('getSettings')->willReturn(null);

        $this->tenantRepository->method('findAll')->willReturn([$tenant]);
        $this->entityManager->method('createQueryBuilder')->willThrowException(new \RuntimeException('no DB'));

        $result = $this->checker->findJsonSchemaViolations();

        self::assertSame([], $result['tenant_settings']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // findAuditLogIntegrityIssues — requires DBAL Connection, which we can
    // mock to throw so all three sub-checks gracefully return empty.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function find_audit_log_integrity_issues_returns_three_keyed_array(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willThrowException(new \RuntimeException('no table'));
        $this->entityManager->method('getConnection')->willReturn($connection);

        $result = $this->checker->findAuditLogIntegrityIssues();

        self::assertArrayHasKey('bulk_batch_mismatches', $result);
        self::assertArrayHasKey('day_gaps', $result);
        self::assertArrayHasKey('null_tenant_entries', $result);

        // All should be empty arrays (all checks skipped silently)
        self::assertSame([], $result['bulk_batch_mismatches']);
        self::assertSame([], $result['day_gaps']);
        self::assertSame([], $result['null_tenant_entries']);
    }
}
