<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Service\Admin\AdminHubCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdminHubCatalogTest extends TestCase
{
    private AdminHubCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new AdminHubCatalog();
    }

    #[Test]
    public function returnsExpectedGroups(): void
    {
        $groups = $this->catalog->getGroups();

        $this->assertCount(8, $groups);
        $this->assertSame(
            ['organisation', 'identity', 'isms_data', 'audit_compliance', 'notifications', 'integrations', 'system', 'branding_ux'],
            array_map(static fn(array $group): string => $group['key'], $groups),
        );
    }

    #[Test]
    public function everyGroupCarriesToneAndModules(): void
    {
        foreach ($this->catalog->getGroups() as $group) {
            $this->assertContains(
                $group['tone'],
                ['cyan', 'pink', 'purple'],
                sprintf('Group %s has unexpected tone %s', $group['key'], $group['tone']),
            );
            $this->assertNotEmpty($group['modules'], sprintf('Group %s has no modules', $group['key']));
        }
    }

    #[Test]
    public function moduleEntriesCarryRequiredFields(): void
    {
        foreach ($this->catalog->getGroups() as $group) {
            foreach ($group['modules'] as $module) {
                $this->assertArrayHasKey('key', $module);
                $this->assertArrayHasKey('icon', $module);
                $this->assertArrayHasKey('label', $module);
                $this->assertArrayHasKey('description', $module);
                $this->assertArrayHasKey('route', $module);
                $this->assertNotEmpty($module['key']);
                $this->assertNotEmpty($module['icon']);
            }
        }
    }

    #[Test]
    public function moduleKeysAreGloballyUnique(): void
    {
        $keys = [];
        foreach ($this->catalog->getGroups() as $group) {
            foreach ($group['modules'] as $module) {
                $keys[] = $module['key'];
            }
        }
        $this->assertSame(count($keys), count(array_unique($keys)), 'Duplicate module keys: ' . implode(', ', array_diff_assoc($keys, array_unique($keys))));
    }

    #[Test]
    public function totalsAroundDocumentedSize(): void
    {
        $count = 0;
        foreach ($this->catalog->getGroups() as $group) {
            $count += count($group['modules']);
        }
        // Documented intent: ~36 modules across 7 groups (raised to 75 after
        // notifications group + Sprint 6 deferred settings + Sprint 8 EU-Authority
        // tiles landed in May 2026 — 8 groups, ~59 modules).
        $this->assertGreaterThanOrEqual(30, $count);
        $this->assertLessThanOrEqual(75, $count);
    }

    #[Test]
    public function everyIconValueIsAuroraPrefixed(): void
    {
        foreach ($this->catalog->getGroups() as $group) {
            $this->assertStringStartsWith(
                'fa-icon--',
                $group['icon'],
                sprintf('Group "%s" icon "%s" must be an Aurora class (fa-icon--*)', $group['key'], $group['icon']),
            );
            foreach ($group['modules'] as $module) {
                $this->assertStringStartsWith(
                    'fa-icon--',
                    $module['icon'],
                    sprintf(
                        'Module "%s" (group "%s") icon "%s" must be an Aurora class (fa-icon--*)',
                        $module['key'],
                        $group['key'],
                        $module['icon'],
                    ),
                );
            }
        }
    }

    // ----------------------------------------------------------------
    // Role-Scope Architecture Phase 2 — requiredAttribute annotations
    // ----------------------------------------------------------------

    #[Test]
    public function everyModuleCarriesARequiredAttribute(): void
    {
        $missing = [];
        foreach ($this->catalog->getGroups() as $group) {
            foreach ($group['modules'] as $module) {
                if (empty($module['requiredAttribute']) && empty($module['requiredRole'])) {
                    $missing[] = $group['key'] . '.' . $module['key'];
                }
            }
        }
        $this->assertSame(
            [],
            $missing,
            'Phase 2: every hub module must declare requiredAttribute or requiredRole. Missing: '
                . implode(', ', $missing),
        );
    }

    #[Test]
    public function requiredAttributeValuesAreSupportedVoterAttributes(): void
    {
        $allowed = [
            'ADMIN_OWN_TENANT',
            'ADMIN_ANY_TENANT',
            'ADMIN_GLOBAL_OP',
            'ADMIN_HOLDING_READ',
            'PERSONA_CISO',
            'PERSONA_RISK',
            'PERSONA_DPO',
            'PERSONA_COMPLIANCE',
        ];
        foreach ($this->catalog->getGroups() as $group) {
            foreach ($group['modules'] as $module) {
                if (!empty($module['requiredAttribute'])) {
                    $this->assertContains(
                        $module['requiredAttribute'],
                        $allowed,
                        sprintf(
                            'Module "%s" (group "%s") has unknown requiredAttribute "%s".',
                            $module['key'],
                            $group['key'],
                            $module['requiredAttribute'],
                        ),
                    );
                }
            }
        }
    }

    #[Test]
    public function knownGlobalOnlyModulesAreFlaggedAsGlobalOp(): void
    {
        $expectedGlobal = [
            'tour_content',
            'tour_completion',
            'licensing',
            'setup_wizard',
            'api_rate_limits',
            'data_retention_settings',
            'workflow_sla_defaults',
            'lifecycle_overrides',
            'industry_baselines',
            'monitoring_performance',
            'monitoring_errors',
            'system_health',
            'loader_fixer',
        ];
        $byKey = [];
        foreach ($this->catalog->getGroups() as $group) {
            foreach ($group['modules'] as $module) {
                $byKey[$module['key']] = $module;
            }
        }
        foreach ($expectedGlobal as $key) {
            $this->assertArrayHasKey($key, $byKey, sprintf('Catalog must still contain module "%s".', $key));
            $this->assertSame(
                'ADMIN_GLOBAL_OP',
                $byKey[$key]['requiredAttribute'] ?? null,
                sprintf('Module "%s" must be flagged ADMIN_GLOBAL_OP.', $key),
            );
        }
    }

    #[Test]
    public function knownOwnTenantModulesAreFlaggedAsOwnTenant(): void
    {
        $expectedOwn = [
            'tenants',
            'users',
            'roles',
            'sso',
            'frameworks',
            'mappings',
            'industry_preset',
            'data_backup',
            'data_repair',
            'kpi_threshold',
            'modules',
            'tenant_compliance_settings',
            'compliance_policy',
            'risk_approval_config',
            'incident_sla',
            'audit_log',
            'audit_retention',
            'workflow_overlay',
            'notification_rules',
            'notification_channels',
            'notification_templates',
            'compliance_import',
            'gstool_import',
            'sample_data',
        ];
        $byKey = [];
        foreach ($this->catalog->getGroups() as $group) {
            foreach ($group['modules'] as $module) {
                $byKey[$module['key']] = $module;
            }
        }
        foreach ($expectedOwn as $key) {
            $this->assertArrayHasKey($key, $byKey, sprintf('Catalog must still contain module "%s".', $key));
            $this->assertSame(
                'ADMIN_OWN_TENANT',
                $byKey[$key]['requiredAttribute'] ?? null,
                sprintf('Module "%s" must be flagged ADMIN_OWN_TENANT.', $key),
            );
        }
    }
}
