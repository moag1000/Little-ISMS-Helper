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
    public function catalogueRowsNumberedApartFromTheArticleAreNamedById(): void
    {
        $this->results['early_warning'] = ['score' => 90.0, 'details' => ['total' => 2]];
        $rule = $this->rule();

        $thematic = $rule->derive($this->requirement('NIS2', 'NIS2-23.1'), $this->tenant);
        self::assertSame(90, $thematic?->percentage, 'NIS2-23.1 is the early warning of Art. 23 (4) (a)');
        self::assertCount(1, $thematic->basis, 'named by article and by id: counted once');
        self::assertSame(90, $rule->derive($this->requirement('NIS2', 'Art.23.2'), $this->tenant)?->percentage);
        self::assertNull($rule->derive($this->requirement('NIS2', 'NIS2-23.4'), $this->tenant), '23.4 (threats) is not the early warning');
        self::assertNull($rule->derive($this->requirement('NIS2', 'Art.23.4'), $this->tenant));
        self::assertSame(['23.4.a', '23.1', '#NIS2-23.1', '#Art.23.2'], RecordCheckRule::referencesOfCheck(
            ['article' => '23(4)(a)/23.1', 'requirements' => ['NIS2-23.1', 'Art.23.2']],
        ));
    }

    #[Test]
    public function articleStyleRequirementIdsCountAsTheirNumber(): void
    {
        self::assertSame('21.2.a', RecordCheckRule::requirementNumber('NIS2', 'Art.21.2.a'));
        self::assertSame('21.2.c', RecordCheckRule::requirementNumber('NIS2', 'NIS2-ART21-C'));
        self::assertSame('5', RecordCheckRule::requirementNumber('GDPR', 'Art.5'));
        self::assertTrue(RecordCheckRule::matches('21.2.b', 'NIS2', 'Art.21.2.b'));
        self::assertTrue(RecordCheckRule::matches('#NIS2-23.1', 'NIS2', 'NIS2-23.1'));
        self::assertFalse(RecordCheckRule::matches('#NIS2-23.1', 'NIS2', 'NIS2-23.10'));
        self::assertSame(['risk', 'gc.12'], RecordCheckRule::referencesOfCheck(['article' => 'RISK, GC.12']));

        $this->results['incident'] = ['score' => 60.0, 'details' => ['total' => 3]];
        $this->results['incident_controls'] = ['score' => 100.0, 'details' => ['total' => 5]];
        self::assertSame(80, $this->rule()->derive($this->requirement('NIS2', 'Art.21.2.b'), $this->tenant)?->percentage);
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
    public function aCheckWithoutReferenceTakesItsCategoryArticle(): void
    {
        $this->results['incident'] = ['score' => 60.0, 'details' => ['total' => 3]];
        $this->results['incident_controls'] = ['score' => 100.0, 'details' => ['total' => 5]];
        $requirement = $this->requirement('NIS2', 'NIS2-21.2.b');

        self::assertSame(80, $this->rule()->derive($requirement, $this->tenant)?->percentage, 'both checks of Art. 21 (2)(b)');

        $beside = $this->rule()->derive($requirement, $this->tenant, true);
        self::assertSame(60, $beside?->percentage, 'beside the controls rule only the record view counts');
        self::assertCount(1, $beside->basis);
        self::assertSame('incident_process', $beside->basis[0]['check_type']);
    }

    #[Test]
    public function anAnnexAControlReferenceCoversThatRequirement(): void
    {
        $this->results['participation'] = ['score' => 40.0, 'details' => ['total' => 10]];

        self::assertSame(40, $this->rule()->derive($this->requirement('ISO27001', 'A.6.3'), $this->tenant)?->percentage);
        self::assertNull($this->rule()->derive($this->requirement('ISO27001', 'A.6.2'), $this->tenant));
    }

    #[Test]
    public function referencesOfNamesTheRequirementsARecordTypeAnswers(): void
    {
        $refs = $this->rule()->referencesOf($this->tenant, ['training_participation', 'incident_process']);

        self::assertSame(['a.6.3'], $refs['ISO27001']);
        self::assertSame(['21.2.b'], $refs['NIS2']);
        self::assertSame([], $this->rule()->referencesOf($this->tenant, ['register_coverage']));
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
                        'annex_a_people' => ['clause' => 'A.6', 'checks' => [
                            'participation' => ['name' => 'participation', 'type' => 'training_participation', 'module' => 'training', 'clause' => 'A.6.3'],
                        ]],
                    ]],
                    'nis2' => ['code' => 'NIS2', 'categories' => [
                        'incident_handling' => ['article' => '21(2)(b)', 'checks' => [
                            'incident' => ['name' => 'incident', 'type' => 'incident_process'],
                            'incident_controls' => ['name' => 'incident_controls', 'type' => 'control_coverage'],
                        ]],
                        'reporting' => ['article' => '23', 'checks' => [
                            'early_warning' => ['name' => 'early_warning', 'type' => 'reporting_duty', 'article' => '23(4)(a)/23.1',
                                'requirements' => ['NIS2-23.1', 'Art.23.2']],
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
