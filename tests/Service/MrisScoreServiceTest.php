<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Service\AiAgentInventoryService;
use App\Service\MrisScoreService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MrisScoreServiceTest extends TestCase
{
    private function makeService(
        array $controls = [],
        ?ComplianceFramework $framework = null,
        array $requirements = [],
        array $aiStats = ['total' => 0, 'avg_completeness' => 0.0],
    ): MrisScoreService {
        $controlRepo = $this->createMock(ControlRepository::class);
        $controlRepo->method('findByTenant')->willReturn($controls);

        $fwRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $fwRepo->method('findOneBy')->willReturn($framework);

        $reqRepo = $this->createMock(ComplianceRequirementRepository::class);
        $reqRepo->method('findBy')->willReturn($requirements);

        $assetRepo = $this->createMock(AssetRepository::class);

        // AiAgentInventoryService is final → use anonymous override.
        $aiInventory = new class($assetRepo, $aiStats) extends AiAgentInventoryService {
            public function __construct(AssetRepository $repo, private array $stub)
            {
                parent::__construct($repo);
            }
            public function inventoryStats(Tenant $tenant): array
            {
                return $this->stub + ['total' => 0, 'by_classification' => [], 'unclassified' => 0, 'avg_completeness' => 0.0];
            }
        };

        return new MrisScoreService($controlRepo, $fwRepo, $reqRepo, $assetRepo, $aiInventory);
    }

    private function makeControl(string $resilience): Control
    {
        $c = new Control();
        $c->setMythosResilience($resilience);
        return $c;
    }

    public function testScoreReturnsZeroForEmptyTenant(): void
    {
        $score = $this->makeService()->compute(new Tenant());
        self::assertSame(0.0, $score['score']);
        self::assertCount(5, $score['breakdown']);
    }

    public function testDisclaimerIsAlwaysPresentAndMentionsAuditLimit(): void
    {
        $score = $this->makeService()->compute(new Tenant());
        self::assertNotEmpty($score['disclaimer']);
        self::assertStringContainsString('MRIS v1.5', $score['disclaimer']);
        self::assertStringContainsString('Audit', $score['disclaimer']);
    }

    public function testWeightsSumToHundred(): void
    {
        $score = $this->makeService()->compute(new Tenant());
        $totalWeight = array_sum(array_column($score['breakdown'], 'weight'));
        self::assertSame(100, $totalWeight);
    }

    public function testStandfestShareIs100PercentWhenAllControlsAreStandfest(): void
    {
        $controls = [
            $this->makeControl('standfest'),
            $this->makeControl('standfest'),
            $this->makeControl('standfest'),
        ];
        $score = $this->makeService($controls)->compute(new Tenant());
        self::assertSame(100.0, $score['breakdown']['standfest']['value']);
    }

    public function testReibungInverseIs100PercentWhenNoReibungExists(): void
    {
        $controls = [
            $this->makeControl('standfest'),
            $this->makeControl('degradiert'),
        ];
        $score = $this->makeService($controls)->compute(new Tenant());
        self::assertSame(100.0, $score['breakdown']['reibung_inverse']['value']);
    }

    public function testReibungInversePenalizesReibungControls(): void
    {
        $controls = [
            $this->makeControl('standfest'),
            $this->makeControl('reibung'),
            $this->makeControl('reibung'),
            $this->makeControl('standfest'),
        ];
        $score = $this->makeService($controls)->compute(new Tenant());
        // 50 % reibung → inverse = 50 %
        self::assertSame(50.0, $score['breakdown']['reibung_inverse']['value']);
    }

    public function testMaturityIsAverageOfAllRequirementsCurrent(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('MRIS-v1.5');

        $r1 = new ComplianceRequirement();
        $r1->setMaturityCurrent('initial');  // 33
        $r2 = new ComplianceRequirement();
        $r2->setMaturityCurrent('managed');  // 100
        $r3 = new ComplianceRequirement();
        // r3 ohne maturity_current — wird ignoriert

        $score = $this->makeService([], $framework, [$r1, $r2, $r3])->compute(new Tenant());
        // (33 + 100) / 2 = 66.5
        self::assertSame(66.5, $score['breakdown']['maturity']['value']);
    }

    public function testManualKpisFillRateBasedOnTenantSettings(): void
    {
        $tenant = new Tenant();
        $tenant->setSettings([
            'mris' => [
                'manual_kpis' => [
                    'sbom_coverage' => 67.5,
                    'kev_patch_latency' => 14,
                    'tlpt_findings_closure' => 92,
                ],
            ],
        ]);
        $score = $this->makeService()->compute($tenant);
        // 3 von 5 → 60 %
        self::assertSame(60.0, $score['breakdown']['manual_kpis']['value']);
    }

    public function testAiCompletenessIsTakenFromInventoryStats(): void
    {
        $score = $this->makeService([], null, [], ['total' => 5, 'avg_completeness' => 78.5])->compute(new Tenant());
        self::assertSame(78.5, $score['breakdown']['ai_agent_doku']['value']);
    }

    public function testAggregateScoreCombinesAllDimensions(): void
    {
        // Setup: 100 % standfest, no reibung, all maturity managed (100), 100 % manual filled, 100 % AI doku
        $controls = [$this->makeControl('standfest'), $this->makeControl('standfest')];

        $framework = new ComplianceFramework();
        $framework->setCode('MRIS-v1.5');
        $r = new ComplianceRequirement();
        $r->setMaturityCurrent('managed');

        $tenant = new Tenant();
        $tenant->setSettings([
            'mris' => [
                'manual_kpis' => [
                    'sbom_coverage' => 100,
                    'kev_patch_latency' => 100,
                    'ccm_coverage' => 100,
                    'crypto_inventory_coverage' => 100,
                    'tlpt_findings_closure' => 100,
                ],
            ],
        ]);

        $score = $this->makeService(
            $controls,
            $framework,
            [$r],
            ['total' => 1, 'avg_completeness' => 100.0],
        )->compute($tenant);

        // Alle 5 Dimensionen = 100 → score = 100
        self::assertSame(100.0, $score['score']);
    }
}
