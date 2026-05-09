<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckRegistry;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardTopicCatalogue;
use App\Service\ComplianceWizardService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * W3-K integration test: verifies that {@see ComplianceWizardService} routes
 * the new `policy_wizard` check-type through the
 * {@see PolicyWizardCheckRegistry} and exposes the 29 Policy-Wizard rows
 * inside the ISO 27001 category map.
 *
 * Strategy:
 * - Boot the kernel to obtain a real, fully-wired {@see ComplianceWizardService}.
 * - For tests that exercise dispatch logic, swap the registry on a freshly
 *   constructed service using the kernel-resolved dependencies and a stub
 *   registry — keeps the test independent of real Document fixtures.
 * - For category-map assertions, reflect into `getIso27001Categories()`
 *   (private) since the surface is read-only and stable.
 */
final class ComplianceWizardServicePolicyWizardIntegrationTest extends KernelTestCase
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

    /**
     * Build a ComplianceWizardService using the kernel's wiring but with the
     * Policy-Wizard registry replaced by the supplied stub.
     */
    private function buildServiceWithRegistry(PolicyWizardCheckRegistry $registry): ComplianceWizardService
    {
        self::bootKernel();
        $container = static::getContainer();
        $real = $container->get(ComplianceWizardService::class);

        // Reuse all real collaborators; only swap the registry. Reflect to
        // pull out the exact constructor argument set without re-resolving.
        $ref = new \ReflectionClass($real);
        $args = [];
        foreach ($ref->getConstructor()->getParameters() as $param) {
            $name = $param->getName();
            $prop = $ref->getProperty($name);
            $args[$name] = $prop->getValue($real);
        }
        $args['policyWizardCheckRegistry'] = $registry;

        return $ref->newInstanceArgs($args);
    }

    #[Test]
    public function testRunCheckDispatchesPolicyWizardType(): void
    {
        $this->requireDatabase();

        $stubCheck = new class implements PolicyWizardCheckInterface {
            public function getCheckId(): string { return 'policy_top_level_present'; }
            public function getStandard(): string { return 'iso27001'; }
            public function run(?Tenant $tenant): PolicyWizardCheckResult
            {
                return new PolicyWizardCheckResult(
                    checkId: 'policy_top_level_present',
                    score: 100.0,
                    passed: true,
                    details: ['published_documents' => 3],
                );
            }
        };

        $service = $this->buildServiceWithRegistry(new PolicyWizardCheckRegistry([$stubCheck]));

        $check = [
            'name' => 'compliance_check.policy_top_level_present.title',
            'type' => 'policy_wizard',
            'check_id' => 'policy_top_level_present',
            'priority' => 'critical',
        ];

        $result = (new \ReflectionMethod($service, 'runCheck'))
            ->invoke($service, $check, null);

        self::assertIsArray($result);
        self::assertSame(100.0, (float) $result['score']);
        self::assertSame('compliant', $result['status']);
        self::assertSame(['published_documents' => 3], $result['details']);
        self::assertNull($result['gap']);
    }

    #[Test]
    public function testRunCheckUsesRegistry(): void
    {
        $this->requireDatabase();

        $invocations = 0;
        $passedTenant = false;
        $stubCheck = new class($invocations, $passedTenant) implements PolicyWizardCheckInterface {
            public function __construct(private int &$invocations, private bool &$passedTenant) {}
            public function getCheckId(): string { return 'policy_acknowledgement_coverage'; }
            public function getStandard(): string { return 'iso27001'; }
            public function run(?Tenant $tenant): PolicyWizardCheckResult
            {
                $this->invocations++;
                $this->passedTenant = ($tenant === null);
                return new PolicyWizardCheckResult(
                    checkId: 'policy_acknowledgement_coverage',
                    score: 70.0,
                    passed: false,
                    details: ['coverage_percent' => 70],
                    gap: [
                        'title' => 'compliance_check.policy_acknowledgement_coverage.fail_message',
                        'priority' => 'high',
                    ],
                );
            }
        };

        $service = $this->buildServiceWithRegistry(new PolicyWizardCheckRegistry([$stubCheck]));

        $check = [
            'name' => 'compliance_check.policy_acknowledgement_coverage.title',
            'type' => 'policy_wizard',
            'check_id' => 'policy_acknowledgement_coverage',
            'priority' => 'high',
        ];

        $result = (new \ReflectionMethod($service, 'dispatchPolicyWizardCheck'))
            ->invoke($service, $check, null);

        self::assertSame(1, $invocations, 'Registry must be invoked exactly once for the matching check_id');
        self::assertTrue($passedTenant, 'Service must forward the tenant argument (null in this case) into the check');
        self::assertSame(70.0, (float) $result['score']);
        self::assertSame(['coverage_percent' => 70], $result['details']);
        self::assertNotNull($result['gap']);
        self::assertSame('high', $result['gap']['priority']);
    }

    #[Test]
    public function testRunCheckAdaptsResultShape(): void
    {
        $this->requireDatabase();

        $stubCheck = new class implements PolicyWizardCheckInterface {
            public function getCheckId(): string { return 'policy_review_cadence'; }
            public function getStandard(): string { return 'iso27001'; }
            public function run(?Tenant $tenant): PolicyWizardCheckResult
            {
                return new PolicyWizardCheckResult(
                    checkId: 'policy_review_cadence',
                    score: 50.0,
                    passed: false,
                    details: ['overdue_policies' => 2],
                    gap: [
                        'title' => 'compliance_check.policy_review_cadence.fail_message',
                        'priority' => 'high',
                        'route' => 'app_policy_wizard_index',
                    ],
                );
            }
        };

        $service = $this->buildServiceWithRegistry(new PolicyWizardCheckRegistry([$stubCheck]));

        $check = [
            'name' => 'compliance_check.policy_review_cadence.title',
            'description' => 'compliance_check.policy_review_cadence.description',
            'type' => 'policy_wizard',
            'check_id' => 'policy_review_cadence',
            'priority' => 'high',
            'route' => 'app_policy_wizard_index',
        ];

        $result = (new \ReflectionMethod($service, 'runCheck'))
            ->invoke($service, $check, null);

        // Verify the shape matches what other check-types (e.g. consent_coverage)
        // emit so the wizard UI doesn't need a special branch for Policy-Wizard.
        $expectedKeys = ['name', 'description', 'score', 'status', 'details', 'gap', 'route', 'module'];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $result, "runCheck() result missing '$key'");
        }

        self::assertSame(50.0, (float) $result['score']);
        self::assertSame(['overdue_policies' => 2], $result['details']);
        self::assertSame('app_policy_wizard_index', $result['route']);
        self::assertSame('in_progress', $result['status']);
        self::assertSame('high', $result['gap']['priority']);
    }

    #[Test]
    public function testIso27001CategoryListsAllPolicyWizardChecks(): void
    {
        $this->requireDatabase();

        self::bootKernel();
        $service = static::getContainer()->get(ComplianceWizardService::class);

        $categories = (new \ReflectionMethod($service, 'getIso27001Categories'))
            ->invoke($service);

        self::assertArrayHasKey('policies_top_level', $categories);
        self::assertArrayHasKey('policies_topic_coverage', $categories);

        // Top-level: exactly 1 row (policy_top_level_present).
        $topLevel = $categories['policies_top_level']['checks'];
        self::assertArrayHasKey('policy_top_level_present', $topLevel);
        self::assertSame('policy_wizard', $topLevel['policy_top_level_present']['type']);
        self::assertSame('policy_top_level_present', $topLevel['policy_top_level_present']['check_id']);

        // Topic coverage: 24 topic rows + 4 cross-cutting = 28 rows.
        $topicChecks = $categories['policies_topic_coverage']['checks'];
        self::assertCount(
            count(PolicyWizardTopicCatalogue::ISO27001_TOPICS) + 4,
            $topicChecks,
            'Topic-coverage category must list all 24 ISO 27002 topics plus 4 cross-cutting checks',
        );

        // Every ISO 27002 topic from the catalogue must be represented.
        foreach (PolicyWizardTopicCatalogue::ISO27001_TOPICS as $topic) {
            $expectedId = sprintf('policy_topic_%s_present', $topic);
            self::assertArrayHasKey($expectedId, $topicChecks, "Missing topic check '$expectedId'");
            self::assertSame('policy_wizard', $topicChecks[$expectedId]['type']);
            self::assertSame($expectedId, $topicChecks[$expectedId]['check_id']);
        }

        // 4 cross-cutting Policy-Wizard checks.
        foreach ([
            'policy_approval_chain_completed',
            'policy_acknowledgement_coverage',
            'policy_review_cadence',
            'policy_tailoring_fields',
        ] as $crossId) {
            self::assertArrayHasKey($crossId, $topicChecks, "Missing cross-cutting check '$crossId'");
            self::assertSame('policy_wizard', $topicChecks[$crossId]['type']);
            self::assertSame($crossId, $topicChecks[$crossId]['check_id']);
        }

        // Sanity: 1 + 24 + 4 = 29 total Policy-Wizard rows in ISO 27001.
        $totalPolicyRows = count($topLevel) + count($topicChecks);
        self::assertSame(29, $totalPolicyRows, 'Expected 29 Policy-Wizard rows (1 top-level + 24 topics + 4 cross-cutting)');
    }

    #[Test]
    public function testInvalidCheckIdReturnsFailGracefully(): void
    {
        $this->requireDatabase();

        // Empty registry — every lookup returns null.
        $service = $this->buildServiceWithRegistry(new PolicyWizardCheckRegistry([]));

        // Case 1: unknown check_id.
        $check = [
            'name' => 'Unknown Policy-Wizard check',
            'type' => 'policy_wizard',
            'check_id' => 'policy_does_not_exist',
            'priority' => 'high',
        ];
        $result = (new \ReflectionMethod($service, 'runCheck'))
            ->invoke($service, $check, null);

        self::assertSame(0, (int) $result['score']);
        self::assertNotNull($result['gap']);
        self::assertSame('unknown_check_id', $result['details']['error']);
        self::assertSame('policy_does_not_exist', $result['details']['check_id']);

        // Case 2: missing check_id key altogether.
        $checkMissing = [
            'name' => 'Policy-Wizard misconfigured',
            'type' => 'policy_wizard',
            'priority' => 'high',
        ];
        $resultMissing = (new \ReflectionMethod($service, 'runCheck'))
            ->invoke($service, $checkMissing, null);

        self::assertSame(0, (int) $resultMissing['score']);
        self::assertNotNull($resultMissing['gap']);
        self::assertSame('missing_check_id', $resultMissing['details']['error']);
    }
}
