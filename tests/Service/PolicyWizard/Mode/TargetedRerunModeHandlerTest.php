<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Mode;

use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\WizardRunRepository;
use App\Service\PolicyWizard\Mode\TargetedRerunModeHandler;
use App\Service\PolicyWizard\WizardStepKeys;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\SettingProviderInterface;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use App\Exception\InvalidArgument\InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W2-C — Targeted-re-run handler unit tests.
 *
 * The targeted handler is a thin wrapper over the existing step
 * machinery: it jumps the run pointer to the PICK step on start,
 * caps the topic list at 10, surfaces the diff vs. the latest
 * approved baseline, and mirrors the finding-reference onto the
 * run.
 */
#[AllowMockObjectsWithoutExpectations]
final class TargetedRerunModeHandlerTest extends TestCase
{
    private function makeTenantStub(int $id, array $ancestors = []): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getAllAncestors')->willReturn($ancestors);
        return $stub;
    }

    /**
     * @param array<string, OverrideMode>           $modes
     * @param array<int, array<string, mixed>>      $stored
     * @param array<string, mixed>                  $defaults
     */
    private function makeResolver(array $modes, array $stored, array $defaults = []): TenantSettingResolver
    {
        $provider = new class ($modes, $stored, $defaults) implements SettingProviderInterface {
            /**
             * @param array<string, OverrideMode>           $modes
             * @param array<int, array<string, mixed>>      $stored
             * @param array<string, mixed>                  $defaults
             */
            public function __construct(
                private readonly array $modes,
                private readonly array $stored,
                private readonly array $defaults = [],
            ) {
            }

            public function getOverrideMode(string $key): OverrideMode
            {
                return $this->modes[$key] ?? OverrideMode::Free;
            }

            public function getStoredValue(Tenant $tenant, string $key): mixed
            {
                $tid = $tenant->getId();
                if ($tid === null) {
                    return null;
                }
                return $this->stored[$tid][$key] ?? null;
            }

            public function getGlobalDefault(string $key, mixed $default): mixed
            {
                return $this->defaults[$key] ?? $default;
            }
        };
        return new TenantSettingResolver($provider);
    }

    private function makeHandler(
        ?WizardRunRepository $repo = null,
        ?TenantSettingResolver $resolver = null,
    ): TargetedRerunModeHandler {
        $repo ??= $this->createStub(WizardRunRepository::class);
        $resolver ??= $this->makeResolver([], []);
        return new TargetedRerunModeHandler($repo, $resolver);
    }

    private function makeRun(?Tenant $tenant = null, string $step = WizardStepKeys::STEP_WELCOME): WizardRun
    {
        $run = new WizardRun();
        $run->setMode(WizardStepKeys::MODE_TARGETED);
        $run->setStep($step);
        $run->setStatus(WizardStepKeys::STATUS_IN_PROGRESS);
        if ($tenant !== null) {
            $run->setTenant($tenant);
        }
        return $run;
    }

    #[Test]
    public function testTargetedRunSkipsToPickStep(): void
    {
        $handler = $this->makeHandler();
        $run = $this->makeRun();

        // Orchestrator's start() lands on STEP_WELCOME by default.
        self::assertSame(WizardStepKeys::STEP_WELCOME, $run->getStep());

        $handler->onStart($run);

        self::assertSame(
            WizardStepKeys::STEP_TARGETED_PICK,
            $run->getStep(),
            'Targeted handler must jump the run pointer past welcome to PICK.',
        );
        self::assertSame(WizardStepKeys::MODE_TARGETED, $run->getMode());
        self::assertIsArray($run->getInputs());
    }

    #[Test]
    public function testTargetedRunMaxTenTopics(): void
    {
        $handler = $this->makeHandler();

        // Direct-API guard via assertTopicsWithinCap().
        $tenTopics = array_map(static fn (int $i): string => 'topic_' . $i, range(1, 10));
        $handler->assertTopicsWithinCap($tenTopics); // 10 = OK, no throw

        $elevenTopics = array_map(static fn (int $i): string => 'topic_' . $i, range(1, 11));
        $this->expectException(InvalidArgumentException::class);
        $handler->assertTopicsWithinCap($elevenTopics);
    }

    #[Test]
    public function testTargetedRunMaxTenTopicsOnAfterStepClampsExcess(): void
    {
        $handler = $this->makeHandler();
        $tenant = $this->makeTenantStub(1);
        $run = $this->makeRun($tenant);

        // Bypass the step's validate() and stuff 12 topics directly
        // — defence-in-depth: the handler clamps on persist.
        $excess = array_map(static fn (int $i): string => 'topic_' . $i, range(1, 12));
        $run->setTargetedTopics($excess);

        $handler->onAfterStep($run, WizardStepKeys::STEP_TARGETED_PICK);

        self::assertCount(TargetedRerunModeHandler::MAX_TOPICS, $run->getTargetedTopics() ?? []);
        self::assertSame(10, TargetedRerunModeHandler::MAX_TOPICS);
    }

    #[Test]
    public function testTargetedRunDiffComputation(): void
    {
        $tenant = $this->makeTenantStub(1);

        // Approved baseline: previous completed run had risk_appetite_tier=3
        // and backup_rpo_hours=24.
        $previousCompleted = new WizardRun();
        $previousCompleted->setTenant($tenant);
        $previousCompleted->setStatus(WizardStepKeys::STATUS_COMPLETED);
        $previousCompleted->setMode(WizardStepKeys::MODE_FULL);
        $previousCompleted->setInputs([
            WizardStepKeys::STEP_RISK_CLASSIFICATION => [
                'risk_appetite_tier' => 3,
            ],
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                'backup_rpo_hours' => 24,
            ],
        ]);

        $repo = $this->createMock(WizardRunRepository::class);
        $repo->expects(self::once())
            ->method('findBy')
            ->with(
                self::callback(static function (array $criteria) use ($tenant): bool {
                    return ($criteria['tenant'] ?? null) === $tenant
                        && ($criteria['status'] ?? null) === WizardStepKeys::STATUS_COMPLETED;
                }),
                self::anything(),
                1,
            )
            ->willReturn([$previousCompleted]);

        // Current effective values resolve to tier=2, RPO=12 (child
        // tightened both compared to the baseline).
        $resolver = $this->makeResolver(
            modes: [
                'risk.appetite_tier' => OverrideMode::CeilingOnly,
                'backup.rpo_hours' => OverrideMode::CeilingOnly,
                'policy.review_interval_months' => OverrideMode::CeilingOnly,
            ],
            stored: [
                1 => [
                    'risk.appetite_tier' => 2,
                    'backup.rpo_hours' => 12,
                ],
            ],
            // Resolver merges (chainValue, childValue) per override-mode;
            // for a standalone tenant with no ancestors the chain starts at
            // the global default. Numeric defaults so mergeCeiling(default,
            // stored) picks the lower one.
            defaults: [
                'risk.appetite_tier' => 5,
                'backup.rpo_hours' => 48,
                'policy.review_interval_months' => 36,
            ],
        );
        $handler = $this->makeHandler($repo, $resolver);

        $run = $this->makeRun($tenant, WizardStepKeys::STEP_TARGETED_DIFF);
        $run->setTargetedTopics(['risk_classification', 'operational_baselines']);

        $diff = $handler->computeDiff($run);

        // Two rows match the picked topics: one per topic that has
        // a setting key in DIFF_KEY_MAP.
        $byKey = [];
        foreach ($diff as $row) {
            $byKey[$row['key']] = $row;
        }
        self::assertArrayHasKey('risk.appetite_tier', $byKey);
        self::assertArrayHasKey('backup.rpo_hours', $byKey);

        self::assertSame(2, $byKey['risk.appetite_tier']['current_value']);
        self::assertSame(3, $byKey['risk.appetite_tier']['approved_value']);
        self::assertTrue($byKey['risk.appetite_tier']['changed']);

        self::assertSame(12, $byKey['backup.rpo_hours']['current_value']);
        self::assertSame(24, $byKey['backup.rpo_hours']['approved_value']);
        self::assertTrue($byKey['backup.rpo_hours']['changed']);
    }

    #[Test]
    public function testTargetedRunFindingReferenceStored(): void
    {
        $tenant = $this->makeTenantStub(1);
        $handler = $this->makeHandler();
        $run = $this->makeRun($tenant);

        // The orchestrator's `start()` already lifts the finding ref
        // onto WizardRun.findingReference when the caller passes it.
        // The handler must NOT clobber that on onStart() — it should
        // be preserved.
        $run->setFindingReference('NCR-2026-04');

        $handler->onStart($run);

        self::assertSame(
            'NCR-2026-04',
            $run->getFindingReference(),
            'Handler must preserve the finding reference set by the orchestrator.',
        );
        self::assertSame(WizardStepKeys::STEP_TARGETED_PICK, $run->getStep());
    }

    #[Test]
    public function testGenerateReturnsNullSoOrchestratorRunsRealGenerator(): void
    {
        $handler = $this->makeHandler();
        $run = $this->makeRun();

        // Targeted mode delegates document generation to the
        // canonical pipeline — handler returns null so the
        // orchestrator stays in charge.
        self::assertNull($handler->generate($run));
    }
}
