<?php

declare(strict_types=1);

namespace App\Tests\Service\Compliance\Fulfillment;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;
use App\Service\Compliance\Fulfillment\RecordCheckRule;
use App\Service\ComplianceWizard\WizardCheckSource;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class RecordCheckRuleTest extends TestCase
{
    private Tenant $tenant;

    /** @var array<string, array{score: float, details: array<string, mixed>}> check name → result */
    private array $results = [];

    /** @var int number of data checks run */
    private int $runs = 0;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        (new ReflectionProperty($this->tenant, 'id'))->setValue($this->tenant, 7);
    }

    #[Test]
    public function aClauseFollowsTheRecordsOfItsCheck(): void
    {
        $this->results['training'] = ['score' => 80.0, 'details' => ['total' => 5]];

        $derivation = $this->rule()->derive($this->requirement('ISO27001', 'ISO27001-7.2'), $this->tenant);

        self::assertNotNull($derivation);
        self::assertSame(80, $derivation->percentage);
        self::assertSame(FulfillmentRuleEngine::KIND_RECORDS, $derivation->kind);
        self::assertSame('7.2', $derivation->basis[0]['ref']);
        self::assertSame('wizard.check.training', $derivation->basis[0]['label']);
        self::assertSame('app_training_index', $derivation->basis[0]['route']);
    }

    #[Test]
    public function aParentClauseCoversItsSubClausesAndSeveralChecksAverage(): void
    {
        $this->results['audit'] = ['score' => 100.0, 'details' => []];
        $this->results['audit_programme'] = ['score' => 50.0, 'details' => []];

        $derivation = $this->rule()->derive($this->requirement('ISO27001', 'ISO27001-9.2.1'), $this->tenant);

        self::assertSame(75, $derivation?->percentage);
        self::assertCount(2, $derivation->basis);
    }

    #[Test]
    public function articleReferencesMatchTheCatalogueNumbering(): void
    {
        $this->results['supplier'] = ['score' => 60.0, 'details' => []];

        self::assertSame(60, $this->rule()->derive($this->requirement('DORA', 'DORA-28.2'), $this->tenant)?->percentage);
        self::assertSame('21.2.a', RecordCheckRule::normaliseReference('21(2)(a)'));
        self::assertSame('6.1.3.d', RecordCheckRule::normaliseReference('6.1.3 d)'));
        self::assertSame('21.2.a', RecordCheckRule::requirementNumber('NIS2', 'NIS2-21.2.a'));
    }

    #[Test]
    public function manualChecksInactiveModulesAndEmptyRecordsGiveNoRule(): void
    {
        $this->results['bcm'] = ['score' => 100.0, 'details' => []];
        $this->results['objectives'] = ['score' => 0.0, 'details' => ['type' => 'entity_presence', 'count' => 0]];
        $this->results['nodata'] = ['score' => 0.0, 'details' => ['reason' => 'no_data']];

        $rule = $this->rule(activeModules: ['training']);

        self::assertNull($rule->derive($this->requirement('ISO27001', 'ISO27001-4.4'), $this->tenant), 'manual');
        self::assertNull($rule->derive($this->requirement('ISO27001', 'ISO27001-8.2'), $this->tenant), 'module bcm inactive');
        self::assertNull($rule->derive($this->requirement('ISO27001', 'ISO27001-6.2'), $this->tenant), 'no objective recorded');
        self::assertNull($rule->derive($this->requirement('ISO27001', 'ISO27001-6.3'), $this->tenant), 'no data');
        self::assertTrue(RecordCheckRule::isEmpty(['total' => 0, 'implemented' => 0, 'excluded' => 0]), 'no control at all');
        self::assertFalse(RecordCheckRule::isEmpty(['total' => 0, 'excluded' => 3]), 'all excluded is a decision');
        self::assertFalse(RecordCheckRule::isEmpty(['total' => 4, 'completed' => 0]), 'records with gaps count');
    }

    #[Test]
    public function singleLevelAndThemeReferencesStayExact(): void
    {
        $this->results['support'] = ['score' => 100.0, 'details' => []];
        $this->results['annex'] = ['score' => 100.0, 'details' => []];

        $rule = $this->rule();

        self::assertNull($rule->derive($this->requirement('ISO27001', 'ISO27001-7.5'), $this->tenant), '"7" does not cover 7.5');
        self::assertNull($rule->derive($this->requirement('ISO27001', 'A.5.1'), $this->tenant), 'Annex-A themes are no requirement');
    }

    #[Test]
    public function eachCheckRunsOncePerOrganisation(): void
    {
        $this->results['audit'] = ['score' => 100.0, 'details' => []];
        $this->results['audit_programme'] = ['score' => 100.0, 'details' => []];
        $rule = $this->rule();

        $rule->derive($this->requirement('ISO27001', 'ISO27001-9.2.1'), $this->tenant);
        $rule->derive($this->requirement('ISO27001', 'ISO27001-9.2.2'), $this->tenant);
        self::assertSame(2, $this->runs);

        $rule->forgetTenant($this->tenant);
        $rule->derive($this->requirement('ISO27001', 'ISO27001-9.2.1'), $this->tenant);
        self::assertSame(4, $this->runs);
    }

    /**
     * @param list<string> $activeModules
     */
    private function rule(array $activeModules = ['training', 'bcm', 'audits', 'objectives']): RecordCheckRule
    {
        $wizard = new class ($this) implements WizardCheckSource {
            public function __construct(private readonly RecordCheckRuleTest $test)
            {
            }

            public function getWizardDefinitions(): array
            {
                return [
                    'iso27001' => ['code' => 'ISO27001', 'categories' => [
                        'support' => ['checks' => [
                            'training' => ['name' => 'wizard.check.training', 'type' => 'training_coverage', 'module' => 'training', 'clause' => '7.2', 'route' => 'app_training_index'],
                            'support' => ['name' => 'support', 'type' => 'entity_presence', 'clause' => '7'],
                            'isms' => ['name' => 'isms', 'type' => 'manual', 'clause' => '4.4'],
                        ]],
                        'operation' => ['checks' => [
                            'bcm' => ['name' => 'bcm', 'type' => 'bcm_coverage', 'module' => 'bcm', 'clause' => '8.2'],
                            'objectives' => ['name' => 'objectives', 'type' => 'entity_presence', 'clause' => '6.2'],
                            'nodata' => ['name' => 'nodata', 'type' => 'risk_coverage', 'clause' => '6.3'],
                        ]],
                        'performance' => ['checks' => [
                            'audit' => ['name' => 'audit', 'type' => 'audit_status', 'clause' => '9.2'],
                            'audit_programme' => ['name' => 'audit_programme', 'type' => 'audit_status', 'clause' => '9.2.1/9.2.2'],
                            'annex' => ['name' => 'annex', 'type' => 'control_coverage', 'clause' => 'A.5/A.6'],
                        ]],
                    ]],
                    'dora' => ['code' => 'DORA', 'categories' => [
                        'third_party' => ['checks' => [
                            'supplier' => ['name' => 'supplier', 'type' => 'supplier_assessment', 'article' => '28(2)'],
                            'range' => ['name' => 'range', 'type' => 'supplier_assessment', 'article' => '28-30'],
                        ]],
                    ]],
                ];
            }

            public function runDataCheck(array $check, Tenant $tenant): ?array
            {
                return $this->test->checkResult((string) $check['name']);
            }
        };

        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('getActiveModulesForTenant')->willReturn($activeModules);

        return new RecordCheckRule(static fn (): WizardCheckSource => $wizard, static fn (): ModuleConfigurationService => $modules);
    }

    /**
     * @return array{score: float, details: array<string, mixed>}|null
     * @internal called by the fake wizard
     */
    public function checkResult(string $name): ?array
    {
        $this->runs++;
        $key = str_replace('wizard.check.', '', $name);

        return $this->results[$key] ?? ['score' => 0.0, 'details' => ['reason' => 'no_data']];
    }

    private function requirement(string $framework, string $id): ComplianceRequirement
    {
        return (new ComplianceRequirement())
            ->setFramework((new ComplianceFramework())->setCode($framework)->setName($framework))
            ->setRequirementId($id);
    }
}
