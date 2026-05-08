<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmBiaMethodologyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmCrisisManagementPlanPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmExerciseProgrammeActiveCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmManagementReviewBcmCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmRecoveryPlansPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmsScopeStatementPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmTopLevelPolicyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiBaselineCoverageCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiIsmsConceptPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiKritisFlagDocumentedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiSchutzbedarfMethodPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiTierConsistencyCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiTopLevelLeitliniePresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckRegistry;
use App\Service\ComplianceWizardService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * W5-C integration test — verifies that {@see ComplianceWizardService} hosts
 * dedicated `bsi_policies` and `bcm_policies` categories whose checks resolve
 * through the {@see PolicyWizardCheckRegistry}, gated by their respective
 * scope detectors (active ComplianceFramework or tenant-policy-setting).
 *
 * Mirrors the W4-D integration-test pattern: boots the kernel for the real
 * wiring then reflects into private category builders for surgical assertions.
 */
final class ComplianceWizardServiceBsiBcmIntegrationTest extends KernelTestCase
{
    private function requireDatabase(): void
    {
        try {
            $em = static::getContainer()->get('doctrine.orm.entity_manager');
            $em->getConnection()->executeQuery('SELECT 1');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Access denied') ||
                str_contains($e->getMessage(), 'Connection refused') ||
                str_contains($e->getMessage(), 'SQLSTATE')) {
                $this->markTestSkipped('Database not available: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    private function bootService(): ComplianceWizardService
    {
        self::bootKernel();
        $container = static::getContainer();
        return $container->get(ComplianceWizardService::class);
    }

    #[Test]
    public function testBsiCategoryRegisteredWhenScopeActive(): void
    {
        $this->requireDatabase();
        $service = $this->bootService();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $framework = $em->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);

        $reflection = new \ReflectionMethod($service, 'buildBsiPolicyWizardCategory');
        $built = $reflection->invoke($service);

        if ($framework === null || $framework->isActive() !== true) {
            // Out-of-scope path: no fixture + no tenant policy setting →
            // builder returns null, category never enters the map.
            self::assertNull(
                $built,
                'When BSI scope is not active, the bsi_policies builder must return null',
            );

            $categories = (new \ReflectionMethod($service, 'getBsiGrundschutzCategories'))
                ->invoke($service);
            self::assertArrayNotHasKey(
                'bsi_policies',
                $categories,
                'bsi_policies must not appear when the tenant lacks BSI scope',
            );
            return;
        }

        // In-scope path: builder returns the populated category structure.
        self::assertIsArray($built);
        self::assertSame('wizard.bsi.bsi_policies', $built['name']);
        self::assertSame('wizard.bsi.bsi_policies_desc', $built['description']);
        self::assertCount(6, $built['checks'], 'bsi_policies must list the 6 W5-C BSI checks');

        foreach ($built['checks'] as $check) {
            self::assertSame('policy_wizard', $check['type']);
            self::assertArrayHasKey('check_id', $check);
            self::assertArrayHasKey('priority', $check);
            self::assertSame('policy_wizard', $check['translation_domain']);
        }
    }

    #[Test]
    public function testBcmCategoryRegisteredWhenScopeActive(): void
    {
        $this->requireDatabase();
        $service = $this->bootService();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $framework = $em->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'ISO_22301']);

        $reflection = new \ReflectionMethod($service, 'buildBcmPolicyWizardCategory');
        $built = $reflection->invoke($service);

        if ($framework === null || $framework->isActive() !== true) {
            self::assertNull(
                $built,
                'When BCM scope is not active, the bcm_policies builder must return null',
            );

            $categories = (new \ReflectionMethod($service, 'getIso22301Categories'))
                ->invoke($service);
            self::assertArrayNotHasKey(
                'bcm_policies',
                $categories,
                'bcm_policies must not appear when the tenant lacks BCM scope',
            );
            return;
        }

        self::assertIsArray($built);
        self::assertSame('wizard.bcm.bcm_policies', $built['name']);
        self::assertSame('wizard.bcm.bcm_policies_desc', $built['description']);
        self::assertCount(7, $built['checks'], 'bcm_policies must list the 7 W5-C BCM checks');

        foreach ($built['checks'] as $check) {
            self::assertSame('policy_wizard', $check['type']);
            self::assertArrayHasKey('check_id', $check);
            self::assertArrayHasKey('priority', $check);
            self::assertSame('policy_wizard', $check['translation_domain']);
        }
    }

    #[Test]
    public function testCategoriesSkippedWhenScopeInactive(): void
    {
        $this->requireDatabase();
        $service = $this->bootService();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $bsiFramework = $em->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);
        $bcmFramework = $em->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'ISO_22301']);

        // For frameworks not active in this kernel, the builders MUST return
        // null. For frameworks that ARE active, the test exercises the
        // happy path elsewhere — here we simply pin the contract.
        if ($bsiFramework === null || $bsiFramework->isActive() !== true) {
            $bsiBuilt = (new \ReflectionMethod($service, 'buildBsiPolicyWizardCategory'))
                ->invoke($service);
            self::assertNull($bsiBuilt);
        } else {
            self::assertNotNull(
                (new \ReflectionMethod($service, 'buildBsiPolicyWizardCategory'))->invoke($service),
                'BSI scope is active in this kernel; builder must return an array',
            );
        }

        if ($bcmFramework === null || $bcmFramework->isActive() !== true) {
            $bcmBuilt = (new \ReflectionMethod($service, 'buildBcmPolicyWizardCategory'))
                ->invoke($service);
            self::assertNull($bcmBuilt);
        } else {
            self::assertNotNull(
                (new \ReflectionMethod($service, 'buildBcmPolicyWizardCategory'))->invoke($service),
                'BCM scope is active in this kernel; builder must return an array',
            );
        }
    }

    #[Test]
    public function testAllThirteenChecksWired(): void
    {
        $this->requireDatabase();

        self::bootKernel();
        $registry = static::getContainer()->get(PolicyWizardCheckRegistry::class);

        $expectedBsi = [
            BsiTopLevelLeitliniePresentCheck::CHECK_ID,
            BsiIsmsConceptPresentCheck::CHECK_ID,
            BsiBaselineCoverageCheck::CHECK_ID,
            BsiSchutzbedarfMethodPresentCheck::CHECK_ID,
            BsiTierConsistencyCheck::CHECK_ID,
            BsiKritisFlagDocumentedCheck::CHECK_ID,
        ];
        $expectedBcm = [
            BcmTopLevelPolicyPresentCheck::CHECK_ID,
            BcmsScopeStatementPresentCheck::CHECK_ID,
            BcmBiaMethodologyPresentCheck::CHECK_ID,
            BcmExerciseProgrammeActiveCheck::CHECK_ID,
            BcmCrisisManagementPlanPresentCheck::CHECK_ID,
            BcmRecoveryPlansPresentCheck::CHECK_ID,
            BcmManagementReviewBcmCheck::CHECK_ID,
        ];

        foreach ($expectedBsi as $checkId) {
            $impl = $registry->get($checkId);
            self::assertNotNull($impl, "Registry must resolve check id '{$checkId}'");
            self::assertInstanceOf(PolicyWizardCheckInterface::class, $impl);
            self::assertSame('bsi', $impl->getStandard(), "Check '{$checkId}' must report BSI standard");
            self::assertSame($checkId, $impl->getCheckId());
        }
        foreach ($expectedBcm as $checkId) {
            $impl = $registry->get($checkId);
            self::assertNotNull($impl, "Registry must resolve check id '{$checkId}'");
            self::assertInstanceOf(PolicyWizardCheckInterface::class, $impl);
            self::assertSame('bcm', $impl->getStandard(), "Check '{$checkId}' must report BCM standard");
            self::assertSame($checkId, $impl->getCheckId());
        }

        $bsiChecks = $registry->forStandard('bsi');
        self::assertGreaterThanOrEqual(6, count($bsiChecks), 'At least 6 BSI checks must be tagged on the registry');

        $bcmChecks = $registry->forStandard('bcm');
        self::assertGreaterThanOrEqual(7, count($bcmChecks), 'At least 7 BCM checks must be tagged on the registry');
    }
}
