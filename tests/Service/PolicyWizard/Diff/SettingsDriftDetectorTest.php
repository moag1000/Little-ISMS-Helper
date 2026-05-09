<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Diff;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Repository\TenantPolicySettingRepository;
use App\Service\PolicyWizard\Diff\SettingsDriftDetector;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Policy-Wizard W7-C — SettingsDriftDetector unit tests.
 *
 * Covers the three contract guarantees:
 *  1. Drift fires when a tenant-resolved setting now differs.
 *  2. No false-positive when settings still match.
 *  3. Defensive degradation: missing snapshot/repo never throws.
 */
#[AllowMockObjectsWithoutExpectations]
final class SettingsDriftDetectorTest extends TestCase
{
    private function makeTenant(int $id = 7, ?string $legalName = 'Old Co GmbH'): Tenant
    {
        $t = new Tenant();
        $t->setName('Old Co');
        $t->setCode('test_' . $id);
        $t->setLegalName($legalName);
        $reflection = new ReflectionProperty(Tenant::class, 'id');
        $reflection->setValue($t, $id);
        return $t;
    }

    private function makeDocument(Tenant $tenant, ?array $variables): Document
    {
        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename('policy.md');
        $doc->setOriginalFilename('policy.md');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(123);
        $doc->setFilePath('virtual:test');
        $doc->setCategory('policy');
        $doc->setStatus('approved');
        if ($variables !== null) {
            $doc->setSubstitutionVariables($variables);
        }
        return $doc;
    }

    private function makeRepo(?TenantPolicySetting $setting): TenantPolicySettingRepository
    {
        $repo = $this->createMock(TenantPolicySettingRepository::class);
        $repo->method('findOneByTenantAndKey')->willReturn($setting);
        return $repo;
    }

    #[Test]
    public function testDriftDetectedWhenSettingChanged(): void
    {
        // Snapshot legal_name = "Old Co GmbH"; tenant now reports "New Co GmbH".
        $tenant = $this->makeTenant(legalName: 'New Co GmbH');
        $doc = $this->makeDocument($tenant, [
            'tenant.legal_name' => 'Old Co GmbH',
            'tenant.id' => 7,
        ]);

        $detector = new SettingsDriftDetector(/* repo */ null);
        self::assertTrue($detector->detectDriftFor($doc, $tenant), 'changed legal_name must register drift');
    }

    #[Test]
    public function testNoDriftWhenSettingsMatch(): void
    {
        $tenant = $this->makeTenant(legalName: 'Stable Co GmbH');
        $doc = $this->makeDocument($tenant, [
            'tenant.legal_name' => 'Stable Co GmbH',
            'tenant.id' => 7,
            'tenant.scope_statement' => 'Whole company.',
        ]);

        // Settings repo returns a matching scope_statement.
        $setting = new TenantPolicySetting();
        $setting->setKey('isms.scope_statement');
        $setting->setValue('Whole company.');

        $detector = new SettingsDriftDetector($this->makeRepo($setting));
        self::assertFalse($detector->detectDriftFor($doc, $tenant), 'matching settings must not register drift');
    }

    #[Test]
    public function testHandlesNullSubstitutionVariables(): void
    {
        $tenant = $this->makeTenant();
        // Document with no snapshot at all (manually uploaded, not wizard-generated).
        $doc = $this->makeDocument($tenant, null);

        $detector = new SettingsDriftDetector($this->makeRepo(null));
        self::assertFalse(
            $detector->detectDriftFor($doc, $tenant),
            'documents without a snapshot must degrade to no-drift, not throw',
        );

        // And with an empty snapshot map.
        $emptyDoc = $this->makeDocument($tenant, []);
        self::assertFalse(
            $detector->detectDriftFor($emptyDoc, $tenant),
            'empty snapshot maps must degrade to no-drift',
        );

        // And without a tenant on the document.
        $orphan = $this->makeDocument($tenant, ['tenant.legal_name' => 'X']);
        $orphan->setTenant(null);
        self::assertFalse(
            $detector->detectDriftFor($orphan),
            'tenant-less documents must degrade to no-drift',
        );
    }
}
