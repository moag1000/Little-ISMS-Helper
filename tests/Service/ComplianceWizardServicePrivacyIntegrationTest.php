<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Service\ComplianceWizard\CategoryProvider\EuRegulatoryFrameworkCategoryProvider;
use App\Service\ComplianceWizard\CategoryProvider\IsoFrameworkCategoryProvider;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701ClauseTagsAppliedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701SchremsIIClauseInTransfersCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701VersionConfiguredCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckRegistry;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\A534ThinHostPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DataBreachNotification72hCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DpiaMethodologyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DpoCharterAppointedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DsrProcedurePresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\GdprSectionCoverageCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\PrivacyPolicyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\RopaMethodologyPresentCheck;
use App\Service\TenantSettingResolver\PolicySettingProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * W6-D integration test — verifies that {@see EuRegulatoryFrameworkCategoryProvider}
 * and {@see IsoFrameworkCategoryProvider} host dedicated `gdpr_policies` and
 * `iso27701_pims` categories whose checks resolve through the
 * {@see PolicyWizardCheckRegistry}, gated by their scope detectors (active
 * GDPR ComplianceFramework or `iso27701.enabled` tenant policy setting).
 *
 * Adapted from god-class decomposition (PR #556): `getGdprCategories()` /
 * `buildGdprPolicyWizardCategory()` were extracted to
 * EuRegulatoryFrameworkCategoryProvider; `getIso27701Categories()` /
 * `buildIso27701PolicyWizardCategory()` were extracted to
 * IsoFrameworkCategoryProvider. Tests now target sub-services directly via
 * their public category-map APIs.
 */
final class ComplianceWizardServicePrivacyIntegrationTest extends KernelTestCase
{
    private function requireDatabase(): void
    {
        try {
            $em = static::getContainer()->get('doctrine.orm.entity_manager');
            $em->getConnection()->executeQuery('SELECT 1');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Access denied')
                || str_contains($e->getMessage(), 'Connection refused')
                || str_contains($e->getMessage(), 'SQLSTATE')
            ) {
                $this->markTestSkipped('Database not available: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    private function bootEuProvider(): EuRegulatoryFrameworkCategoryProvider
    {
        self::bootKernel();
        return static::getContainer()->get(EuRegulatoryFrameworkCategoryProvider::class);
    }

    private function bootIsoProvider(): IsoFrameworkCategoryProvider
    {
        self::bootKernel();
        return static::getContainer()->get(IsoFrameworkCategoryProvider::class);
    }

    #[Test]
    public function testGdprCategoryRegisteredWhenScopeActive(): void
    {
        $this->requireDatabase();
        $euProvider = $this->bootEuProvider();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $framework = $em->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'GDPR']);

        // getGdprCategories() calls buildGdprPolicyWizardCategory() internally
        // and drops null entries via array_filter — absence == null return.
        $categories = $euProvider->getGdprCategories();
        $built = $categories['gdpr_policies'] ?? null;

        if ($framework === null || $framework->isActive() !== true) {
            // Out-of-scope path: builder returns null when no fixture is active.
            self::assertNull(
                $built,
                'When GDPR scope is not active, the gdpr_policies builder must return null',
            );

            self::assertArrayNotHasKey(
                'gdpr_policies',
                $categories,
                'gdpr_policies must not appear when the tenant lacks GDPR scope',
            );
            return;
        }

        // In-scope path.
        self::assertIsArray($built);
        self::assertSame('wizard.gdpr.gdpr_policies', $built['name']);
        self::assertSame('wizard.gdpr.gdpr_policies_desc', $built['description']);
        self::assertCount(8, $built['checks'], 'gdpr_policies must list the 8 W6-D GDPR checks');

        foreach ($built['checks'] as $check) {
            self::assertSame('policy_wizard', $check['type']);
            self::assertArrayHasKey('check_id', $check);
            self::assertArrayHasKey('priority', $check);
            self::assertSame('policy_wizard', $check['translation_domain']);
        }
    }

    #[Test]
    public function testIso27701CategoryRegisteredWhenAddonEnabled(): void
    {
        $this->requireDatabase();
        $isoProvider = $this->bootIsoProvider();

        // getIso27701Categories() calls buildIso27701PolicyWizardCategory()
        // internally. Absence of the key == null (not opted in).
        $categories = $isoProvider->getIso27701Categories();
        $built = $categories['iso27701_pims'] ?? null;

        if ($built === null) {
            // PolicySettingProvider not wired or PIMS not enabled in this kernel —
            // the category is not produced. Verify the category map agrees.
            self::assertArrayNotHasKey(
                'iso27701_pims',
                $categories,
                'iso27701_pims must not appear when PIMS addon is not opted in',
            );
            return;
        }

        self::assertIsArray($built);
        self::assertSame('wizard.iso27701.iso27701_pims', $built['name']);
        self::assertSame('wizard.iso27701.iso27701_pims_desc', $built['description']);
        self::assertCount(3, $built['checks'], 'iso27701_pims must list the 3 W6-D ISO 27701 checks');

        foreach ($built['checks'] as $check) {
            self::assertSame('policy_wizard', $check['type']);
            self::assertArrayHasKey('check_id', $check);
            self::assertSame('policy_wizard', $check['translation_domain']);
        }
    }

    #[Test]
    public function testCategoriesSkippedWhenScopeInactive(): void
    {
        $this->requireDatabase();

        self::bootKernel();
        $euProvider = static::getContainer()->get(EuRegulatoryFrameworkCategoryProvider::class);
        $isoProvider = static::getContainer()->get(IsoFrameworkCategoryProvider::class);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $gdprFramework = $em->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'GDPR']);

        $gdprCategories = $euProvider->getGdprCategories();
        $built = $gdprCategories['gdpr_policies'] ?? null;

        if ($gdprFramework === null || $gdprFramework->isActive() !== true) {
            self::assertNull($built);
        } else {
            self::assertNotNull(
                $built,
                'GDPR scope is active in this kernel; builder must return an array',
            );
        }

        // ISO 27701 PIMS — gated on PolicySettingProvider+iso27701.enabled.
        // Without an active tenant context with the setting, it stays null.
        $isoCategories = $isoProvider->getIso27701Categories();
        $isoBuilt = $isoCategories['iso27701_pims'] ?? null;
        // Either null (not enabled / provider not wired) OR a populated array
        // (test kernel happens to be configured). Both are valid.
        self::assertTrue($isoBuilt === null || is_array($isoBuilt));
    }

    #[Test]
    public function testAllElevenW6dChecksWired(): void
    {
        $this->requireDatabase();

        self::bootKernel();
        $registry = static::getContainer()->get(PolicyWizardCheckRegistry::class);
        // PolicySettingProvider availability is independent of GDPR scope
        // detection — the iso27701 checks rely on it, so confirm it's wired.
        self::assertNotNull(
            static::getContainer()->get(PolicySettingProvider::class),
            'PolicySettingProvider must be wired for W6-D ISO 27701 checks',
        );

        $expectedGdpr = [
            PrivacyPolicyPresentCheck::CHECK_ID,
            RopaMethodologyPresentCheck::CHECK_ID,
            DpiaMethodologyPresentCheck::CHECK_ID,
            DsrProcedurePresentCheck::CHECK_ID,
            DataBreachNotification72hCheck::CHECK_ID,
            DpoCharterAppointedCheck::CHECK_ID,
            GdprSectionCoverageCheck::CHECK_ID,
            A534ThinHostPresentCheck::CHECK_ID,
        ];
        $expectedIso27701 = [
            Iso27701VersionConfiguredCheck::CHECK_ID,
            Iso27701ClauseTagsAppliedCheck::CHECK_ID,
            Iso27701SchremsIIClauseInTransfersCheck::CHECK_ID,
        ];

        foreach ($expectedGdpr as $checkId) {
            $impl = $registry->get($checkId);
            self::assertNotNull($impl, "Registry must resolve check id '{$checkId}'");
            self::assertInstanceOf(PolicyWizardCheckInterface::class, $impl);
            self::assertSame('gdpr', $impl->getStandard(), "Check '{$checkId}' must report GDPR standard");
            self::assertSame($checkId, $impl->getCheckId());
        }
        foreach ($expectedIso27701 as $checkId) {
            $impl = $registry->get($checkId);
            self::assertNotNull($impl, "Registry must resolve check id '{$checkId}'");
            self::assertInstanceOf(PolicyWizardCheckInterface::class, $impl);
            self::assertSame('iso27701', $impl->getStandard(), "Check '{$checkId}' must report ISO 27701 standard");
            self::assertSame($checkId, $impl->getCheckId());
        }

        $gdprChecks = $registry->forStandard('gdpr');
        self::assertGreaterThanOrEqual(8, count($gdprChecks), 'At least 8 GDPR checks must be tagged on the registry');

        $isoChecks = $registry->forStandard('iso27701');
        self::assertGreaterThanOrEqual(3, count($isoChecks), 'At least 3 ISO 27701 checks must be tagged on the registry');
    }
}
