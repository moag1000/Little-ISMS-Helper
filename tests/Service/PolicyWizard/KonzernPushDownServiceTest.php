<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Repository\TenantPolicySettingRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\KonzernPushDownService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W3-B — KonzernPushDownService unit tests.
 *
 * Pure unit tests: no DB roundtrip. Uses stubbed repositories + a
 * recording AuditLogger double to assert structured events.
 */
#[AllowMockObjectsWithoutExpectations]
final class KonzernPushDownServiceTest extends TestCase
{
    /** @var array<int, array<string, TenantPolicySetting>> tenantId => key => setting */
    private array $store = [];

    /** @var list<array{action: string, entityType: string, entityId: ?int, oldValues: ?array, newValues: ?array, description: ?string}> */
    private array $auditCalls = [];

    protected function setUp(): void
    {
        $this->store = [];
        $this->auditCalls = [];
    }

    #[Test]
    public function propagateAffectsViolatingDescendants(): void
    {
        // Konzern raises crypto floor 128 -> 256. Subsidiary stored 192
        // (FloorOnly violation) — drift must be flagged.
        $tochter = $this->makeTenant(2, []);
        $konzern = $this->makeTenant(1, [], null, [$tochter]);

        $this->setStored($tochter, 'crypto.minimum_key_length', 192);

        $service = $this->makeService();

        $result = $service->propagate($konzern, 'crypto.minimum_key_length', 256);

        $this->assertSame([2], $result['affected_subsidiaries']);
        $this->assertSame(1, $result['alva_hints_emitted']);

        $stored = $this->store[2]['crypto.minimum_key_length']->getValue();
        $this->assertIsArray($stored);
        $this->assertArrayHasKey('_meta', $stored);
        $this->assertTrue($stored['_meta']['settings_drift_detected']);
        $this->assertSame(256, $stored['_meta']['drift_parent_value']);
        $this->assertSame(192, $stored['value']);
    }

    #[Test]
    public function propagateSkipsConformingDescendants(): void
    {
        // Subsidiary already at 384 (>= 256) — no drift.
        $tochter = $this->makeTenant(2, []);
        $konzern = $this->makeTenant(1, [], null, [$tochter]);

        $this->setStored($tochter, 'crypto.minimum_key_length', 384);

        $service = $this->makeService();

        $result = $service->propagate($konzern, 'crypto.minimum_key_length', 256);

        $this->assertSame([], $result['affected_subsidiaries']);
        $this->assertSame(0, $result['alva_hints_emitted']);

        $stored = $this->store[2]['crypto.minimum_key_length']->getValue();
        $this->assertSame(384, $stored, 'conforming descendant value untouched');
    }

    #[Test]
    public function propagateEmitsAlvaHints(): void
    {
        // Two violating descendants → 2 hints emitted.
        $a = $this->makeTenant(2, []);
        $b = $this->makeTenant(3, []);
        $konzern = $this->makeTenant(1, [], null, [$a, $b]);

        $this->setStored($a, 'crypto.minimum_key_length', 128);
        $this->setStored($b, 'crypto.minimum_key_length', 192);

        $service = $this->makeService();

        $result = $service->propagate($konzern, 'crypto.minimum_key_length', 256);

        $this->assertCount(2, $result['affected_subsidiaries']);
        $this->assertSame(2, $result['alva_hints_emitted']);
    }

    #[Test]
    public function propagateLogsAuditEvent(): void
    {
        $tochter = $this->makeTenant(2, []);
        $konzern = $this->makeTenant(1, [], null, [$tochter]);

        $this->setStored($tochter, 'crypto.minimum_key_length', 192);
        // Konzern's own pre-change persisted value (read for "old" snapshot)
        $this->setStored($konzern, 'crypto.minimum_key_length', 128);

        $service = $this->makeService();

        $service->propagate($konzern, 'crypto.minimum_key_length', 256);

        $this->assertCount(1, $this->auditCalls);
        $entry = $this->auditCalls[0];
        $this->assertSame('KonzernPushDown', $entry['action']);
        $this->assertSame('TenantPolicySetting', $entry['entityType']);
        $this->assertSame(1, $entry['entityId']);
        $this->assertSame(['value' => 128], $entry['oldValues']);
        $this->assertSame('crypto.minimum_key_length', $entry['newValues']['key']);
        $this->assertSame(256, $entry['newValues']['value']);
        $this->assertSame('floor_only', $entry['newValues']['override_mode']);
        $this->assertSame([2], $entry['newValues']['affected_tenants']);
    }

    #[Test]
    public function idempotentReRun(): void
    {
        // Second run with the same parent value must not emit fresh hints.
        $tochter = $this->makeTenant(2, []);
        $konzern = $this->makeTenant(1, [], null, [$tochter]);

        $this->setStored($tochter, 'crypto.minimum_key_length', 192);

        $service = $this->makeService();

        $first = $service->propagate($konzern, 'crypto.minimum_key_length', 256);
        $this->assertSame(1, $first['alva_hints_emitted']);
        $this->assertCount(1, $this->auditCalls);

        $second = $service->propagate($konzern, 'crypto.minimum_key_length', 256);

        $this->assertSame([2], $second['affected_subsidiaries'], 'descendant still violates');
        $this->assertSame(0, $second['alva_hints_emitted'], 're-run does not surface new hints');
        $this->assertCount(1, $this->auditCalls, 're-run does not write a duplicate audit entry');
    }

    // ----- helpers ----------------------------------------------------------

    private function makeService(): KonzernPushDownService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->makeRepoStub();
        $audit = $this->makeAuditLoggerStub();

        return new KonzernPushDownService(
            entityManager: $em,
            settingRepository: $repo,
            auditLogger: $audit,
        );
    }

    private function makeRepoStub(): TenantPolicySettingRepository
    {
        $repo = $this->createMock(TenantPolicySettingRepository::class);
        $store =& $this->store;

        $repo->method('findOneByTenantAndKey')->willReturnCallback(
            static function (Tenant $tenant, string $key) use (&$store): ?TenantPolicySetting {
                $tid = $tenant->getId();
                if ($tid === null) {
                    return null;
                }
                return $store[$tid][$key] ?? null;
            }
        );
        $repo->method('findByTenant')->willReturnCallback(
            static function (Tenant $tenant) use (&$store): array {
                $tid = $tenant->getId();
                if ($tid === null) {
                    return [];
                }
                return array_values($store[$tid] ?? []);
            }
        );

        return $repo;
    }

    private function makeAuditLoggerStub(): AuditLogger
    {
        $audit = $this->createMock(AuditLogger::class);
        $calls =& $this->auditCalls;
        $audit->method('logCustom')->willReturnCallback(
            static function (
                string $action,
                string $entityType,
                ?int $entityId = null,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?string $description = null,
                ?string $userName = null,
            ) use (&$calls): void {
                $calls[] = [
                    'action' => $action,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'oldValues' => $oldValues,
                    'newValues' => $newValues,
                    'description' => $description,
                ];
            }
        );
        return $audit;
    }

    /**
     * @param list<Tenant> $ancestors
     * @param list<Tenant> $subsidiaries
     */
    private function makeTenant(int $id, array $ancestors, ?Tenant $parent = null, array $subsidiaries = []): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getAllAncestors')->willReturn($ancestors);
        $stub->method('getParent')->willReturn($parent);
        $stub->method('getAllSubsidiaries')->willReturn($subsidiaries);
        return $stub;
    }

    private function setStored(Tenant $tenant, string $key, mixed $value): void
    {
        $tid = $tenant->getId();
        $setting = new TenantPolicySetting();
        $setting->setTenant($tenant);
        $setting->setKey($key);
        $setting->setValue($value);
        $this->store[$tid][$key] = $setting;
    }
}
