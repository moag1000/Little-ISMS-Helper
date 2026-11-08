<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Service\ComplianceWizard\ClauseCoverageCheck;
use App\Service\ComplianceWizard\DeadlineAdherenceCheck;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\Responsibility\Responsibility;
use App\Tests\Stub\FakeDeadlineSource;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wizard checks that replaced the last manual ISO 27001 clauses:
 * 4.4 from clauses 4–10, 8.1 and 9.1 from the deadline sources.
 */
final class DerivedClauseChecksTest extends TestCase
{
    #[Test]
    public function clauseCoverageCountsChecksAtTheMinimumScore(): void
    {
        $scores = ['a' => 80.0, 'b' => 59.0, 'c' => 100.0];
        $result = (new ClauseCoverageCheck())->check(
            ['checks' => [
                'ctx.a' => ['type' => 'x', 'id' => 'a', 'clause' => '4.1'],
                'ctx.b' => ['type' => 'x', 'id' => 'b', 'clause' => '6.2'],
                'ctx.c' => ['type' => 'x', 'id' => 'c', 'clause' => '9.2', 'module' => 'audits'],
                'ctx.d' => ['type' => 'x', 'id' => 'd', 'clause' => '7.2', 'module' => 'training'],
            ]],
            static fn (array $sub): array => ['score' => $scores[$sub['id']] ?? 0.0],
            static fn (string $module): bool => $module === 'audits',
        );

        self::assertSame(66.7, $result['score'], 'a and c of three active checks reach 60');
        self::assertSame(['6.2'], $result['details']['open_clauses']);
        self::assertNotNull($result['gap']);
    }

    #[Test]
    public function clauseCoverageSkipsChecksThatAreNoDataChecks(): void
    {
        $result = (new ClauseCoverageCheck())->check(
            ['checks' => ['x.y' => ['type' => 'manual']]],
            static fn (array $sub): ?array => null,
            static fn (string $module): bool => true,
        );

        self::assertSame('no_data', $result['details']['reason']);
    }

    #[Test]
    public function deadlineAdherenceIsTheShareOfDutiesNotOverdue(): void
    {
        $who = Responsibility::holders();
        $today = new DateTimeImmutable('2026-11-08');
        $sources = [
            new FakeDeadlineSource('isms_objective_measurement', new DeadlineProfile(bucket: 'objectives_due'), [
                FakeDeadlineSource::record(1, $who, new DateTimeImmutable('2026-10-01')),
                FakeDeadlineSource::record(2, $who, new DateTimeImmutable('2026-12-01')),
                FakeDeadlineSource::record(3, $who, new DateTimeImmutable('2026-09-01'), done: true),
            ]),
            new FakeDeadlineSource('control_effectiveness', new DeadlineProfile(bucket: 'control_effectiveness_overdue'), [
                FakeDeadlineSource::record(4, $who, new DateTimeImmutable('2027-01-01')),
                FakeDeadlineSource::record(5, $who),
            ]),
            new FakeDeadlineSource('broken', new DeadlineProfile(bucket: 'x'), broken: true),
        ];
        $check = new DeadlineAdherenceCheck(
            static fn (): DeadlineSourceRegistry => new DeadlineSourceRegistry($sources),
            $this->createStub(EntityManagerInterface::class),
        );

        $all = $check->check(['sources' => []], new Tenant(), $today);
        self::assertSame(66.7, $all['score'], 'one of three dated open duties is overdue; done and undated ones do not count');
        self::assertSame(['Isms_objective_measurement' => 1], $all['details']['overdue_by_type']);

        $effectiveness = $check->check(['sources' => ['control_effectiveness']], new Tenant(), $today);
        self::assertSame(100.0, $effectiveness['score']);
        self::assertNull($effectiveness['gap']);
    }

    #[Test]
    public function noPlannedDutyIsNoData(): void
    {
        $check = new DeadlineAdherenceCheck(
            static fn (): DeadlineSourceRegistry => new DeadlineSourceRegistry([]),
            $this->createStub(EntityManagerInterface::class),
        );

        self::assertSame('no_data', $check->check([], new Tenant())['details']['reason']);
    }
}
