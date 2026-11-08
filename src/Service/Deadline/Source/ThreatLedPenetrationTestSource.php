<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Tenant;
use App\Entity\ThreatLedPenetrationTest;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Responsibility\Responsibility;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * TLPT (DORA Art. 26): a running test is due at its planned end; once the
 * newest test is closed, the next one is due three years after it ended.
 * Organisations that never recorded a TLPT are not reminded here — whether
 * TLPT applies at all is the compliance wizard's DORA check.
 */
final class ThreatLedPenetrationTestSource extends EntityDeadlineSource
{
    public const int CADENCE_MONTHS = 36;

    protected function entityClass(): string
    {
        return ThreatLedPenetrationTest::class;
    }

    public function slug(): string
    {
        return 'tlpt';
    }

    public function label(): string
    {
        return 'TLPT';
    }

    public function requiredModule(): string
    {
        return 'nis2_dora';
    }

    /** Running tests, or the newest closed one when none is running. */
    public function findOpen(Tenant $tenant): iterable
    {
        $tests = $this->entityManager->getRepository(ThreatLedPenetrationTest::class)->findBy(['tenant' => $tenant]);
        $running = array_values(array_filter($tests, static fn (ThreatLedPenetrationTest $t): bool => !in_array(
            $t->getStatus(),
            [ThreatLedPenetrationTest::STATUS_CLOSED, ThreatLedPenetrationTest::STATUS_CANCELLED],
            true,
        )));
        if ($running !== []) {
            return array_values(array_filter($running, fn (ThreatLedPenetrationTest $t): bool => $this->dueDateOf($t) !== null));
        }
        $closed = array_filter($tests, static fn (ThreatLedPenetrationTest $t): bool => $t->getStatus() === ThreatLedPenetrationTest::STATUS_CLOSED);
        usort($closed, fn (ThreatLedPenetrationTest $a, ThreatLedPenetrationTest $b): int => $this->endOf($b) <=> $this->endOf($a));

        return array_slice($closed, 0, 1);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ThreatLedPenetrationTest);
        if ($item->getStatus() !== ThreatLedPenetrationTest::STATUS_CLOSED) {
            return $item->getPlannedEndDate();
        }

        return DateTimeImmutable::createFromInterface($this->endOf($item))->modify(sprintf('+%d months', self::CADENCE_MONTHS));
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ThreatLedPenetrationTest);

        return (string) $item->getTitle();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ThreatLedPenetrationTest);

        return $item->getStatus() === ThreatLedPenetrationTest::STATUS_CANCELLED;
    }

    public function responsibleOf(object $item): Responsibility
    {
        return Responsibility::roles('ROLE_CISO', 'ROLE_ISB');
    }

    /** TLPT records have no page of their own yet. */
    public function linkOf(object $item): null
    {
        return null;
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'tlpt_due', leadDays: 90, normReference: 'DORA Art. 26');
    }

    private function endOf(ThreatLedPenetrationTest $test): DateTimeInterface
    {
        return $test->getActualEndDate() ?? $test->getPlannedEndDate() ?? $test->getCreatedAt();
    }
}
