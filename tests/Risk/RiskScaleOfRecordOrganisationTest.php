<?php

declare(strict_types=1);

namespace App\Tests\Risk;

use App\Entity\{Risk, Tenant};
use App\Repository\RiskRepository;
use App\Risk\RiskMatrixThresholds;
use App\Service\Notification\Event\DomainEventDetector;
use App\Service\RiskMatrixService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Outside a request (Doctrine listener in a worker, cron, async job) and in
 * holding views there is no tenant in scope, or the viewer's is the wrong
 * one: every classification must use the scale of the risk's own
 * organisation (ISO 27001 6.1.2 b, Risikokriterien).
 *
 * The provider below knows a strict scale (critical from 16) for one
 * organisation and nothing for "the tenant in scope" (null → defaults 20/12/6),
 * exactly like a console command or worker.
 */
final class RiskScaleOfRecordOrganisationTest extends TestCase
{
    private Tenant $strict;
    private Tenant $lenient;

    protected function setUp(): void
    {
        $this->strict = new Tenant();
        $this->lenient = new Tenant();
        $strict = $this->strict;
        $lenient = $this->lenient;
        RiskMatrixThresholds::useProvider(static fn (?Tenant $t): ?array => match ($t) {
            $strict => ['critical' => 16, 'high' => 9, 'medium' => 4],
            $lenient => ['critical' => 25, 'high' => 16, 'medium' => 9],
            default => null, // no tenant in scope: defaults
        });
    }

    protected function tearDown(): void
    {
        RiskMatrixThresholds::useProvider(null);
    }

    private function risk(Tenant $tenant, int $probability, int $impact): Risk
    {
        return (new Risk())->setTenant($tenant)->setTitle('R')->setProbability($probability)->setImpact($impact);
    }

    /** @return string[] */
    private function types(array $events): array
    {
        return array_map(static fn ($e): string => $e->eventType, $events);
    }

    #[Test]
    public function criticalEventFollowsTheRisksOwnScaleWithoutTenantContext(): void
    {
        $detector = new DomainEventDetector();

        // 4 × 4 = 16: critical for the strict organisation, below the default edge 20.
        $inserted = fn (Tenant $t, int $p, int $i): array => $this->types($detector->forInsert($this->risk($t, $p, $i)));
        self::assertContains('risk.score_critical', $inserted($this->strict, 4, 4));
        self::assertNotContains('risk.score_critical', $inserted($this->lenient, 4, 4));
        // 5 × 4 = 20 is critical by default, but not in the lenient organisation's scale.
        self::assertNotContains('risk.score_critical', $inserted($this->lenient, 5, 4));
    }

    #[Test]
    public function crossingIntoCriticalIsJudgedOnTheRisksOwnScale(): void
    {
        $detector = new DomainEventDetector();
        // 3 × 4 = 12 → 4 × 4 = 16: crosses the strict organisation's edge 16.
        $events = $detector->forUpdate($this->risk($this->strict, 4, 4), ['probability' => [3, 4]]);

        self::assertSame(['risk.score_critical'], $this->types($events));
    }

    #[Test]
    public function matrixStatisticsCountEachRiskInItsOwnOrganisationsScale(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $service = new RiskMatrixService($this->createStub(RiskRepository::class), $translator);

        // Holding view: same score 16, two organisations, two different bands.
        $risks = [$this->risk($this->strict, 4, 4), $this->risk($this->lenient, 4, 4)];

        $byLevel = $service->getRisksByLevel($risks);
        self::assertSame([$risks[0]], $byLevel['critical']);
        self::assertSame([$risks[1]], $byLevel['high']);

        $matrix = $service->generateMatrix($risks, $this->strict);
        self::assertSame(1, $matrix['statistics']['critical']);
        self::assertSame(1, $matrix['statistics']['high']);
        // Cell colours: the scale of the organisation whose matrix is shown.
        self::assertSame('critical', $matrix['riskLevels'][4][4]);
        self::assertSame('high', $service->generateMatrix($risks, $this->lenient)['riskLevels'][4][4]);
        // No organisation given: the defaults, never another organisation's scale.
        self::assertSame('high', $service->calculateRiskLevel(4, 4, null));
    }
}
