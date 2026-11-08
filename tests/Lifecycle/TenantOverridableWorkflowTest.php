<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * /admin/lifecycle-overrides listed every workflow YAML, including tenant, role
 * and permission. An organisation admin could lower the roles of
 * tenant_lifecycle.suspend for the own organisation — the override was then
 * applied to any subject, so another organisation could be suspended; roles and
 * permissions are instance-wide. Overrides apply only to organisation-owned
 * records (LifecycleConfigResolver + LifecycleOverridesController).
 */
final class TenantOverridableWorkflowTest extends TestCase
{
    #[Test]
    public function organisationRecordsAcceptOverrides(): void
    {
        foreach (['document_lifecycle', 'risk_lifecycle', 'asset_lifecycle', 'management_decision_lifecycle'] as $wf) {
            self::assertTrue(EntityTypeRegistry::acceptsTenantOverrides($wf), $wf);
        }
    }

    #[Test]
    public function instanceRecordsNeverAcceptOverrides(): void
    {
        foreach ([
            'tenant_lifecycle',
            'role_lifecycle',
            'permission_lifecycle',
            'policy_template_lifecycle',
            'compliance_mapping_lifecycle',
            'unknown_lifecycle',
        ] as $wf) {
            self::assertFalse(EntityTypeRegistry::acceptsTenantOverrides($wf), $wf);
        }
    }

    #[Test]
    public function everyWorkflowFileIsClassified(): void
    {
        // A new workflow YAML of an organisation-owned entity must be registered,
        // otherwise its overrides would silently stop working.
        $registry = new EntityTypeRegistry();
        $registered = [];
        foreach ($registry->knownSlugs() as $slug) {
            $registered[] = $registry->lookup($slug)['workflow'];
        }
        foreach (glob(\dirname(__DIR__, 2) . '/config/workflows/*.yaml') ?: [] as $file) {
            $name = basename($file, '.yaml');
            $name = str_ends_with($name, '_lifecycle') ? $name : $name . '_lifecycle';
            if ($name === 'compliance_mapping_lifecycle') {
                continue; // global catalogue data, deliberately outside the registry
            }
            self::assertContains($name, $registered, $name . ' is missing in EntityTypeRegistry::MAP');
        }
    }
}
